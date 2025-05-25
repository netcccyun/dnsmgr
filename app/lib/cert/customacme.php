<?php

namespace app\lib\cert;

use app\lib\CertInterface;
use app\lib\acme\ACMECert;
use Exception;

class customacme implements CertInterface
{
    private $ac;
    private $config;
    private $ext;

    public function __construct($config, $ext = null)
    {
        $this->config = $config;
        $this->ac = new ACMECert($config['directory'], (int)$config['proxy']);
        if ($ext) {
            $this->ext = $ext;
            $this->ac->loadAccountKey($ext['key']);
            $this->ac->setAccount($ext['kid']);
        }
    }

    public function register()
    {
        if (empty($this->config['directory'])) throw new Exception('ACME地址不能为空');
        if (empty($this->config['email'])) throw new Exception('邮件地址不能为空');

        if (!empty($this->ext['key'])) {
            if (!empty($this->config['kid']) && !empty($this->config['key'])) {
                $kid = $this->ac->registerEAB(true, $this->config['kid'], $this->config['key'], $this->config['email']);
            } else {
                $kid = $this->ac->register(true, $this->config['email']);
            }
            return ['kid' => $kid, 'key' => $this->ext['key']];
        }

        $key = $this->ac->generateRSAKey(2048);
        $this->ac->loadAccountKey($key);
        if (!empty($this->config['kid']) && !empty($this->config['key'])) {
            $kid = $this->ac->registerEAB(true, $this->config['kid'], $this->config['key'], $this->config['email']);
        } else {
            $kid = $this->ac->register(true, $this->config['email']);
        }
        return ['kid' => $kid, 'key' => $key];
    }

    public function buyCert($domainList, &$order) {}

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

    public function cancel($order) {}

    public function setLogger($func)
    {
        $this->ac->setLogger($func);
    }
}
