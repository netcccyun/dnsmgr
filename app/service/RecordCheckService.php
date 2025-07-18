<?php
namespace app\service;

use Exception;
use think\facade\Db;
use GuzzleHttp\Client;

class RecordCheckService
{
    private $apiUrl = "https://api.makuo.cc/api/get.other.icp";
    private $token = "6cx92OAIcm8yd29Kmpoc7w";

    public function checkDomainRecord(string $domain, int $id): array
    {
        $exists = Db::name('domain')->where('id', $id)->value('id');
        if (!$exists) {
            return ['code' => -1, 'msg' => '域名不存在'];
        }
    
        try {
            $client = new Client();
            $response = $client->get($this->apiUrl, [
                'query' => [
                    'token' => $this->token,
                    'url' => $domain
                ]
            ]);

            $rawBody = $response->getBody()->getContents();
    
            $encoding = mb_detect_encoding($rawBody, ['UTF-8', 'GBK', 'ASCII', 'ISO-8859-1'], true);
            if ($encoding !== 'UTF-8') {
                $rawBody = mb_convert_encoding($rawBody, 'UTF-8', $encoding);
            }
    
            $result = json_decode($rawBody, true);
    
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON 解码失败: ' . json_last_error_msg());
            }
    
            if (isset($result['code']) && $result['code'] == 200 && !empty($result['data'])) {
                $data = $result['data'];
    
                Db::name('domain')
                    ->where('id', $id)
                    ->strict(false)
                    ->update([
                        'record_status'      => 1,
                        'record_number'      => $data['icp'],
                    ]);
    
                return [
                    'code' => 0,
                    'data' => [
                        'record_status' => 1,
                        'record_number' => $data['icp']
                    ],
                    'msg' => '备案查询成功'
                ];
            }
    
            Db::name('domain')
                ->where('id', $id)
                ->strict(false)
                ->update([
                    'record_status'      => 2,
                    'record_number'      => null,
                ]);
    
            return [
                'code' => 0,
                'data' => [
                    'record_status' => 2,
                    'record_number' => null
                ],
                'msg' => '该域名暂未备案'
            ];
    
        } catch (Exception $e) {
            Db::name('domain')
                ->where('id', $id)
                ->strict(false)
                ->update([
                    'record_status' => 3,
                ]);
    
            return [
                'code' => -1,
                'msg' => '备案查询失败，请稍后再试'
            ];
        }
    }
}
