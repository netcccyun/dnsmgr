<?php

namespace app\lib\client;

use Exception;

/**
 * 华为云OBS
 */
class HuaweiOBS
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $Endpoint;
    private $proxy = false;

    public function __construct($AccessKeyId, $SecretAccessKey, $Endpoint, $proxy = false)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->SecretAccessKey = $SecretAccessKey;
        $this->Endpoint = $Endpoint;
        $this->proxy = $proxy;
    }

    public function setBucketCustomdomain($bucket, $domain, $cert_name, $fullchain, $privatekey)
    {
        $strXml = <<<EOF
        <CustomDomainConfiguration>
        </CustomDomainConfiguration>
        EOF;
        $xml = new \SimpleXMLElement($strXml);
        $xml->addChild('Name', $cert_name);
        $xml->addChild('Certificate', $fullchain);
        $xml->addChild('PrivateKey', $privatekey);
        $body = $xml->asXML();

        $options = [
            'bucket' => $bucket,
            'key' => '',
        ];
        $query = [
            'customdomain' => $domain
        ];
        return $this->request('PUT', '/', $query, $body, $options);
    }

    public function deleteBucketCustomdomain($bucket, $domain)
    {
        $options = [
            'bucket' => $bucket,
            'key' => '',
        ];
        $query = [
            'customdomain' => $domain
        ];
        return $this->request('DELETE', '/', $query, '', $options);
    }

    public function getBucketCustomdomain($bucket)
    {
        $options = [
            'bucket' => $bucket,
            'key' => '',
        ];
        $query = [
            'customdomain' => '',
        ];
        return $this->request('GET', '/', $query, '', $options);
    }

    private function request($method, $path, $query, $body, $options)
    {
        $hostname = $options['bucket'] . '.' . $this->Endpoint;
        $query_string = $this->toQueryString($query);
        $query_string = empty($query_string) ? '' : '?' . $query_string;
        $requestUrl = 'https://' . $hostname . $path . $query_string;
        $headers = [
            'Content-Type' => 'application/xml',
            'Content-MD5' => base64_encode(md5($body, true)),
            'Date' => gmdate('D, d M Y H:i:s \G\M\T'),
        ];
        $headers['Authorization'] = $this->getAuthorization($method, $path, $query, $headers, $options);
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $this->curl($method, $requestUrl, $body, $header);
    }

    private function curl($method, $url, $body, $header)
    {
        $ch = curl_init($url);
        if ($this->proxy) {
            curl_set_proxy($ch);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception('Curl error: ' . $errmsg);
        }
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            if (empty($response)) return true;
            return $this->xml2array($response);
        }
        $arr = $this->xml2array($response);
        if (isset($arr['Message'])) {
            throw new Exception($arr['Message']);
        } else {
            throw new Exception('HTTP Code: ' . $httpCode);
        }
    }

    private function toQueryString($params = array())
    {
        $temp = array();
        uksort($params, 'strnatcasecmp');
        foreach ($params as $key => $value) {
            if (is_string($key) && !is_array($value)) {
                if (strlen($value) > 0) {
                    $temp[] = rawurlencode($key) . '=' . rawurlencode($value);
                } else {
                    $temp[] = rawurlencode($key);
                }
            }
        }
        return implode('&', $temp);
    }

    private function xml2array($xml)
    {
        if (!$xml) {
            return false;
        }
        LIBXML_VERSION < 20900 && libxml_disable_entity_loader(true);
        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA), JSON_UNESCAPED_UNICODE), true);
    }

    private function getAuthorization($method, $url, $query, $headers, $options)
    {
        $method = strtoupper($method);
        $date = $headers['Date'];
        $resourcePath = $this->getResourcePath($options);
        $stringToSign = $this->calcStringToSign($method, $date, $headers, $resourcePath, $query);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->SecretAccessKey, true));
        return 'OBS ' . $this->AccessKeyId . ':' . $signature;
    }

    private function getResourcePath(array $options)
    {
        $resourcePath = '/';
        if (strlen($options['bucket']) > 0) {
            $resourcePath .= $options['bucket'] . '/';
        }
        if (strlen($options['key']) > 0) {
            $resourcePath .= $options['key'];
        }
        return $resourcePath;
    }

    private function calcStringToSign($method, $date, array $headers, $resourcePath, array $query)
    {
        /*
		SignToString =
			VERB + "\n"
			+ Content-MD5 + "\n"
			+ Content-Type + "\n"
			+ Date + "\n"
			+ CanonicalizedOSSHeaders
			+ CanonicalizedResource
		Signature = base64(hmac-sha1(AccessKeySecret, SignToString))
	    */
        $contentMd5 = '';
        $contentType = '';
        // CanonicalizedOSSHeaders
        $signheaders = array();
        foreach ($headers as $key => $value) {
            $lowk = strtolower($key);
            if (strncmp($lowk, "x-obs-", 6) == 0) {
                $signheaders[$lowk] = $value;
            } else if ($lowk === 'content-md5') {
                $contentMd5 = $value;
            } else if ($lowk === 'content-type') {
                $contentType = $value;
            }
        }
        ksort($signheaders);
        $canonicalizedOSSHeaders = '';
        foreach ($signheaders as $key => $value) {
            $canonicalizedOSSHeaders .= $key . ':' . $value . "\n";
        }
        // CanonicalizedResource
        $signquery = array();
        foreach ($query as $key => $value) {
            if (in_array($key, $this->signKeyList)) {
                $signquery[$key] = $value;
            }
        }
        ksort($signquery);
        $sortedQueryList = array();
        foreach ($signquery as $key => $value) {
            if (strlen($value) > 0) {
                $sortedQueryList[] = $key . '=' . $value;
            } else {
                $sortedQueryList[] = $key;
            }
        }
        $queryStringSorted = implode('&', $sortedQueryList);
        $canonicalizedResource = $resourcePath;
        if (!empty($queryStringSorted)) {
            $canonicalizedResource .= '?' . $queryStringSorted;
        }
        return $method . "\n" . $contentMd5 . "\n" . $contentType . "\n" . $date . "\n" . $canonicalizedOSSHeaders . $canonicalizedResource;
    }

    private $signKeyList = array(
        'acl', 'policy', 'torrent', 'logging', 'location', 'storageinfo', 'quota', 'storagepolicy', 'requestpayment', 'versions', 'versioning', 'versionid', 'uploads', 'uploadid', 'partnumber', 'website', 'notification', 'lifecycle', 'deletebucket', 'delete', 'cors', 'restore', 'tagging', 'response-content-type', 'response-content-language', 'response-expires', 'response-cache-control', 'response-content-disposition', 'response-content-encoding', 'x-image-process', 'backtosource', 'storageclass', 'replication', 'append', 'position', 'x-oss-process', 'CDNNotifyConfiguration', 'attname', 'customdomain', 'directcoldaccess', 'encryption', 'inventory', 'length', 'metadata', 'modify', 'name', 'rename', 'truncate', 'x-image-save-bucket', 'x-image-save-object', 'x-obs-security-token', 'x-obs-callback'
    );
}