<?php

namespace app\lib\cert;

use app\lib\CertInterface;
use app\lib\acme\ACMECert;
use Exception;

class zerossl implements CertInterface
{
    private $directory = 'https://acme.zerossl.com/v2/DV90';
    private $ac;
    private $config;
    private $ext;

    public function __construct($config, $ext = null)
    {
        $this->config = $config;
        $this->ac = new ACMECert($this->directory, (int)$config['proxy']);
        if ($ext) {
            $this->ext = $ext;
            $this->ac->loadAccountKey($ext['key']);
            $this->ac->setAccount($ext['kid']);
        }
    }

    public function register()
    {
        if (empty($this->config['email'])) throw new Exception('邮件地址不能为空');

        if (isset($this->config['eabMode']) && $this->config['eabMode'] == 'auto') {
            $eab = $this->getEAB($this->config['email']);
        } else {
            $eab = ['kid' => $this->config['kid'], 'key' => $this->config['key']];
        }

        if (!empty($this->ext['key'])) {
            $kid = $this->ac->registerEAB(true, $eab['kid'], $eab['key'], $this->config['email']);
            return ['kid' => $kid, 'key' => $this->ext['key']];
        }

        $key = $this->ac->generateRSAKey(2048);
        $this->ac->loadAccountKey($key);
        $kid = $this->ac->registerEAB(true, $eab['kid'], $eab['key'], $this->config['email']);
        return ['kid' => $kid, 'key' => $key];
    }

    public function buyCert($domainList, &$order)
    {
    }

    public function createOrder($domainList, &$order, $keytype, $keysize)
    {
        $domain_config = [];
        foreach ($domainList as $domain) {
            if (empty($domain)) continue;
            $domain_config[$domain] = ['challenge' => 'dns-01'];
        }
        if (empty($domain_config)) throw new Exception('域名列表不能为空');

        $order = $this->ac->createOrder($domain_config);

        $dnsList = [];
        if (!empty($order['challenges'])) {
            foreach ($order['challenges'] as $opts) {
                $mainDomain = getMainDomain($opts['domain']);
                $name = substr($opts['key'], 0, -(strlen($mainDomain) + 1));
                /*if (!array_key_exists($mainDomain, $dnsList)) {
                    $dnsList[$mainDomain][] = ['name' => '@', 'type' => 'CAA', 'value' => '0 issue "sectigo.com"'];
                }*/
                $dnsList[$mainDomain][] = ['name' => $name, 'type' => 'TXT', 'value' => $opts['value']];
            }
        }

        return $dnsList;
    }

    public function authOrder($domainList, $order)
    {
        $this->ac->authOrder($order);
    }

    public function getAuthStatus($domainList, $order)
    {
        return true;
    }

    public function finalizeOrder($domainList, $order, $keytype, $keysize)
    {
        if (empty($domainList)) throw new Exception('域名列表不能为空');

        if ($keytype == 'ECC') {
            if (empty($keysize)) $keysize = '384';
            $private_key = $this->ac->generateECKey($keysize);
        } else {
            if (empty($keysize)) $keysize = '2048';
            $private_key = $this->ac->generateRSAKey($keysize);
        }
        $fullchain = $this->ac->finalizeOrder($domainList, $order, $private_key);

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        return ['private_key' => $private_key, 'fullchain' => $fullchain, 'issuer' => $certInfo['issuer']['CN'], 'subject' => $certInfo['subject']['CN'], 'validFrom' => $certInfo['validFrom_time_t'], 'validTo' => $certInfo['validTo_time_t']];
    }

    public function revoke($order, $pem)
    {
        $this->ac->revoke($pem);
    }

    public function cancel($order)
    {
    }

    public function setLogger($func)
    {
        $this->ac->setLogger($func);
    }

    private function getEAB($email)
    {
        $api = "https://api.zerossl.com/acme/eab-credentials-email";
        $response = http_request($api, http_build_query(['email' => $email]), null, null, null, $this->config['proxy'] == 1);
        $result = json_decode($response['body'], true);
        if (!isset($result['success'])) {
            throw new Exception('获取EAB失败：' . $response['body']);
        } elseif (!$result['success'] && isset($result['error'])) {
            throw new Exception('获取EAB失败：' . $result['error']['code'] . ' - ' . $result['error']['type']);
        } elseif (!isset($result['eab_kid']) || !isset($result['eab_hmac_key'])) {
            throw new Exception('获取EAB失败：返回数据不完整');
        }
        return ['kid' => $result['eab_kid'], 'key' => $result['eab_hmac_key']];
    }
}
