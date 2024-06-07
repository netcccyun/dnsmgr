<?php

namespace app\lib;

interface DnsInterface
{
    function getError();

    function check();

    function getDomainList($KeyWord=null, $PageNumber=1, $PageSize=20);

    function getDomainRecords($PageNumber=1, $PageSize=20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null);

    function getSubDomainRecords($SubDomain, $PageNumber=1, $PageSize=20, $Type = null, $Line = null);

    function getDomainRecordInfo($RecordId);

    function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Remark = null);

    function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Remark = null);

    function updateDomainRecordRemark($RecordId, $Remark);

    function deleteDomainRecord($RecordId);

    function setDomainRecordStatus($RecordId, $Status);

    function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null);

    function getRecordLine();

    function getMinTTL();

}