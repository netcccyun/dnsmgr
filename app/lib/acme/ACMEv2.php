<?php

namespace app\lib\acme;

use Exception;

class ACMEv2
{ // Communication with Let's Encrypt via ACME v2 protocol

	protected
		$ch = null, $logger = true, $bits, $sha_bits, $directory, $resources, $jwk_header, $kid_header, $account_key, $thumbprint, $nonce = null, $proxy, $proxy_config = null;
	private $delay_until = null;

    /**
     * @param $directory string ACME directory URL
     * @param $proxy int 代理模式，0为不使用代理，1为使用系统代理，2为使用反向代理
     * @param null $proxy_config array 反向代理配置，proxy参数为2时必填
     * @throws Exception
     */
	public function __construct($directory, $proxy = 0, $proxy_config = null)
    {
		$this->directory = $directory;
		$this->proxy = $proxy;
		if ($proxy == 2) {
			$this->proxy_config = $proxy_config;
		}
	}

	public function __destruct()
	{
		if (PHP_MAJOR_VERSION < 8 && $this->account_key) openssl_pkey_free($this->account_key);
		if ($this->ch) curl_close($this->ch);
	}

	public function loadAccountKey($account_key_pem)
	{
		if (PHP_MAJOR_VERSION < 8 && $this->account_key) openssl_pkey_free($this->account_key);
		if (false === ($this->account_key = openssl_pkey_get_private($account_key_pem))) {
			throw new Exception('Could not load account key: ' . $account_key_pem . ' (' . $this->get_openssl_error() . ')');
		}

		if (false === ($details = openssl_pkey_get_details($this->account_key))) {
			throw new Exception('Could not get account key details: ' . $account_key_pem . ' (' . $this->get_openssl_error() . ')');
		}

		$this->bits = $details['bits'];
		switch ($details['type']) {
			case OPENSSL_KEYTYPE_EC:
				if (version_compare(PHP_VERSION, '7.1.0') < 0) throw new Exception('PHP >= 7.1.0 required for EC keys !');
				$this->sha_bits = ($this->bits == 521 ? 512 : $this->bits);
				$this->jwk_header = array( // JOSE Header - RFC7515
					'alg' => 'ES' . $this->sha_bits,
					'jwk' => array( // JSON Web Key
						'crv' => 'P-' . $details['bits'],
						'kty' => 'EC',
						'x' => $this->base64url(str_pad($details['ec']['x'], ceil($this->bits / 8), "\x00", STR_PAD_LEFT)),
						'y' => $this->base64url(str_pad($details['ec']['y'], ceil($this->bits / 8), "\x00", STR_PAD_LEFT))
					)
				);
				break;
			case OPENSSL_KEYTYPE_RSA:
				$this->sha_bits = 256;
				$this->jwk_header = array( // JOSE Header - RFC7515
					'alg' => 'RS256',
					'jwk' => array( // JSON Web Key
						'e' => $this->base64url($details['rsa']['e']), // public exponent
						'kty' => 'RSA',
						'n' => $this->base64url($details['rsa']['n']) // public modulus
					)
				);
				break;
			default:
				throw new Exception('Unsupported key type! Must be RSA or EC key.');
				break;
		}

		$this->kid_header = array(
			'alg' => $this->jwk_header['alg'],
			'kid' => null
		);

		$this->thumbprint = $this->base64url( // JSON Web Key (JWK) Thumbprint - RFC7638
			hash(
				'sha256',
				json_encode($this->jwk_header['jwk']),
				true
			)
		);
	}

	public function getAccountID()
	{
		if (!$this->kid_header['kid']) self::getAccount();
		return $this->kid_header['kid'];
	}

	public function setLogger($value = true)
	{
		switch (true) {
			case is_bool($value):
				break;
			case is_callable($value):
				break;
			default:
				throw new Exception('setLogger: invalid value provided');
				break;
		}
		$this->logger = $value;
	}

	public function log($txt)
	{
		switch (true) {
			case $this->logger === true:
				error_log($txt);
				break;
			case $this->logger === false:
				break;
			default:
				$fn = $this->logger;
				$fn($txt);
				break;
		}
	}

	protected function create_ACME_Exception($type, $detail, $subproblems = array())
	{
		$this->log('ACME_Exception: ' . $detail . ' (' . $type . ')');
		return new ACME_Exception($type, $detail, $subproblems);
	}

	protected function get_openssl_error()
	{
		$out = array();
		$arr = error_get_last();
		if (is_array($arr)) {
			$out[] = $arr['message'];
		}
		$out[] = openssl_error_string();
		return implode(' | ', $out);
	}

