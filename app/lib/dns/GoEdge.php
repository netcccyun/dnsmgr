<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

class GoEdge implements DnsInterface
{
    private string $baseUrl;
    private string $accessKeyId;
    private string $accessKey;
    private string $userType;
    private int $nsClusterId;
    private int $userId;
    private bool $proxy;
    private ?string $accessToken = null;
    private ?string $error = null;
    private ?string $domain = null;
    private ?string $domainId = null;

    public function __construct($config)
    {
        $this->baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
        $this->accessKeyId = trim((string)($config['accessKeyId'] ?? ''));
        $this->accessKey = trim((string)($config['accessKey'] ?? ''));
        $this->userType = strtolower(trim((string)($config['type'] ?? $config['usertype'] ?? 'admin')));
        $this->nsClusterId = (int)($config['nsClusterId'] ?? 0);
        $this->userId = (int)($config['userId'] ?? 0);
        $this->proxy = filter_var($config['proxy'] ?? false, FILTER_VALIDATE_BOOLEAN) || $config['proxy'] === 1 || $config['proxy'] === '1';
        $this->domain = isset($config['domain']) ? (string)$config['domain'] : null;
        $this->domainId = isset($config['domainid']) && $config['domainid'] !== '' ? (string)$config['domainid'] : null;
    }

    public function getError()
    {
        return $this->error;
    }

