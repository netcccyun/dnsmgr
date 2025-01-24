<?php

namespace app\lib\acme;

use Exception;
use stdClass;

/**
 * ACMECert
 * https://github.com/skoerfgen/ACMECert
 */
class ACMECert extends ACMEv2
{
	private $alternate_chains = array();

	public function register($termsOfServiceAgreed = false, $contacts = array())
	{
		return $this->_register($termsOfServiceAgreed, $contacts);
	}

	public function registerEAB($termsOfServiceAgreed, $eab_kid, $eab_hmac, $contacts = array())
	{
		if (!$this->resources) $this->readDirectory();

		$protected = array(
			'alg' => 'HS256',
			'kid' => $eab_kid,
			'url' => $this->resources['newAccount']
		);
		$payload = $this->jwk_header['jwk'];

		$protected64 = $this->base64url(json_encode($protected, JSON_UNESCAPED_SLASHES));
		$payload64 = $this->base64url(json_encode($payload, JSON_UNESCAPED_SLASHES));

		$signature = hash_hmac('sha256', $protected64 . '.' . $payload64, $this->base64url_decode($eab_hmac), true);

		return $this->_register($termsOfServiceAgreed, $contacts, array(
			'externalAccountBinding' => array(
				'protected' => $protected64,
				'payload' => $payload64,
				'signature' => $this->base64url($signature)
			)
		));
	}

	private function _register($termsOfServiceAgreed = false, $contacts = array(), $extra = array())
	{
		$this->log('Registering account');

		$ret = $this->request('newAccount', array(
			'termsOfServiceAgreed' => (bool)$termsOfServiceAgreed,
			'contact' => $this->make_contacts_array($contacts)
		) + $extra);
		$this->log($ret['code'] == 201 ? 'Account registered' : 'Account already registered');
		return $this->kid_header['kid'];
	}

	public function update($contacts = array())
	{
		$this->log('Updating account');
		$ret = $this->request($this->getAccountID(), array(
			'contact' => $this->make_contacts_array($contacts)
		));
		$this->log('Account updated');
		return $ret['body'];
	}

	public function getAccount()
	{
		$ret = parent::getAccount();
		return $this->kid_header['kid'];
	}

	public function setAccount($kid)
	{
		$this->kid_header['kid'] = $kid;
	}

	public function deactivateAccount()
	{
		$this->log('Deactivating account');
		$ret = $this->deactivate($this->getAccountID());
		$this->log('Account deactivated');
		return $ret;
	}

	public function deactivate($url)
	{
		$this->log('Deactivating resource: ' . $url);
		$ret = $this->request($url, array('status' => 'deactivated'));
		$this->log('Resource deactivated');
		return $ret['body'];
	}

	public function getTermsURL()
	{
		if (!$this->resources) $this->readDirectory();
		if (!isset($this->resources['meta']['termsOfService'])) {
			throw new Exception('Failed to get Terms Of Service URL');
		}
		return $this->resources['meta']['termsOfService'];
	}

	public function getCAAIdentities()
	{
		if (!$this->resources) $this->readDirectory();
		if (!isset($this->resources['meta']['caaIdentities'])) {
			throw new Exception('Failed to get CAA Identities');
		}
		return $this->resources['meta']['caaIdentities'];
	}

	public function keyChange($new_account_key_pem)
	{ // account key roll-over
		$this->loadAccountKey($new_account_key_pem);
		$account = $this->getAccountID();
		$this->resources = $this->resources;

		$this->log('Account Key Roll-Over');

		$ret = $this->request(
			'keyChange',
			$this->jws_encapsulate('keyChange', array(
				'account' => $account,
				'oldKey' => $this->jwk_header['jwk']
			), true)
		);
		$this->log('Account Key Roll-Over successful');

		$this->loadAccountKey($new_account_key_pem);
		return $ret['body'];
	}

	public function revoke($pem)
	{
		if (false === ($res = openssl_x509_read($pem))) {
			throw new Exception('Could not load certificate: ' . $pem . ' (' . $this->get_openssl_error() . ')');
		}
		if (false === (openssl_x509_export($res, $certificate))) {
			throw new Exception('Could not export certificate: ' . $pem . ' (' . $this->get_openssl_error() . ')');
		}

		$this->log('Revoking certificate');
		$this->request('revokeCert', array(
			'certificate' => $this->base64url($this->pem2der($certificate))
		));
		$this->log('Certificate revoked');
	}