	protected function getAccount()
	{
		$this->log('Getting account info');
		$ret = $this->request('newAccount', array('onlyReturnExisting' => true));
		$this->log('Account info retrieved');
		return $ret;
	}

	protected function keyAuthorization($token)
	{
		return $token . '.' . $this->thumbprint;
	}

	protected function readDirectory()
	{
		$this->log('Initializing ACME v2 environment: ' . $this->directory);
		$ret = $this->http_request($this->directory); // Read ACME Directory
		if (
			!is_array($ret['body']) ||
			!empty(array_diff_key(
					array_flip(array('newNonce', 'newAccount', 'newOrder')),
					$ret['body']
				))
		) {
			throw new Exception('Failed to read directory: ' . $this->directory);
		}
		$this->resources = $ret['body']; // store resources for later use
		$this->log('Initialized');
	}

	protected function request($type, $payload = '', $retry = false)
	{
		if (!$this->jwk_header) {
			throw new Exception('use loadAccountKey to load an account key');
		}

		if (!$this->resources) $this->readDirectory();

		if (0 === stripos($type, 'http')) {
			$this->resources['_tmp'] = $type;
			$type = '_tmp';
		}

		try {
			$ret = $this->http_request($this->resources[$type], json_encode(
				$this->jws_encapsulate($type, $payload)
			));
		} catch (ACME_Exception $e) { // retry previous request once, if replay-nonce expired/failed
			if (!$retry && $e->getType() === 'urn:ietf:params:acme:error:badNonce') {
				$this->log('Replay-Nonce expired, retrying previous request');
				return $this->request($type, $payload, true);
			}
			if (!$retry && $e->getType() === 'urn:ietf:params:acme:error:rateLimited' && $this->delay_until !== null) {
				return $this->request($type, $payload, true);
			}
			throw $e; // rethrow all other exceptions
		}

		if (!$this->kid_header['kid'] && $type === 'newAccount') {
			// 反向替换反向代理配置，防止破坏签名
			$this->kid_header['kid'] = $this->unproxiedURL($ret['headers']['location']);
			$this->log('AccountID: ' . $this->kid_header['kid']);
		}

		return $ret;
	}

	protected function jws_encapsulate($type, $payload, $is_inner_jws = false)
	{ // RFC7515
		if ($type === 'newAccount' || $is_inner_jws) {
			$protected = $this->jwk_header;
		} else {
			$this->getAccountID();
			$protected = $this->kid_header;
		}

		if (!$is_inner_jws) {
			if (!$this->nonce) {
				$ret = $this->http_request($this->resources['newNonce'], false);
			}
			$protected['nonce'] = $this->nonce;
			$this->nonce = null;
		}

		if (!isset($this->resources[$type])) {
			throw new Exception('Resource "' . $type . '" not available.');
		}

		// 反向替换反向代理配置，防止破坏签名
		$protected['url'] = $this->unproxiedURL($this->resources[$type]);

		$protected64 = $this->base64url(json_encode($protected, JSON_UNESCAPED_SLASHES));
		$payload64 = $this->base64url(is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES));

		if (false === openssl_sign(
			$protected64 . '.' . $payload64,
			$signature,
			$this->account_key,
			'SHA' . $this->sha_bits
		)) {
			throw new Exception('Failed to sign payload !' . ' (' . $this->get_openssl_error() . ')');
		}

