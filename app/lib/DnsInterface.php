<?php

namespace app\lib;

interface DnsInterface
{
    public function getError();

    public function check();

    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20);

    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = '', $SubDomain = '', $Value = '', $Type = '', $Line = '', $Status = '');

    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = '', $Line = '');

    public function getDomainRecordInfo($RecordId);

    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null);

    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null);

    public function updateDomainRecordRemark($RecordId, $Remark);

    public function deleteDomainRecord($RecordId);

    public function setDomainRecordStatus($RecordId, $Status);

    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null);

    public function getRecordLine();

    public function getMinTTL();

}