	public function createOrder($domain_config, $settings = array())
	{
		$settings = $this->parseSettings($settings);

		$domain_config = array_change_key_case($domain_config, CASE_LOWER);
		$domains = array_keys($domain_config);
		$authz_deactivated = false;

		// === Order ===
		$this->log('Creating Order');
		$ret = $this->request('newOrder', $this->makeOrder($domains, $settings));
		$order = $ret['body'];
		$order_location = $ret['headers']['location'];
		$this->log('Order created: ' . $order_location);
		$order['location'] = $order_location;

		// === Authorization ===
		if ($order['status'] === 'ready' && $settings['authz_reuse']) {
			$this->log('All authorizations already valid, skipping validation altogether');
		} else {
			$auth_count = count($order['authorizations']);
			$challenges = array();

			foreach ($order['authorizations'] as $idx => $auth_url) {
				$this->log('Fetching authorization ' . ($idx + 1) . ' of ' . $auth_count);
				$ret = $this->request($auth_url, '');
				$authorization = $ret['body'];

				// wildcard authorization identifiers have no leading *.
				$domain = ( // get domain and add leading *. if wildcard is used
					isset($authorization['wildcard']) &&
					$authorization['wildcard'] ?
					'*.' : ''
				) . $authorization['identifier']['value'];

				if ($authorization['status'] === 'valid') {
					if ($settings['authz_reuse']) {
						$this->log('Authorization of ' . $domain . ' already valid, skipping validation');
					} else {
						$this->log('Authorization of ' . $domain . ' already valid, deactivating authorization');
						$this->deactivate($auth_url);
						$authz_deactivated = true;
					}
					continue;
				}

				if(!isset($domain_config[$domain])) {
					$this->log('Domain ' . $domain . ' not found in domain_config');
					continue;
				}

				$config = $domain_config[$domain];
				$type = $config['challenge'];

				$challenge = $this->parse_challenges($authorization, $type, $challenge_url);

				$opts = array(
					'domain' => $domain,
					'type' => $type,
					'auth_url' => $auth_url,
					'challenge_url' => $challenge_url
				);
				list($opts['key'], $opts['value']) = $challenge;
				
				$challenges[] = $opts;
			}

			if ($authz_deactivated) {
				$this->log('Restarting Order after deactivating already valid authorizations');
				$settings['authz_reuse'] = true;
				return $this->createOrder($domain_config, $settings);
			}

			$order['challenges'] = $challenges;
		}
		return $order;
	}

	public function authOrder($order)
	{
		if ($order['status'] != 'pending' && $order['status'] != 'ready' && empty($order['challenges'])) {
			throw new Exception('No challenges available');
		}

		// === Challenge ===
		if (!empty($order['challenges'])){
			foreach ($order['challenges'] as $opts) {
				$this->log('Notifying server for validation of ' . $opts['domain']);
				$this->request($opts['challenge_url'], new stdClass);
	
				$this->log('Waiting for server challenge validation');
				sleep(1);
	
				if (!$this->poll('pending', $opts['auth_url'], $body)) {
					$this->log('Validation failed: ' . $opts['domain']);
	
					$error = $body['challenges'][0]['error'];
					throw $this->create_ACME_Exception(
						$error['type'],
						'Challenge validation failed: ' . $error['detail']
					);
				} else {
					$this->log('Validation successful: ' . $opts['domain']);
				}
			}
		}
	}

	public function finalizeOrder($domains, $order, $pem)
	{
		// autodetect if Private Key or CSR is used
		if ($key = openssl_pkey_get_private($pem)) { // Private Key detected
			if (PHP_MAJOR_VERSION < 8) openssl_free_key($key);
			$this->log('Generating CSR');
			$csr = $this->generateCSR($pem, $domains);
		} elseif (openssl_csr_get_subject($pem)) { // CSR detected
			$this->log('Using provided CSR');
			if (0 === strpos($pem, 'file://')) {
				$csr = file_get_contents(substr($pem, 7));
				if (false === $csr) {
					throw new Exception('Failed to read CSR from ' . $pem . ' (' . $this->get_openssl_error() . ')');
				}
			} else {
				$csr = $pem;
			}
		} else {
			throw new Exception('Could not load Private Key or CSR (' . $this->get_openssl_error() . '): ' . $pem);
		}

		$this->log('Finalizing Order');

		$ret = $this->request($order['finalize'], array(
			'csr' => $this->base64url($this->pem2der($csr))
		));
		$ret = $ret['body'];

		if (isset($ret['certificate'])) {
			return $this->request_certificate($ret);
		}

		if ($this->poll('processing', $order['location'], $ret)) {
			return $this->request_certificate($ret);
		}

		throw new Exception('Order failed');
	}