		return array(
			'protected' => $protected64,
			'payload' => $payload64,
			'signature' => $this->base64url($this->jwk_header['alg'][0] == 'R' ? $signature : $this->asn2signature($signature, ceil($this->bits / 8)))
		);
	}

	private function asn2signature($asn, $pad_len)
	{
		if ($asn[0] !== "\x30") throw new Exception('ASN.1 SEQUENCE not found !');
		$asn = substr($asn, $asn[1] === "\x81" ? 3 : 2);
		if ($asn[0] !== "\x02") throw new Exception('ASN.1 INTEGER 1 not found !');
		$R = ltrim(substr($asn, 2, ord($asn[1])), "\x00");
		$asn = substr($asn, ord($asn[1]) + 2);
		if ($asn[0] !== "\x02") throw new Exception('ASN.1 INTEGER 2 not found !');
		$S = ltrim(substr($asn, 2, ord($asn[1])), "\x00");
		return str_pad($R, $pad_len, "\x00", STR_PAD_LEFT) . str_pad($S, $pad_len, "\x00", STR_PAD_LEFT);
	}

	protected function base64url($data)
	{ // RFC7515 - Appendix C
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	protected function base64url_decode($data)
	{
		return base64_decode(strtr($data, '-_', '+/'));
	}

	private function json_decode($str)
	{
		$ret = json_decode($str, true);
		if ($ret === null) {
			throw new Exception('Could not parse JSON: ' . $str);
		}
		return $ret;
	}

	protected function http_request($url, $data = null)
	{
		if ($this->ch === null) {
			$this->ch = curl_init();
		}

		if ($this->delay_until !== null) {
			$delta = $this->delay_until - time();
			if ($delta > 0) {
				$this->log('Delaying ' . $delta . 's (rate limit)');
				sleep($delta);
			}
			$this->delay_until = null;
		}

		// 替换反向代理配置
		$url = $this->proxiedURL($url);

		$method = $data === false ? 'HEAD' : ($data === null ? 'GET' : 'POST');
		$user_agent = 'ACMECert v3.4.0 (+https://github.com/skoerfgen/ACMECert)';
		$header = ($data === null || $data === false) ? array() : array('Content-Type: application/jose+json');

		$headers = array();
		curl_setopt_array($this->ch, array(
			CURLOPT_URL => $url,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_NOBODY => $data === false,
			CURLOPT_USERAGENT => $user_agent,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $header,
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$headers) {
				$headers[] = $header;
				return strlen($header);
			}
		));
		
		if ($this->proxy) {
			curl_set_proxy($this->ch);
        }

		$took = microtime(true);
		$body = curl_exec($this->ch);
		$took = round(microtime(true) - $took, 2) . 's';
		if ($body === false) throw new Exception('HTTP Request Error: ' . curl_error($this->ch));

		$headers = array_reduce( // parse http response headers into array
			array_filter($headers, function ($item) {
				return trim($item) != '';
			}),
			function ($carry, $item) use (&$code) {
				$parts = explode(':', $item, 2);
				if (count($parts) === 1) {
					list(, $code) = explode(' ', trim($item), 3);
					$carry = array();
				} else {
					list($k, $v) = $parts;
					$k = strtolower(trim($k));
					switch ($k) {
						case 'link':
							if (preg_match('/<(.*)>\s*;\s*rel=\"(.*)\"/', $v, $matches)) {
								$carry[$k][$matches[2]][] = trim($matches[1]);
							}
							break;
						case 'content-type':
							list($v) = explode(';', $v, 2);
						default:
							$carry[$k] = trim($v);
							break;
					}
				}
				return $carry;
			},
			array()
		);
		$this->log('  ' . $url . ' [' . $code . '] (' . $took . ')');

		if (!empty($headers['replay-nonce'])) $this->nonce = $headers['replay-nonce'];

		if (isset($headers['retry-after'])) {
			if (is_numeric($headers['retry-after'])) {
				$this->delay_until = time() + ceil($headers['retry-after']);
			} else {
				$this->delay_until = strtotime($headers['retry-after']);
			}
			$tmp = $this->delay_until - time();
			// ignore delay if not in range 1s..5min
			if ($tmp > 300 || $tmp < 1) $this->delay_until = null;
		}

		if (!empty($headers['content-type'])) {
			switch ($headers['content-type']) {
				case 'application/json':
					if ($code[0] == '2') { // on non 2xx response: fall through to problem+json case
						$body = $this->json_decode($body);
						if (isset($body['error']) && !(isset($body['status']) && $body['status'] === 'valid')) {
							$this->handleError($body['error']);
						}
						break;
					}
				case 'application/problem+json':
					$body = $this->json_decode($body);
					$this->handleError($body);
					break;
			}
		}

		if ($code[0] != '2') {
			throw new Exception('Invalid HTTP-Status-Code received: ' . $code . ': ' . print_r($body, true));
		}

		$ret = array(
			'code' => $code,
			'headers' => $headers,
			'body' => $body
		);

		return $ret;
	}

	private function handleError($error)
	{
		throw $this->create_ACME_Exception(
			$error['type'],
			$error['detail'],
			array_map(function ($subproblem) {
				return $this->create_ACME_Exception(
					$subproblem['type'],
					(isset($subproblem['identifier']['value']) ?
						'"' . $subproblem['identifier']['value'] . '": ' :
						''
					) . $subproblem['detail']
				);
			}, isset($error['subproblems']) ? $error['subproblems'] : array())
		);
	}

	// 替换反向代理配置
	protected function proxiedURL($url)
	{
		if ($this->proxy == 2) {
			return str_replace(
				$this->proxy_config['origin'],
				$this->proxy_config['proxy'],
				$url
			);
		}
		return $url;
	}

	// 反向替换反向代理配置
	protected function unproxiedURL($url)
	{
		if ($this->proxy == 2) {
			return str_replace(
				$this->proxy_config['proxy'],
				$this->proxy_config['origin'],
				$url
			);
		}
		return $url;
	}
}
