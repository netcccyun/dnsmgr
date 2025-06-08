<?php

namespace app\lib\client;

use Exception;

class Ucloud
{
    const VERSION = "0.1.0";

    private $ApiUrl = 'https://api.ucloud.cn/';
    private $PublicKey;
    private $PrivateKey;

    public function __construct($PublicKey, $PrivateKey)
    {
        $this->PublicKey = $PublicKey;
        $this->PrivateKey = $PrivateKey;
    }

    public function request($action, $params)
    {
        $param = [
            'Action' => $action,
            'PublicKey' => $this->PublicKey,
        ];
        $param = array_merge($param, $params);
        $param['Signature'] = $this->ucloudSignature($param);
        $ua = sprintf("PHP/%s PHP-SDK/%s", phpversion(), self::VERSION);
        $response = get_curl($this->ApiUrl, json_encode($param), 0, 0, $ua, 0, ['Content-Type' => 'application/json']);
        $result = json_decode($response, true);
        if (isset($result['RetCode']) && $result['RetCode'] == 0) {
            return $result;
        } elseif (isset($result['Message'])) {
            throw new Exception($result['Message']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    private function ucloudSignature($param)
    {
        ksort($param);
        $str = '';
        foreach ($param as $key => $value) {
            $str .= $key . $value;
        }
        $str .= $this->PrivateKey;
        return sha1($str);
    }
}