	public function finalizeOrders($domains, $order, $pem)
	{
		$default_chain = $this->finalizeOrder($domains, $order, $pem);

		$out = array();
		$out[$this->getTopIssuerCN($default_chain)] = $default_chain;

		foreach ($this->alternate_chains as $link) {
			$chain = $this->request_certificate(array('certificate' => $link), true);
			$out[$this->getTopIssuerCN($chain)] = $chain;
		}

		$this->log('Received ' . count($out) . ' chain(s): ' . implode(', ', array_keys($out)));
		return $out;
	}

	public function generateCSR($domain_key_pem, $domains)
	{
		if (false === ($domain_key = openssl_pkey_get_private($domain_key_pem))) {
			throw new Exception('Could not load domain key: ' . $domain_key_pem . ' (' . $this->get_openssl_error() . ')');
		}

		$fn = $this->tmp_ssl_cnf($domains);
		$cn = reset($domains);
		$dn = array();
		if (strlen($cn) <= 64) {
			$dn['commonName'] = $cn;
		}
		$csr = openssl_csr_new($dn, $domain_key, array(
			'config' => $fn,
			'req_extensions' => 'SAN',
			'digest_alg' => 'sha512'
		));
		unlink($fn);
		if (PHP_MAJOR_VERSION < 8) openssl_free_key($domain_key);

		if (false === $csr) {
			throw new Exception('Could not generate CSR ! (' . $this->get_openssl_error() . ')');
		}
		if (false === openssl_csr_export($csr, $out)) {
			throw new Exception('Could not export CSR ! (' . $this->get_openssl_error() . ')');
		}

		return $out;
	}

	private function generateKey($opts)
	{
		$fn = $this->tmp_ssl_cnf();
		$config = array('config' => $fn) + $opts;
		if (false === ($key = openssl_pkey_new($config))) {
			throw new Exception('Could not generate new private key ! (' . $this->get_openssl_error() . ')');
		}
		if (false === openssl_pkey_export($key, $pem, null, $config)) {
			throw new Exception('Could not export private key ! (' . $this->get_openssl_error() . ')');
		}
		unlink($fn);
		if (PHP_MAJOR_VERSION < 8) openssl_free_key($key);
		return $pem;
	}

	public function generateRSAKey($bits = 2048)
	{
		return $this->generateKey(array(
			'private_key_bits' => (int)$bits,
			'private_key_type' => OPENSSL_KEYTYPE_RSA
		));
	}

	public function generateECKey($curve_name = '384')
	{
		if (version_compare(PHP_VERSION, '7.1.0') < 0) throw new Exception('PHP >= 7.1.0 required for EC keys !');
		$map = array('256' => 'prime256v1', '384' => 'secp384r1', '521' => 'secp521r1');
		if (isset($map[$curve_name])) $curve_name = $map[$curve_name];
		return $this->generateKey(array(
			'curve_name' => $curve_name,
			'private_key_type' => OPENSSL_KEYTYPE_EC
		));
	}

	public function parseCertificate($cert_pem)
	{
		if (false === ($ret = openssl_x509_read($cert_pem))) {
			throw new Exception('Could not load certificate: ' . $cert_pem . ' (' . $this->get_openssl_error() . ')');
		}
		if (!is_array($ret = openssl_x509_parse($ret, true))) {
			throw new Exception('Could not parse certificate (' . $this->get_openssl_error() . ')');
		}
		return $ret;
	}

	public function getSAN($pem)
	{
		$ret = $this->parseCertificate($pem);
		if (!isset($ret['extensions']['subjectAltName'])) {
			throw new Exception('No Subject Alternative Name (SAN) found in certificate');
		}
		$out = array();
		foreach (explode(',', $ret['extensions']['subjectAltName']) as $line) {
			list($type, $name) = array_map('trim', explode(':', $line));
			if ($type === 'DNS') {
				$out[] = $name;
			}
		}
		return $out;
	}

	public function getRemainingDays($cert_pem)
	{
		$ret = $this->parseCertificate($cert_pem);
		return ($ret['validTo_time_t'] - time()) / 86400;
	}

	public function getRemainingPercent($cert_pem)
	{
		$ret = $this->parseCertificate($cert_pem);
		$total = $ret['validTo_time_t'] - $ret['validFrom_time_t'];
		$used = time() - $ret['validFrom_time_t'];
		return (1 - max(0, min(1, $used / $total))) * 100;
	}

