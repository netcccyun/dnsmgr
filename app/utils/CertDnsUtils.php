<?php

namespace app\utils;

use Exception;
use think\facade\Db;
use app\lib\DnsHelper;

class CertDnsUtils
{
    public static function addDns($dnsList, callable $log, $cname = false)
    {
        $cnameDomainList = [];
        foreach ($dnsList as $mainDomain => $list) {
            $drow = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.name', $mainDomain)->field('A.*,B.type')->find();
            if (!$drow) {
                if ($cname) {
                    foreach ($list as $key => $row) {
                        if ($row['name'] == '_acme-challenge') {
                            $domain = $mainDomain;
                        } else {
                            $domain = str_replace('_acme-challenge.', '', $row['name']) . '.' . $mainDomain;
                        }
                        $cname_row = Db::name('cert_cname')->alias('A')->join('domain B', 'A.did = B.id')->where('A.domain', $domain)->field('A.*,B.name cnamedomain')->find();
                        if ($cname_row) {
                            $row['name'] = $cname_row['rr'];
                            $cnameDomainList[$cname_row['cnamedomain']][] = $row;
                            unset($list[$key]);
                        } else {
                            throw new Exception('域名'.$domain.'未在本系统添加');
                        }
                    }
                } else {
                    throw new Exception('域名'.$mainDomain.'未在本系统添加');
                }
            }
            if (empty($list)) continue;
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            usort($list, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            if ($drow['type'] == 'huawei') {
                $list = self::getHuaweiDnsRecords($list);
            }
            $records = [];
            foreach ($list as $row) {
                $domain = $row['name'] . '.' . $mainDomain;
                if (!isset($records[$row['name']])) $records[$row['name']] = $dns->getSubDomainRecords($row['name'], 1, 100);
                if (!$records[$row['name']]) throw new Exception('获取'.$domain.'记录列表失败，'.$dns->getError());

                $filter_records = array_filter($records[$row['name']]['list'], function ($v) use ($row) {
                    if (is_array($v['Value'])) $v['Value'] = implode(',', $v['Value']);
                    return $v['Type'] == $row['type'] && ($v['Value'] == $row['value'] || rtrim($v['Value'], '.') == $row['value']);
                });
                if (!empty($filter_records)) {
                    foreach ($filter_records as $recordid => $record) {
                        unset($records[$row['name']]['list'][$recordid]);
                    }
                    continue;
                }

                $filter_records = array_filter($records[$row['name']]['list'], function ($v) use ($row) {
                    return $v['Type'] == $row['type'];
                });
                if (!empty($filter_records)) {
                    foreach ($filter_records as $recordid => $record) {
                        $dns->deleteDomainRecord($record['RecordId']);
                        unset($records[$row['name']]['list'][$recordid]);
                        $log('Delete DNS Record: '.$domain.' '.$row['type']);
                    }
                }

                $ttl = $drow['type'] == 'namesilo' ? 3600 : 600;
                $res = $dns->addDomainRecord($row['name'], $row['type'], $row['value'], DnsHelper::$line_name[$drow['type']]['DEF'], $ttl);
                if (!$res && $row['type'] != 'CAA') throw new Exception('添加'.$domain.'解析记录失败，' . $dns->getError());
                $log('Add DNS Record: '.$domain.' '.$row['type'].' '.$row['value']);
            }
        }
        if (!empty($cnameDomainList)) {
            self::addDns($cnameDomainList, $log);
        }
    }

    private static function getHuaweiDnsRecords($list)
    {
        //将name相同的TXT记录合并
        $txt_records = [];
        foreach ($list as $key => $row) {
            if ($row['type'] == 'TXT') {
                $txt_records[$row['name']][] = $row['value'];
                unset($list[$key]);
            }
        }
        foreach ($txt_records as $name => $rows) {
            $list[] = ['name' => $name, 'type' => 'TXT', 'value' => '"' . implode('","', $rows) . '"'];
        }
        return $list;
    }

    public static function delDns($dnsList, callable $log, $cname = false)
    {
        $cnameDomainList = [];
        foreach ($dnsList as $mainDomain => $list) {
            $drow = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.name', $mainDomain)->field('A.*,B.type')->find();
            if (!$drow) {
                if ($cname) {
                    foreach ($list as $key => $row) {
                        if ($row['name'] == '_acme-challenge') {
                            $domain = $mainDomain;
                        } else {
                            $domain = str_replace('_acme-challenge.', '', $row['name']) . '.' . $mainDomain;
                        }
                        $cname_row = Db::name('cert_cname')->alias('A')->join('domain B', 'A.did = B.id')->where('A.domain', $domain)->field('A.*,B.name cnamedomain')->find();
                        if ($cname_row) {
                            $row['name'] = $cname_row['rr'];
                            $cnameDomainList[$cname_row['cnamedomain']][] = $row;
                            unset($list[$key]);
                        } else {
                            throw new Exception('域名'.$domain.'未在本系统添加');
                        }
                    }
                } else {
                    throw new Exception('域名'.$mainDomain.'未在本系统添加');
                }
            }
            if (empty($list)) continue;
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            usort($list, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            if ($drow['type'] == 'huawei') {
                $list = self::getHuaweiDnsRecords($list);
            }
            $records = [];
            foreach ($list as $row) {
                //if ($row['type'] == 'CAA') continue;
                $domain = $row['name'] . '.' . $mainDomain;
                if (!isset($records[$row['name']])) $records[$row['name']] = $dns->getSubDomainRecords($row['name'], 1, 100);
                if (!$records[$row['name']]) throw new Exception('获取'.$domain.'记录列表失败，'.$dns->getError());

                $filter_records = array_filter($records[$row['name']]['list'], function ($v) use ($row) {
                    if (is_array($v['Value'])) $v['Value'] = implode(',', $v['Value']);
                    return $v['Type'] == $row['type'] && ($v['Value'] == $row['value'] || rtrim($v['Value'], '.') == $row['value']);
                });
                if (empty($filter_records)) continue;

                foreach ($filter_records as $record) {
                    $dns->deleteDomainRecord($record['RecordId']);
                    $log('Delete DNS Record: '.$domain.' '.$row['type'].' '.$row['value']);
                }
            }
        }
        if (!empty($cnameDomainList)) {
            self::delDns($cnameDomainList, $log);
        }
    }

    public static function verifyDns($dnsList)
    {
        if (empty($dnsList)) return true;
        foreach ($dnsList as $mainDomain => $list) {
            foreach ($list as $row) {
                if ($row['type'] == 'CAA') continue;
                $domain = $row['name'] . '.' . $mainDomain;
                $result = DnsQueryUtils::get_dns_records($domain, $row['type']);
                if (!$result || !in_array($row['value'], $result) && !in_array(strtolower($row['value']), $result)) {
                    $result = DnsQueryUtils::query_dns_doh($domain, $row['type']);
                    if (!$result || !in_array($row['value'], $result) && !in_array(strtolower($row['value']), $result)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }
}
