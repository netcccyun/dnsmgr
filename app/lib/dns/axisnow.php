<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use app\service\AxisNowService;
use Exception;

class axisnow implements DnsInterface
{
    private ?AxisNowService $service = null;
    private ?string $error = null;

    public function __construct(array $config)
    {
        if (trim((string)($config['tenant'] ?? '')) === '') {
            $this->error = '租户名不能为空';
            return;
        }
        try {
            $this->service = new AxisNowService($config);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
        }
    }

    public function getError()
    {
        return $this->error;
    }

    public function check()
    {
        if ($this->service === null) return false;
        try {
            return $this->service->check();
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        return $this->unsupported();
    }

    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        return $this->unsupported();
    }

    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        return $this->unsupported();
    }

    public function getDomainRecordInfo($RecordId)
    {
        return $this->unsupported();
    }

    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        return $this->unsupported();
    }

    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        return $this->unsupported();
    }

    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return $this->unsupported();
    }

    public function deleteDomainRecord($RecordId)
    {
        return $this->unsupported();
    }

    public function setDomainRecordStatus($RecordId, $Status)
    {
        return $this->unsupported();
    }

    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return $this->unsupported();
    }

    public function getRecordLine()
    {
        return ['default' => '默认'];
    }

    public function getMinTTL()
    {
        return 60;
    }

    public function addDomain($Domain)
    {
        return $this->unsupported();
    }

    private function unsupported()
    {
        $this->error = '请在“AxisNow调度”菜单中管理调度域名、规则和 EIP';
        return false;
    }
}