	public function generateALPNCertificate($domain_key_pem, $domain, $token)
	{
		$domains = array($domain);
		$csr = $this->generateCSR($domain_key_pem, $domains);

		$fn = $this->tmp_ssl_cnf($domains, '1.3.6.1.5.5.7.1.31=critical,DER:0420' . $token . "\n");
		$config = array(
			'config' => $fn,
			'x509_extensions' => 'SAN',
			'digest_alg' => 'sha512'
		);
		$cert = openssl_csr_sign($csr, null, $domain_key_pem, 1, $config);
		unlink($fn);
		if (false === $cert) {
			throw new Exception('Could not generate self signed certificate ! (' . $this->get_openssl_error() . ')');
		}
		if (false === openssl_x509_export($cert, $out)) {
			throw new Exception('Could not export self signed certificate ! (' . $this->get_openssl_error() . ')');
		}
		return $out;
	}

	public function getARI($pem, &$ari_cert_id = null)
	{
		$ari_cert_id = null;
		$id = $this->getARICertID($pem);

		if (!$this->resources) $this->readDirectory();
		if (!isset($this->resources['renewalInfo'])) throw new Exception('ARI not supported');

		$ret = $this->http_request($this->resources['renewalInfo'] . '/' . $id);

		if (!is_array($ret['body']['suggestedWindow'])) throw new Exception('ARI suggestedWindow not present');

		$sw = &$ret['body']['suggestedWindow'];

		if (!isset($sw['start'])) throw new Exception('ARI suggestedWindow start not present');
		if (!isset($sw['end'])) throw new Exception('ARI suggestedWindow end not present');

		$sw = array_map(array($this, 'parseDate'), $sw);

		$ari_cert_id = $id;
		return $ret['body'];
	}

	private function getARICertID($pem)
	{
		if (version_compare(PHP_VERSION, '7.1.2', '<')) {
			throw new Exception('PHP Version >= 7.1.2 required for ARI'); // serialNumberHex - https://github.com/php/php-src/pull/1755
		}
		$ret = $this->parseCertificate($pem);

		if (!isset($ret['extensions']['authorityKeyIdentifier'])) {
			throw new Exception('authorityKeyIdentifier missing');
		}
		$aki = hex2bin(str_replace(':', '', substr(trim($ret['extensions']['authorityKeyIdentifier']), 6)));
		if (!$aki) throw new Exception('Failed to parse authorityKeyIdentifier');

		if (!isset($ret['serialNumberHex'])) {
			throw new Exception('serialNumberHex missing');
		}
		$ser = hex2bin(trim($ret['serialNumberHex']));
		if (!$ser) throw new Exception('Failed to parse serialNumberHex');

		return $this->base64url($aki) . '.' . $this->base64url($ser);
	}

	private function parseDate($str)
	{
		$ret = strtotime(preg_replace('/(\.\d\d)\d+/', '$1', $str));
		if ($ret === false) throw new Exception('Failed to parse date: ' . $str);
		return $ret;
	}

	private function parseSettings($opts)
	{
		// authz_reuse: backwards compatibility to ACMECert v3.1.2 or older
		if (!is_array($opts)) $opts = array('authz_reuse' => (bool)$opts);
		if (!isset($opts['authz_reuse'])) $opts['authz_reuse'] = true;

		$diff = array_diff_key(
			$opts,
			array_flip(array('authz_reuse', 'notAfter', 'notBefore', 'replaces'))
		);

		if (!empty($diff)) {
			throw new Exception('getCertificateChain(s): Invalid option "' . key($diff) . '"');
		}

		return $opts;
	}

	private function setRFC3339Date(&$out, $key, $opts)
	{
		if (isset($opts[$key])) {
			$out[$key] = is_string($opts[$key]) ?
				$opts[$key] :
				date(DATE_RFC3339, $opts[$key]);
		}
	}

	private function makeOrder($domains, $opts)
	{
		$order = array(
			'identifiers' => array_map(
				function ($domain) {
					return array('type' => 'dns', 'value' => $domain);
				},
				$domains
			)
		);
		$this->setRFC3339Date($order, 'notAfter', $opts);
		$this->setRFC3339Date($order, 'notBefore', $opts);

		if (isset($opts['replaces'])) { // ARI
			$order['replaces'] = $opts['replaces'];
			$this->log('Replacing Certificate: ' . $opts['replaces']);
		}

		return $order;
	}

