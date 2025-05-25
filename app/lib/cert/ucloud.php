<?php

namespace app\lib\cert;

use app\lib\CertInterface;
use app\lib\client\Ucloud as UcloudClient;
use Exception;

class ucloud implements CertInterface
{
    private $PublicKey;
    private $PrivateKey;
    private $config;
    private $logger;
    private UcloudClient $client;

    public function __construct($config, $ext = null)
    {
        $this->PublicKey = $config['PublicKey'];
        $this->PrivateKey = $config['PrivateKey'];
        $this->client = new UcloudClient($this->PublicKey, $this->PrivateKey);
        $this->config = $config;
    }

    public function register()
    {
        if (empty($this->PublicKey) || empty($this->PrivateKey) || empty($this->config['username']) || empty($this->config['phone']) || empty($this->config['email'])) throw new Exception('必填参数不能为空');
        $param = ['Mode' => 'free'];
        $this->request('GetCertificateList', $param);
        return true;
    }

    public function buyCert($domainList, &$order)
    {
        $param = [
            'CertificateBrand' => 'TrustAsia',
            'CertificateName' => 'TrustAsiaC1DVFree',
            'DomainsCount' => 1,
            'ValidYear' => 1,
        ];
        $data = $this->request('PurchaseCertificate', $param);
        if (!isset($data['CertificateID'])) throw new Exception('证书购买失败，CertificateID为空');
        $order['CertificateID'] = $data['CertificateID'];
    }

    public function createOrder($domainList, &$order, $keytype, $keysize)
    {
        if (empty($domainList)) throw new Exception('域名列表不能为空');
        $domain = $domainList[0];
        $param = [
            'CertificateID' => $order['CertificateID'],
            'Domains' => $domain,
            'CSROnline' => 1,
            'CSREncryptAlgo' => ['RSA' => 'RSA', 'ECC' => 'ECDSA'][$keytype],
            'CSRKeyParameter' => ['2048' => '2048', '3072' => '3072', '256' => 'prime256v1', '384' => 'prime384v1'][$keysize],
            'CompanyName' => '公司名称',
            'CompanyAddress' => '公司地址',
            'CompanyRegion' => '北京',
            'CompanyCity' => '北京',
            'CompanyCountry' => 'CN',
            'CompanyDivision' => '部门',
            'CompanyPhone' => $this->config['phone'],
            'CompanyPostalCode' => '110100',
            'AdminName' => $this->config['username'],
            'AdminPhone' => $this->config['phone'],
            'AdminEmail' => $this->config['email'],
            'AdminTitle' => '职员',
            'DVAuthMethod' => 'DNS'
        ];
        $data = $this->request('ComplementCSRInfo', $param);

        sleep(3);

        $param = [
            'CertificateID' => $order['CertificateID'],
        ];
        $data = $this->request('GetDVAuthInfo', $param);

        $dnsList = [];
        if (!empty($data['Auths'])) {
            foreach ($data['Auths'] as $auth) {
                $mainDomain = getMainDomain($auth['Domain']);
                $name = substr($auth['Domain'], 0, -(strlen($mainDomain) + 1));
                $dnsList[$mainDomain][] = ['name' => $name, 'type' => $auth['AuthType'] == 'DNS_TXT' ? 'TXT' : 'CNAME', 'value' => $auth['AuthValue']];
            }
        }
        return $dnsList;
    }

    public function authOrder($domainList, $order) {}

    public function getAuthStatus($domainList, $order)
    {
        $param = [
            'CertificateID' => $order['CertificateID'],
        ];
        $data = $this->request('GetCertificateDetailInfo', $param);
        if ($data['CertificateInfo']['StateCode'] == 'COMPLETED' || $data['CertificateInfo']['StateCode'] == 'RENEWED') {
            return true;
        } elseif ($data['CertificateInfo']['StateCode'] == 'REJECTED' || $data['CertificateInfo']['StateCode'] == 'SECURITY_REVIEW_FAILED') {
            throw new Exception('证书审核失败:' . $data['CertificateInfo']['State']);
        } else {
            return false;
        }
    }

    public function finalizeOrder($domainList, $order, $keytype, $keysize)
    {
        if (!is_dir(app()->getRuntimePath() . 'cert')) mkdir(app()->getRuntimePath() . 'cert');
        $param = [
            'CertificateID' => $order['CertificateID'],
        ];
        $info = $this->request('GetCertificateDetailInfo', $param);

        $data = $this->request('DownloadCertificate', $param);
        $file_data = get_curl($data['CertificateUrl']);
        $file_path = app()->getRuntimePath() . 'cert/USSL_' . $order['CertificateID'] . '.zip';
        file_put_contents($file_path, $file_data);

        $zip = new \ZipArchive;
        if ($zip->open($file_path) === true) {
            $zip->extractTo(app()->getRuntimePath() . 'cert/');
            $zip->close();
        } else {
            throw new Exception('解压证书失败');
        }
        $cert_dir = app()->getRuntimePath() . 'cert/Nginx';

        $items = scandir($cert_dir);
        if ($items === false) throw new Exception('解压后的证书文件夹不存在');
        $private_key = null;
        $fullchain = null;
        foreach ($items as $item) {
            if (substr($item, -4) == '.key') {
                $private_key = file_get_contents($cert_dir . '/' . $item);
            } elseif (substr($item, -4) == '.pem') {
                $fullchain = file_get_contents($cert_dir . '/' . $item);
            }
        }
        if (empty($private_key) || empty($fullchain)) throw new Exception('解压后的证书文件夹内未找到证书文件');

        clearDirectory(app()->getRuntimePath() . 'cert');

        return ['private_key' => $private_key, 'fullchain' => $fullchain, 'issuer' => $info['CertificateInfo']['CaOrganization'], 'subject' => $info['CertificateInfo']['Name'], 'validFrom' => $info['CertificateInfo']['IssuedDate'], 'validTo' => $info['CertificateInfo']['ExpiredDate']];
    }

    public function revoke($order, $pem)
    {
        $param = [
            'CertificateID' => $order['CertificateID'],
            'Reason' => '业务终止',
        ];
        $this->request('RevokeCertificate', $param);
    }

    public function cancel($order)
    {
        $param = [
            'CertificateID' => $order['CertificateID'],
        ];
        $this->request('CancelCertificateOrder', $param);

        sleep(1);

        $param['CertificateMode'] = 'purchase';
        $this->request('DeleteSSLCertificate', $param);
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }

    private function request($action, $params)
    {
        $this->log('Action:' . $action . PHP_EOL . 'Request:' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $result = $this->client->request($action, $params);
        $this->log('Response:' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $result;
    }
}
