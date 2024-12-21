<?php

namespace app\lib\mail;

use app\lib\client\Aliyun as AliyunClient;

class Aliyun
{
    private $AccessKeyId;
    private $AccessKeySecret;
    private $Endpoint = 'dm.aliyuncs.com';
    private $Version = '2015-11-23';
    private AliyunClient $client;

    public function __construct($AccessKeyId, $AccessKeySecret)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->AccessKeySecret = $AccessKeySecret;
        $this->client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $this->Endpoint, $this->Version);
    }

    public function send($to, $sub, $msg, $from, $from_name)
    {
        if (empty($this->AccessKeyId) || empty($this->AccessKeySecret)) return false;
        $param = [
            'Action' => 'SingleSendMail',
            'AccountName' => $from,
            'ReplyToAddress' => 'false',
            'AddressType' => 1,
            'ToAddress' => $to,
            'FromAlias' => $from_name,
            'Subject' => $sub,
            'HtmlBody' => $msg,
        ];
        try {
            $this->client->request($param);
            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