	private function parse_challenges($authorization, $type, &$url)
	{
		foreach ($authorization['challenges'] as $challenge) {
			if ($challenge['type'] != $type) continue;

			$url = $challenge['url'];

			switch ($challenge['type']) {
				case 'dns-01':
					return array(
						'_acme-challenge.' . $authorization['identifier']['value'],
						$this->base64url(hash('sha256', $this->keyAuthorization($challenge['token']), true))
					);
					break;
				case 'http-01':
					return array(
						'/.well-known/acme-challenge/' . $challenge['token'],
						$this->keyAuthorization($challenge['token'])
					);
					break;
				case 'tls-alpn-01':
					return array(null, hash('sha256', $this->keyAuthorization($challenge['token'])));
					break;
			}
		}
		throw new Exception(
			'Challenge type: "' . $type . '" not available, for this challenge use ' .
				implode(' or ', array_map(
					function ($a) {
						return '"' . $a['type'] . '"';
					},
					$authorization['challenges']
				))
		);
	}

	private function poll($initial, $type, &$ret)
	{
		$max_tries = 10; // ~ 5 minutes
		for ($i = 0; $i < $max_tries; $i++) {
			$ret = $this->request($type);
			$ret = $ret['body'];
			if ($ret['status'] !== $initial) return $ret['status'] === 'valid';
			$s = pow(2, min($i, 6));
			if ($i !== $max_tries - 1) {
				$this->log('Retrying in ' . ($s) . 's');
				sleep($s);
			}
		}
		throw new Exception('Aborted after ' . $max_tries . ' tries');
	}

	private function request_certificate($ret, $alternate = false)
	{
		$this->log('Requesting ' . ($alternate ? 'alternate' : 'default') . ' certificate-chain');
		$ret = $this->request($ret['certificate'], '');
		if ($ret['headers']['content-type'] !== 'application/pem-certificate-chain') {
			throw new Exception('Unexpected content-type: ' . $ret['headers']['content-type']);
		}

		$chain = array();
		foreach ($this->splitChain($ret['body']) as $cert) {
			$info = $this->parseCertificate($cert);
			$chain[] = '[' . $info['issuer']['CN'] . ']';
		}

		if (!$alternate) {
			if (isset($ret['headers']['link']['alternate'])) {
				$this->alternate_chains = $ret['headers']['link']['alternate'];
			} else {
				$this->alternate_chains = array();
			}
		}

		$this->log(($alternate ? 'Alternate' : 'Default') . ' certificate-chain retrieved: ' . implode(' -> ', array_reverse($chain, true)));
		return $ret['body'];
	}

	private function tmp_ssl_cnf($domains = null, $extension = '')
	{
		if (false === ($fn = tempnam(sys_get_temp_dir(), "CNF_"))) {
			throw new Exception('Failed to create temp file !');
		}
		if (false === @file_put_contents(
			$fn,
			'HOME = .' . "\n" .
				'RANDFILE=$ENV::HOME/.rnd' . "\n" .
				'[v3_ca]' . "\n" .
				'[req]' . "\n" .
				'default_bits=2048' . "\n" .
				($domains ?
					'distinguished_name=req_distinguished_name' . "\n" .
					'[req_distinguished_name]' . "\n" .
					'[v3_req]' . "\n" .
					'[SAN]' . "\n" .
					'subjectAltName=' .
					implode(',', array_map(function ($domain) {
						return 'DNS:' . $domain;
					}, $domains)) . "\n"
					:
					''
				) . $extension
		)) {
			throw new Exception('Failed to write tmp file: ' . $fn);
		}
		return $fn;
	}

	private function pem2der($pem)
	{
		return base64_decode(implode('', array_slice(
			array_map('trim', explode("\n", trim($pem))),
			1,
			-1
		)));
	}

	private function make_contacts_array($contacts)
	{
		if (!is_array($contacts)) {
			$contacts = $contacts ? array($contacts) : array();
		}
		return array_map(function ($contact) {
			return 'mailto:' . $contact;
		}, $contacts);
	}

	private function getTopIssuerCN($chain)
	{
		$tmp = $this->splitChain($chain);
		$ret = $this->parseCertificate(end($tmp));
		return $ret['issuer']['CN'];
	}

	public function splitChain($chain)
	{
		$delim = '-----END CERTIFICATE-----';
		return array_map(function ($item) use ($delim) {
			return trim($item . $delim);
		}, array_filter(explode($delim, $chain), function ($item) {
			return strpos($item, '-----BEGIN CERTIFICATE-----') !== false;
		}));
	}
}