    public function check()
    {
        if ($this->baseUrl === '' || $this->accessKeyId === '' || $this->accessKey === '') {
            $this->setError('API地址、AccessKey ID和AccessKey不能为空');
            return false;
        }
        if (!in_array($this->userType, ['admin', 'user'], true)) {
            $this->setError('AccessKey类型必须是admin或user');
            return false;
        }
        if ($this->nsClusterId <= 0) {
            $this->setError('DNS集群ID必须大于0');
            return false;
        }

        try {
            $data = $this->request('/NSClusterService/findNSCluster', ['nsClusterId' => $this->nsClusterId]);
            return isset($data['nsCluster']) && is_array($data['nsCluster']);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        try {
            $countData = $this->request('/NSDomainService/countAllNSDomains', $this->domainQuery($KeyWord));
            $listData = $this->request('/NSDomainService/listNSDomains', array_merge(
                $this->domainQuery($KeyWord),
                [
                    'offset' => max(0, ((int)$PageNumber - 1) * (int)$PageSize),
                    'size' => max(1, (int)$PageSize),
                ]
            ));

            $list = [];
            foreach (($listData['nsDomains'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $list[] = [
                    'DomainId' => $row['id'] ?? null,
                    'Domain' => $row['name'] ?? '',
                    'RecordCount' => 0,
                ];
            }
            return [
                'total' => (int)($countData['count'] ?? count($list)),
                'list' => $list,
            ];
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        if (!$this->domainId) {
            $this->setError('GoEdge域名ID不能为空');
            return false;
        }

        $hasLocalFilter = !isNullOrEmpty($SubDomain) || !isNullOrEmpty($Value) || !isNullOrEmpty($Status);
        $payload = [
            'nsDomainId' => (int)$this->domainId,
            'offset' => $hasLocalFilter ? 0 : max(0, ((int)$PageNumber - 1) * (int)$PageSize),
            'size' => $hasLocalFilter ? 1000 : max(1, (int)$PageSize),
        ];
        if (!isNullOrEmpty($KeyWord)) $payload['keyword'] = (string)$KeyWord;
        if (!isNullOrEmpty($Type)) $payload['type'] = (string)$Type;
        if (!isNullOrEmpty($Line)) $payload['nsRouteCode'] = $this->firstRouteCode($Line);

        try {
            $data = $this->request('/NSRecordService/listNSRecords', $payload);
            $list = [];
            foreach (($data['nsRecords'] ?? []) as $record) {
                if (!is_array($record)) continue;
                $row = $this->normalizeRecord($record);
                if (!isNullOrEmpty($SubDomain) && strcasecmp((string)$row['Name'], (string)$SubDomain) !== 0) continue;
                if (!isNullOrEmpty($Value) && stripos((string)$row['Value'], (string)$Value) === false) continue;
                if (!isNullOrEmpty($Status) && (string)$row['Status'] !== (string)$Status) continue;
                $list[] = $row;
            }

            $total = count($list);
            if ($hasLocalFilter) {
                $offset = max(0, ((int)$PageNumber - 1) * (int)$PageSize);
                $list = array_slice($list, $offset, max(1, (int)$PageSize));
            }
            return ['total' => $total, 'list' => $list];
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain === '' ? '@' : $SubDomain, null, $Type, $Line);
    }

    public function getDomainRecordInfo($RecordId)
    {
        try {
            $data = $this->request('/NSRecordService/findNSRecord', ['nsRecordId' => (int)$RecordId]);
            if (!isset($data['nsRecord']) || !is_array($data['nsRecord'])) return false;
            return $this->normalizeRecord($data['nsRecord']);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        try {
            $data = $this->request('/NSRecordService/createNSRecord', $this->recordPayload([
                'name' => (string)$Name,
                'type' => (string)$Type,
                'value' => (string)$Value,
                'ttl' => max(1, (int)$TTL),
                'nsRouteCodes' => $this->routeCodes($Line),
                'weight' => $Weight === null ? 0 : (int)$Weight,
                'mxPriority' => $Type === 'MX' ? max(0, (int)$MX) : 0,
                'description' => $Remark === null ? '' : (string)$Remark,
            ]));
            return $data['nsRecordId'] ?? false;
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $current = $this->getDomainRecordInfo($RecordId);
        if (!$current) return false;

        try {
            $this->request('/NSRecordService/updateNSRecord', $this->recordPayload([
                'nsRecordId' => (int)$RecordId,
                'isOn' => (string)$current['Status'] !== '0',
                'name' => (string)$Name,
                'type' => (string)$Type,
                'value' => (string)$Value,
                'ttl' => max(1, (int)$TTL),
                'nsRouteCodes' => $this->routeCodes($Line),
                'weight' => $Weight === null ? (int)$current['Weight'] : (int)$Weight,
                'mxPriority' => $Type === 'MX' ? max(0, (int)$MX) : 0,
                'description' => $Remark === null ? '' : (string)$Remark,
            ]));
            return true;
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        $current = $this->getDomainRecordInfo($RecordId);
        if (!$current) return false;
        return $this->updateRecordFromNormalized($current, ['description' => $Remark === null ? '' : (string)$Remark]);
    }

    public function deleteDomainRecord($RecordId)
    {
        try {
            $this->request('/NSRecordService/deleteNSRecord', ['nsRecordId' => (int)$RecordId]);
            return true;
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    public function setDomainRecordStatus($RecordId, $Status)
    {
        $current = $this->getDomainRecordInfo($RecordId);
        if (!$current) return false;
        return $this->updateRecordFromNormalized($current, ['isOn' => (string)$Status === '1']);
    }

    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    public function getRecordLine()
    {
        $lines = [];
        $methods = [
            '/NSRouteService/findAllDefaultWorldRegionRoutes',
            '/NSRouteService/findAllDefaultChinaProvinceRoutes',
            '/NSRouteService/findAllDefaultISPRoutes',
            '/NSRouteService/findAllAgentNSRoutes',
        ];

        try {
            foreach ($methods as $method) {
                $this->appendRoutes($lines, $this->request($method, []));
            }
            if ($this->domainId) {
                // GoEdge returns the domain's custom/public routes only when this
                // request is an empty object. Filtering by cluster/domain yields
                // an empty response on v1.3.x, leaving record codes like id:48
                // without their display names.
                $this->appendRoutes($lines, $this->request('/NSRouteService/findAllNSRoutes', []));
            }
            // The default route is implicit in GoEdge and should be the first
            // option regardless of the order returned by the API.
            if (isset($lines['default'])) {
                $default = $lines['default'];
                unset($lines['default']);
            } else {
                $default = ['name' => '默认线路', 'parent' => null];
            }
            $lines = ['default' => $default] + $lines;
            $this->inferRouteParents($lines);
            return $lines;
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    public function getMinTTL()
    {
        return 1;
    }

    public function addDomain($Domain)
    {
        try {
            $payload = $this->withUserId([
                'nsClusterId' => $this->nsClusterId,
                'name' => (string)$Domain,
            ]);
            $data = $this->request('/NSDomainService/createNSDomain', $payload);
            if (!isset($data['nsDomainId'])) return false;
            return ['id' => $data['nsDomainId'], 'name' => (string)$Domain];
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    private function getAccessToken()
    {
        $response = http_request(
            $this->baseUrl . '/APIAccessTokenService/getAPIAccessToken',
            json_encode([
                'type' => $this->userType,
                'accessKeyId' => $this->accessKeyId,
                'accessKey' => $this->accessKey,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            null,
            null,
            ['Content-Type' => 'application/json'],
            $this->proxy
        );
        $result = json_decode($response['body'] ?? '', true);
        if ((int)($response['code'] ?? 0) !== 200 || (int)($result['code'] ?? 0) !== 200 || empty($result['data']['token'])) {
            throw new Exception($result['message'] ?? '获取GoEdge AccessToken失败');
        }
        $this->accessToken = (string)$result['data']['token'];
    }

    private function request($path, array $payload)
    {
        if ($this->accessToken === null) $this->getAccessToken();
        // GoEdge protobuf requests are JSON objects, even when they have no fields.
        // json_encode([]) would send [], which the API rejects during decoding.
        $requestPayload = $payload === [] ? new \stdClass() : $payload;
        $body = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) throw new Exception('GoEdge请求数据编码失败');

        $response = http_request(
            $this->baseUrl . $path,
            $body,
            null,
            null,
            [
                'Content-Type' => 'application/json',
                'X-Edge-Access-Token' => $this->accessToken,
            ],
            $this->proxy
        );
        $result = json_decode($response['body'] ?? '', true);
        if ((int)($response['code'] ?? 0) !== 200 || !is_array($result) || (int)($result['code'] ?? 0) !== 200) {
            $message = is_array($result) ? ($result['message'] ?? '') : '';
            throw new Exception($message !== '' ? $message : 'GoEdge API请求失败');
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    private function domainQuery($keyword = null)
    {
        $payload = ['nsClusterId' => $this->nsClusterId];
        if (!isNullOrEmpty($keyword)) $payload['keyword'] = (string)$keyword;
        return $this->withUserId($payload);
    }

    private function withUserId(array $payload)
    {
        if ($this->userType === 'admin' && $this->userId > 0) $payload['userId'] = $this->userId;
        return $payload;
    }

    private function recordPayload(array $payload)
    {
        $payload['nsDomainId'] = (int)$this->domainId;
        return $payload;
    }

    private function normalizeRecord(array $record)
    {
        $routeCodes = [];
        $routeNames = [];
        foreach (($record['nsRoutes'] ?? []) as $route) {
            if (!is_array($route) || empty($route['code'])) continue;
            $routeCodes[] = (string)$route['code'];
            if (!empty($route['name'])) $routeNames[] = (string)$route['name'];
        }
        return [
            'RecordId' => $record['id'] ?? null,
            'Domain' => $this->domain,
            'Name' => $record['name'] ?? '',
            'Type' => $record['type'] ?? '',
            'Value' => $record['value'] ?? '',
            'Line' => $routeCodes ? implode(',', $routeCodes) : 'default',
            'LineName' => $routeNames ? implode(',', $routeNames) : '默认线路',
            'TTL' => (int)($record['ttl'] ?? 0),
            'MX' => (int)($record['mxPriority'] ?? 0),
            'Status' => !empty($record['isOn']) ? '1' : '0',
            'Weight' => (int)($record['weight'] ?? 0),
            'Remark' => $record['description'] ?? '',
            'UpdateTime' => !empty($record['createdAt']) ? date('Y-m-d H:i:s', (int)$record['createdAt']) : null,
        ];
    }

    private function updateRecordFromNormalized(array $current, array $changes)
    {
        $payload = [
            'nsRecordId' => (int)$current['RecordId'],
            'isOn' => (string)$current['Status'] !== '0',
            'name' => (string)$current['Name'],
            'type' => (string)$current['Type'],
            'value' => (string)$current['Value'],
            'ttl' => max(1, (int)$current['TTL']),
            'nsRouteCodes' => $this->routeCodes($current['Line']),
            'weight' => (int)$current['Weight'],
            'mxPriority' => strtoupper((string)$current['Type']) === 'MX' ? (int)$current['MX'] : 0,
            'description' => (string)$current['Remark'],
        ];
        $payload = array_merge($payload, $changes);
        try {
            $this->request('/NSRecordService/updateNSRecord', $payload);
            return true;
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    private function routeCodes($line)
    {
        $lines = is_array($line) ? $line : explode(',', (string)$line);
        $codes = array_values(array_unique(array_filter(array_map('trim', $lines), static fn($item) => $item !== '')));
        return $codes ?: ['default'];
    }

    private function firstRouteCode($line)
    {
        return $this->routeCodes($line)[0];
    }

    private function appendRoutes(array &$lines, array $data)
    {
        foreach (($data['nsRoutes'] ?? []) as $route) {
            if (!is_array($route) || empty($route['code']) || isset($lines[$route['code']])) continue;
            $lines[$route['code']] = [
                'name' => $route['name'] ?? $route['code'],
                'parent' => $route['parent'] ?? null,
            ];
        }
    }

    /**
     * GoEdge uses colon-delimited route codes for nested routes. Only assign a
     * parent when that prefix is actually present in the API response, so this
     * remains compatible with arbitrary built-in and custom route namespaces.
     */
    private function inferRouteParents(array &$lines)
    {
        foreach ($lines as $code => &$line) {
            if (!is_array($line) || ($line['parent'] ?? null) !== null) continue;
            $parts = explode(':', (string)$code);
            while (count($parts) > 1) {
                array_pop($parts);
                $parent = implode(':', $parts);
                if (isset($lines[$parent])) {
                    $line['parent'] = $parent;
                    break;
                }
            }
        }
        unset($line);
    }

    private function setError($message)
    {
        $this->error = (string)$message;
    }
}
