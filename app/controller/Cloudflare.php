<?php

namespace app\controller;

use app\BaseController;
use app\lib\DnsHelper;
use app\service\CloudflareEnhanceService;
use Exception;
use think\facade\Db;
use think\facade\View;

class Cloudflare extends BaseController
{
    public function hostnames()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            View::assign('domainId', $context['domain']['id']);
            View::assign('domainName', $context['domain']['name']);
            return view();
        } catch (Exception $e) {
            return $this->alert('error', $e->getMessage());
        }
    }

    public function hostnames_data()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $rows = [];
            foreach ($context['service']->listCustomHostnames($context['domain']['thirdid']) as $row) {
                $rows[] = $this->formatCustomHostnameRow($row);
            }
            return json(['code' => 0, 'total' => count($rows), 'rows' => $rows]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]);
        }
    }

    public function hostnames_add()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $hostname = trim(input('post.hostname', '', 'trim'));
            $origin = trim(input('post.custom_origin_server', '', 'trim'));
            $sslMethod = trim(input('post.ssl_method', 'txt', 'trim'));
            $minTlsVersion = trim(input('post.min_tls_version', '1.0', 'trim'));
            if (empty($hostname) || !checkDomain($hostname)) {
                throw new Exception('主机名格式不正确');
            }
            if (!in_array($sslMethod, ['txt', 'http'])) {
                throw new Exception('证书验证方法无效');
            }
            if (!in_array($minTlsVersion, ['1.0', '1.1', '1.2', '1.3'])) {
                throw new Exception('最低 TLS 版本无效');
            }
            if ($origin !== '') {
                $this->validateCustomOrigin($origin);
            }

            $result = $context['service']->createCustomHostname($context['domain']['thirdid'], $hostname, $origin !== '' ? $origin : null, $sslMethod, $minTlsVersion);
            $this->add_log($context['domain']['name'], '创建自定义主机名', $hostname . ($origin !== '' ? ' -> ' . $origin : '') . ' (验证: ' . $sslMethod . ', TLS: ' . $minTlsVersion . ')');
            return json(['code' => 0, 'msg' => '创建自定义主机名成功', 'data' => $this->formatCustomHostnameRow($result)]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function hostnames_update()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $hostnameId = trim(input('post.hostname_id', '', 'trim'));
            if ($hostnameId === '') {
                throw new Exception('缺少 hostname_id');
            }

            $current = $context['service']->getCustomHostname($context['domain']['thirdid'], $hostnameId);
            $hostname = trim((string)($current['hostname'] ?? ''));
            $origin = trim(input('post.custom_origin_server', '', 'trim'));
            $sslMethod = trim(input('post.ssl_method', 'txt', 'trim'));
            $minTlsVersion = trim(input('post.min_tls_version', '1.0', 'trim'));
            if (!in_array($sslMethod, ['txt', 'http'])) {
                throw new Exception('证书验证方法无效');
            }
            if (!in_array($minTlsVersion, ['1.0', '1.1', '1.2', '1.3'])) {
                throw new Exception('最低 TLS 版本无效');
            }
            if ($origin !== '') {
                $this->validateCustomOrigin($origin);
            }

            $result = $context['service']->updateCustomHostname(
                $context['domain']['thirdid'],
                $hostnameId,
                [
                    'custom_origin_server' => $origin !== '' ? $origin : null,
                    'ssl' => $this->extractCustomHostnameSslPayload($current, $sslMethod, $minTlsVersion),
                ]
            );
            $this->add_log($context['domain']['name'], '编辑自定义主机名', $hostname . ' -> ' . ($origin !== '' ? $origin : '清空源站') . ' (验证: ' . $sslMethod . ', TLS: ' . $minTlsVersion . ')');
            return json(['code' => 0, 'msg' => '更新自定义主机名成功', 'data' => $this->formatCustomHostnameRow($result)]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function hostnames_refresh()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $hostnameId = trim(input('post.hostname_id', '', 'trim'));
            if ($hostnameId === '') {
                throw new Exception('缺少 hostname_id');
            }

            $current = $context['service']->getCustomHostname($context['domain']['thirdid'], $hostnameId);
            $hostname = trim((string)($current['hostname'] ?? $hostnameId));
            $origin = trim((string)($current['custom_origin_server'] ?? ''));
            $result = $context['service']->updateCustomHostname(
                $context['domain']['thirdid'],
                $hostnameId,
                [
                    'custom_origin_server' => $origin !== '' ? $origin : null,
                    'ssl' => $this->extractCustomHostnameSslPayload($current),
                ]
            );
            $this->add_log($context['domain']['name'], '刷新自定义主机名验证', $hostname);
            return json(['code' => 0, 'msg' => '已向 Cloudflare 重新发起验证', 'data' => $this->formatCustomHostnameRow($result)]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function hostnames_delete()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $hostnameId = trim(input('post.hostname_id', '', 'trim'));
            $hostname = trim(input('post.hostname', '', 'trim'));
            if ($hostnameId === '') {
                throw new Exception('缺少 hostname_id');
            }
            $context['service']->deleteCustomHostname($context['domain']['thirdid'], $hostnameId);
            $this->add_log($context['domain']['name'], '删除自定义主机名', $hostname);
            return json(['code' => 0, 'msg' => '删除自定义主机名成功']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function hostnames_batch_delete()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $hostnameIds = input('post.hostname_ids/a', []);
            if (empty($hostnameIds)) {
                throw new Exception('缺少 hostname_ids');
            }
            
            $deletedCount = 0;
            foreach ($hostnameIds as $hostnameId) {
                if (trim((string)$hostnameId) !== '') {
                    try {
                        // 获取主机名信息用于日志
                        $hostnameInfo = $context['service']->getCustomHostname($context['domain']['thirdid'], trim((string)$hostnameId));
                        $hostname = trim((string)($hostnameInfo['hostname'] ?? ''));
                        
                        $context['service']->deleteCustomHostname($context['domain']['thirdid'], trim((string)$hostnameId));
                        $deletedCount++;
                        // 为每个成功删除的主机名记录单独的日志
                        $this->add_log($context['domain']['name'], '批量删除自定义主机名', $hostname);
                    } catch (Exception $e) {
                        // 忽略删除失败的情况，继续处理其他主机名
                    }
                }
            }
            
            return json(['code' => 0, 'msg' => '批量删除成功，共删除 ' . $deletedCount . ' 个自定义主机名']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function hostnames_batch_update()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $hostnameIds = input('post.hostname_ids/s', '');
            $hostnameIdArray = array_filter(array_map('trim', explode(',', $hostnameIds)));
            if (empty($hostnameIdArray)) {
                throw new Exception('缺少 hostname_ids');
            }
            
            $origin = trim(input('post.custom_origin_server', '', 'trim'));
            $sslMethod = trim(input('post.ssl_method', '', 'trim'));
            $minTlsVersion = trim(input('post.min_tls_version', '', 'trim'));
            
            if (!empty($sslMethod) && !in_array($sslMethod, ['txt', 'http'])) {
                throw new Exception('证书验证方法无效');
            }
            if (!empty($minTlsVersion) && !in_array($minTlsVersion, ['1.0', '1.1', '1.2', '1.3'])) {
                throw new Exception('最低 TLS 版本无效');
            }
            if ($origin !== '') {
                $this->validateCustomOrigin($origin);
            }
            
            $updatedCount = 0;
            foreach ($hostnameIdArray as $hostnameId) {
                if (trim((string)$hostnameId) !== '') {
                    try {
                        $current = $context['service']->getCustomHostname($context['domain']['thirdid'], $hostnameId);
                        $hostname = trim((string)($current['hostname'] ?? ''));
                        $payload = [];
                        
                        // 总是设置 custom_origin_server，留空时设置为 null 表示清空
                        $payload['custom_origin_server'] = $origin !== '' ? $origin : null;
                        
                        if (!empty($sslMethod) || !empty($minTlsVersion)) {
                            $payload['ssl'] = $this->extractCustomHostnameSslPayload($current, $sslMethod, $minTlsVersion);
                        }
                        
                        if (!empty($payload)) {
                            $context['service']->updateCustomHostname($context['domain']['thirdid'], $hostnameId, $payload);
                            $updatedCount++;
                            // 为每个成功修改的主机名记录单独的日志
                            $logMessage = $hostname . ' -> ' . ($origin !== '' ? $origin : '清空源站') . ' (验证: ' . ($sslMethod ?: '保持不变') . ', TLS: ' . ($minTlsVersion ?: '保持不变') . ')';
                            $this->add_log($context['domain']['name'], '批量修改自定义主机名', $logMessage);
                        }
                    } catch (Exception $e) {
                        // 忽略修改失败的情况，继续处理其他主机名
                    }
                }
            }
            
            return json(['code' => 0, 'msg' => '批量修改成功，共修改 ' . $updatedCount . ' 个自定义主机名']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function hostnames_batch_add()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $hostnamesText = trim(input('post.hostnames', '', 'trim'));
            $origin = trim(input('post.custom_origin_server', '', 'trim'));
            $sslMethod = trim(input('post.ssl_method', 'txt', 'trim'));
            $minTlsVersion = trim(input('post.min_tls_version', '1.0', 'trim'));
            
            if (empty($hostnamesText)) {
                throw new Exception('缺少主机名列表');
            }
            if (!in_array($sslMethod, ['txt', 'http'])) {
                throw new Exception('证书验证方法无效');
            }
            if (!in_array($minTlsVersion, ['1.0', '1.1', '1.2', '1.3'])) {
                throw new Exception('最低 TLS 版本无效');
            }
            if ($origin !== '') {
                $this->validateCustomOrigin($origin);
            }
            
            $hostnames = array_filter(array_map('trim', explode("\n", $hostnamesText)));
            if (empty($hostnames)) {
                throw new Exception('主机名列表为空');
            }
            
            $addedCount = 0;
            $failedHostnames = [];
            foreach ($hostnames as $hostname) {
                if (empty($hostname)) {
                    continue;
                }
                if (!checkDomain($hostname)) {
                    $failedHostnames[] = $hostname . '（格式不正确）';
                    continue;
                }
                try {
                    $context['service']->createCustomHostname(
                        $context['domain']['thirdid'],
                        $hostname,
                        $origin !== '' ? $origin : null,
                        $sslMethod,
                        $minTlsVersion
                    );
                    $addedCount++;
                    // 为每个成功添加的主机名记录单独的日志
                    $logMessage = $hostname . ($origin !== '' ? ' -> ' . $origin : '') . ' (验证: ' . $sslMethod . ', TLS: ' . $minTlsVersion . ')';
                    $this->add_log($context['domain']['name'], '批量添加自定义主机名', $logMessage);
                } catch (Exception $e) {
                    $failedHostnames[] = $hostname . '（' . $e->getMessage() . '）';
                }
            }
            
            $message = '批量添加成功，共添加 ' . $addedCount . ' 个自定义主机名';
            if (!empty($failedHostnames)) {
                $message .= '，失败 ' . count($failedHostnames) . ' 个：' . implode('; ', $failedHostnames);
            }
            
            return json(['code' => 0, 'msg' => $message]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function hostnames_txt_targets()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $hostname = trim(input('post.hostname', '', 'trim'));
            if ($hostname === '') {
                throw new Exception('缺少 TXT 主机名');
            }

            return json([
                'code' => 0,
                'data' => [
                    'hostname' => $hostname,
                    'candidates' => $this->findTxtRecordTargetDomains($context['domain'], $hostname),
                ],
            ]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'data' => ['candidates' => []]]);
        }
    }

    public function fallback_get()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $origin = $context['service']->getFallbackOrigin($context['domain']['thirdid']);
            return json(['code' => 0, 'data' => ['origin' => $origin]]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function fallback_set()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $origin = trim(input('post.origin', '', 'trim'));
            if ($origin === '') {
                throw new Exception('Fallback Origin 不能为空');
            }
            $this->validateCustomOrigin($origin);

            $savedOrigin = $context['service']->updateFallbackOrigin($context['domain']['thirdid'], $origin);
            $this->add_log($context['domain']['name'], '更新 Fallback Origin', $savedOrigin);
            return json(['code' => 0, 'msg' => '更新 Fallback Origin 成功', 'data' => ['origin' => $savedOrigin]]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function fallback_delete()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $context['service']->deleteFallbackOrigin($context['domain']['thirdid']);
            $this->add_log($context['domain']['name'], '删除 Fallback Origin', '清空成功');
            return json(['code' => 0, 'msg' => '已清空 Fallback Origin']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function dcv_delegation_uuid()
    {
        try {
            $context = $this->getCloudflareDomainContext(input('param.id/d'));
            $uuid = $context['service']->getDcvDelegationUuid($context['domain']['thirdid']);
            return json(['code' => 0, 'data' => ['uuid' => $uuid]]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function get_domain_default_line()
    {
        try {
            $domainId = input('param.domain_id/d');
            if (empty($domainId)) {
                throw new Exception('缺少 domain_id 参数');
            }

            // 查询域名信息
            $domainRow = Db::name('domain')->alias('A')
                ->join('account B', 'A.aid = B.id')
                ->where('A.id', $domainId)
                ->field('A.*, B.type, B.config account_config')
                ->find();

            if (!$domainRow) {
                throw new Exception('域名不存在');
            }

            // 获取该域名的默认线路
            $recordLine = cache('record_line_' . $domainId);
            
            if (empty($recordLine)) {
                // 缓存中没有，需要从 DNS 提供商获取
                $config = json_decode($domainRow['account_config'] ?? '', true);
                if (!is_array($config)) {
                    $config = [];
                }

                $dnsModel = \app\lib\DnsHelper::getModel(
                    intval($domainRow['aid']),
                    $domainRow['name'],
                    $domainRow['thirdid'],
                    $domainRow['type'],
                    $config
                );

                if ($dnsModel && method_exists($dnsModel, 'getRecordLine')) {
                    $recordLine = $dnsModel->getRecordLine();
                    if ($recordLine && is_array($recordLine)) {
                        cache('record_line_' . $domainId, $recordLine, 604800); // 缓存7天
                    }
                }
            }

            if (empty($recordLine) || !is_array($recordLine)) {
                throw new Exception('无法获取该域名的解析线路列表');
            }

            $firstKey = array_key_first($recordLine);
            if ($firstKey === null) {
                throw new Exception('解析线路列表为空');
            }

            $lines = [];
            foreach ($recordLine as $lineValue => $lineLabel) {
                if (is_array($lineLabel)) {
                    $lines[] = [
                        'value' => strval($lineValue),
                        'label' => isset($lineLabel['name']) ? strval($lineLabel['name']) : strval($lineValue),
                        'parent' => isset($lineLabel['parent']) ? ($lineLabel['parent'] !== null ? strval($lineLabel['parent']) : '') : '',
                        'is_default' => ($lineValue === $firstKey)
                    ];
                } else {
                    $lines[] = [
                        'value' => strval($lineValue),
                        'label' => strval($lineLabel),
                        'parent' => '',
                        'is_default' => ($lineValue === $firstKey)
                    ];
                }
            }

            return json(['code' => 0, 'data' => ['default_line' => strval($firstKey), 'lines' => $lines]]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            View::assign('accountId', $context['account']['id']);
            View::assign('accountName', $this->formatAccountDisplayName($context['account']));
            View::assign('cfAccountId', $context['accountId']);
            return view();
        } catch (Exception $e) {
            return $this->alert('error', $e->getMessage());
        }
    }

    public function tunnels_data()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $rows = [];
            foreach ($context['service']->listTunnels($context['accountId']) as $row) {
                $rows[] = $this->formatTunnelRow($row);
            }
            return json(['code' => 0, 'total' => count($rows), 'rows' => $rows, 'account_id' => $context['accountId']]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]);
        }
    }

    public function tunnels_add()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $name = trim(input('post.name', '', 'trim'));
            if ($name === '') {
                throw new Exception('Tunnel 名称不能为空');
            }
            $tunnel = $context['service']->createTunnel($context['accountId'], $name);
            $this->add_log($this->formatAccountDisplayName($context['account']), '创建 Tunnel', $name . ' [' . ($tunnel['id'] ?? '-') . ']');
            return json(['code' => 0, 'msg' => '创建 Tunnel 成功', 'data' => $this->formatTunnelRow($tunnel)]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels_delete()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            if ($tunnelId === '') {
                throw new Exception('缺少 tunnel_id');
            }
            $context['service']->deleteTunnel($context['accountId'], $tunnelId);
            $this->add_log($this->formatAccountDisplayName($context['account']), '删除 Tunnel', $tunnelId);
            return json(['code' => 0, 'msg' => '删除 Tunnel 成功']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels_token()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            if ($tunnelId === '') {
                throw new Exception('缺少 tunnel_id');
            }
            $token = $context['service']->getTunnelToken($context['accountId'], $tunnelId);
            return json(['code' => 0, 'data' => ['token' => $token]]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels_public_hostnames_data()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            if ($tunnelId === '') {
                throw new Exception('缺少 tunnel_id');
            }
            $config = $this->extractTunnelConfigObject($context['service']->getTunnelConfig($context['accountId'], $tunnelId));
            $rows = [];
            foreach ($this->extractPublicHostnames($config) as $row) {
                $zone = $this->findBestMatchingDomain(intval($context['account']['id']), $row['hostname']);
                $row['zone_name'] = $zone['name'] ?? '';
                $row['zone_id'] = $zone['thirdid'] ?? '';
                $rows[] = $row;
            }
            return json(['code' => 0, 'total' => count($rows), 'rows' => $rows]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]);
        }
    }

    public function tunnels_public_hostnames_save()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            $hostname = trim(input('post.hostname', '', 'trim'));
            $serviceValue = trim(input('post.service', '', 'trim'));
            $path = trim(input('post.path', '', 'trim'));
            if ($tunnelId === '' || $hostname === '' || $serviceValue === '') {
                throw new Exception('Tunnel、主机名、服务地址不能为空');
            }
            if (!checkDomain($hostname)) {
                throw new Exception('主机名格式不正确');
            }

            $zone = $this->findBestMatchingDomain(intval($context['account']['id']), $hostname);
            if (empty($zone) || empty($zone['thirdid'])) {
                throw new Exception('未找到匹配的本地域名，请先在当前 Cloudflare 账户下导入该主机名所属主域');
            }

            $config = $this->extractTunnelConfigObject($context['service']->getTunnelConfig($context['accountId'], $tunnelId));
            $oldConfig = json_decode(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
            $ingress = isset($config['ingress']) && is_array($config['ingress']) ? array_values($config['ingress']) : [];
            $rule = [
                'hostname' => $hostname,
                'service' => $serviceValue,
            ];
            if ($path !== '') {
                $rule['path'] = $path;
            }

            $existingIndex = $this->findPublicHostnameIndex($ingress, $hostname, $path);
            if ($existingIndex >= 0) {
                $next = array_merge($ingress[$existingIndex], $rule);
                if ($path === '' && isset($next['path'])) {
                    unset($next['path']);
                }
                $ingress[$existingIndex] = $next;
            } else {
                $fallbackIndex = $this->findFallbackIngressIndex($ingress);
                if ($fallbackIndex >= 0) {
                    array_splice($ingress, $fallbackIndex, 0, [$rule]);
                } else {
                    $ingress[] = $rule;
                }
            }

            $config['ingress'] = $this->ensureFallbackIngress($ingress);
            $context['service']->updateTunnelConfig($context['accountId'], $tunnelId, $config);

            try {
                $dns = $context['service']->upsertTunnelCnameRecord($zone['thirdid'], $hostname, $tunnelId);
            } catch (Exception $e) {
                $context['service']->updateTunnelConfig($context['accountId'], $tunnelId, $oldConfig);
                throw new Exception('Public Hostname 已回滚：' . $e->getMessage());
            }

            $this->add_log($this->formatAccountDisplayName($context['account']), '配置 Tunnel 公网主机名', $hostname . ' -> ' . $serviceValue . ' [' . ($dns['action'] ?? '-') . ']');
            return json(['code' => 0, 'msg' => '配置 Public Hostname 成功']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels_public_hostnames_delete()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            $hostname = trim(input('post.hostname', '', 'trim'));
            $path = trim(input('post.path', '', 'trim'));
            if ($tunnelId === '' || $hostname === '') {
                throw new Exception('缺少 tunnel_id 或 hostname');
            }

            $config = $this->extractTunnelConfigObject($context['service']->getTunnelConfig($context['accountId'], $tunnelId));
            $oldConfig = json_decode(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
            $ingress = isset($config['ingress']) && is_array($config['ingress']) ? array_values($config['ingress']) : [];
            $nextIngress = [];
            foreach ($ingress as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $match = $this->normalizeHostname($row['hostname'] ?? '') === $this->normalizeHostname($hostname)
                    && trim((string)($row['path'] ?? '')) === $path;
                if (!$match) {
                    $nextIngress[] = $row;
                }
            }

            $config['ingress'] = $this->ensureFallbackIngress($nextIngress);
            $context['service']->updateTunnelConfig($context['accountId'], $tunnelId, $config);

            $zone = $this->findBestMatchingDomain(intval($context['account']['id']), $hostname);
            if (!empty($zone['thirdid'])) {
                try {
                    $context['service']->deleteTunnelCnameRecordIfMatch($zone['thirdid'], $hostname, $tunnelId);
                } catch (Exception $e) {
                    $context['service']->updateTunnelConfig($context['accountId'], $tunnelId, $oldConfig);
                    throw new Exception('删除 Public Hostname 时已回滚：' . $e->getMessage());
                }
            }

            $this->add_log($this->formatAccountDisplayName($context['account']), '删除 Tunnel 公网主机名', $hostname . ($path !== '' ? ' [' . $path . ']' : ''));
            return json(['code' => 0, 'msg' => '删除 Public Hostname 成功']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels_cidr_data()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            if ($tunnelId === '') {
                throw new Exception('缺少 tunnel_id');
            }
            $rows = [];
            foreach ($context['service']->listCidrRoutes($context['accountId'], $tunnelId) as $row) {
                $mapped = $this->formatCidrRouteRow($row);
                if ($mapped['id'] !== '' && $mapped['network'] !== '') {
                    $rows[] = $mapped;
                }
            }
            return json(['code' => 0, 'total' => count($rows), 'rows' => $rows]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]);
        }
    }

    public function tunnels_cidr_add()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            $network = trim(input('post.network', '', 'trim'));
            $comment = trim(input('post.comment', '', 'trim'));
            if ($tunnelId === '' || $network === '') {
                throw new Exception('Tunnel 和 CIDR 不能为空');
            }
            if (!$this->isValidCidr($network)) {
                throw new Exception('CIDR 格式不正确');
            }

            $route = $context['service']->createCidrRoute($context['accountId'], $tunnelId, $network, $comment !== '' ? $comment : null);
            $mapped = $this->formatCidrRouteRow($route);
            $this->add_log($this->formatAccountDisplayName($context['account']), '创建 Tunnel CIDR 路由', $mapped['network']);
            return json(['code' => 0, 'msg' => '创建 CIDR 路由成功', 'data' => $mapped]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels_cidr_delete()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            $routeId = trim(input('post.route_id', '', 'trim'));
            if ($tunnelId === '' || $routeId === '') {
                throw new Exception('缺少 tunnel_id 或 route_id');
            }

            $matched = false;
            foreach ($context['service']->listCidrRoutes($context['accountId'], $tunnelId) as $row) {
                if (trim((string)($row['id'] ?? '')) === $routeId) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new Exception('CIDR 路由不存在或不属于当前 Tunnel');
            }

            $context['service']->deleteCidrRoute($context['accountId'], $routeId);
            $this->add_log($this->formatAccountDisplayName($context['account']), '删除 Tunnel CIDR 路由', $routeId);
            return json(['code' => 0, 'msg' => '删除 CIDR 路由成功']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels_hostname_routes_data()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            if ($tunnelId === '') {
                throw new Exception('缺少 tunnel_id');
            }
            $rows = [];
            foreach ($context['service']->listHostnameRoutes($context['accountId'], $tunnelId) as $row) {
                $mapped = $this->formatHostnameRouteRow($row);
                if ($mapped['id'] !== '' && $mapped['hostname'] !== '') {
                    $rows[] = $mapped;
                }
            }
            return json(['code' => 0, 'total' => count($rows), 'rows' => $rows]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]);
        }
    }

    public function tunnels_hostname_routes_add()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            $hostname = trim(input('post.hostname', '', 'trim'));
            $comment = trim(input('post.comment', '', 'trim'));
            if ($tunnelId === '' || $hostname === '') {
                throw new Exception('Tunnel 和主机名不能为空');
            }
            if (!checkDomain($hostname)) {
                throw new Exception('主机名格式不正确');
            }

            $route = $context['service']->createHostnameRoute($context['accountId'], $tunnelId, $hostname, $comment !== '' ? $comment : null);
            $mapped = $this->formatHostnameRouteRow($route);
            $this->add_log($this->formatAccountDisplayName($context['account']), '创建 Tunnel 主机名路由', $mapped['hostname']);
            return json(['code' => 0, 'msg' => '创建主机名路由成功', 'data' => $mapped]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tunnels_hostname_routes_delete()
    {
        try {
            $context = $this->getCloudflareAccountContext(input('param.id/d'), true, true);
            $tunnelId = trim(input('post.tunnel_id', '', 'trim'));
            $routeId = trim(input('post.route_id', '', 'trim'));
            if ($tunnelId === '' || $routeId === '') {
                throw new Exception('缺少 tunnel_id 或 route_id');
            }

            $matched = false;
            foreach ($context['service']->listHostnameRoutes($context['accountId'], $tunnelId) as $row) {
                $id = trim((string)($row['id'] ?? $row['hostname_route_id'] ?? ''));
                if ($id === $routeId) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new Exception('主机名路由不存在或不属于当前 Tunnel');
            }

            $context['service']->deleteHostnameRoute($context['accountId'], $routeId);
            $this->add_log($this->formatAccountDisplayName($context['account']), '删除 Tunnel 主机名路由', $routeId);
            return json(['code' => 0, 'msg' => '删除主机名路由成功']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function getCloudflareDomainContext(int $domainId): array
    {
        if (!checkPermission(2)) {
            throw new Exception('无权限');
        }
        $row = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->where('A.id', $domainId)
            ->field('A.*,B.type,B.config account_config,B.name account_name,B.remark account_remark')
            ->find();
        if (!$row) {
            throw new Exception('域名不存在');
        }
        if (($row['type'] ?? '') !== 'cloudflare') {
            throw new Exception('仅支持 Cloudflare 域名');
        }
        if (empty($row['thirdid'])) {
            throw new Exception('当前域名缺少 Cloudflare Zone ID');
        }

        $config = json_decode($row['account_config'] ?? '', true);
        if (!is_array($config)) {
            $config = [];
        }

        return [
            'domain' => $row,
            'config' => $config,
            'service' => new CloudflareEnhanceService($config),
        ];
    }

    private function getCloudflareAccountContext(int $accountId, bool $requireAccountId = false, bool $requireTunnelApiToken = false): array
    {
        if (!checkPermission(2)) {
            throw new Exception('无权限');
        }
        $account = Db::name('account')->where('id', $accountId)->find();
        if (!$account) {
            throw new Exception('域名账户不存在');
        }
        if (($account['type'] ?? '') !== 'cloudflare') {
            throw new Exception('仅支持 Cloudflare 账户');
        }

        $config = json_decode($account['config'] ?? '', true);
        if (!is_array($config)) {
            $config = [];
        }

        $service = new CloudflareEnhanceService($config);
        if ($requireTunnelApiToken && !$service->isApiTokenAuth()) {
            throw new Exception('Cloudflare Tunnels 仅支持 API 令牌认证，请将当前账户的认证方式切换为 API令牌');
        }

        $resolvedAccountId = trim((string)($config['account_id'] ?? ''));
        if ($requireAccountId && $resolvedAccountId === '') {
            $resolvedAccountId = $service->getDefaultAccountId();
            if ($resolvedAccountId !== '') {
                $config['account_id'] = $resolvedAccountId;
                Db::name('account')->where('id', $account['id'])->update([
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $account['config'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $service = new CloudflareEnhanceService($config);
            }
        }
        if ($requireAccountId && $resolvedAccountId === '') {
            throw new Exception('当前 Cloudflare 账户缺少 Account ID，且无法自动探测。请编辑账户并补充 Account ID 后重试');
        }

        return [
            'account' => $account,
            'config' => $config,
            'service' => $service,
            'accountId' => $resolvedAccountId,
        ];
    }

    private function validateCustomOrigin(string $origin): void
    {
        if (preg_match('/^https?:\/\//i', $origin)) {
            throw new Exception('自定义源站不支持填写 http:// 或 https://');
        }
        if (str_contains($origin, '*')) {
            throw new Exception('自定义源站不支持通配符');
        }
        if (str_contains($origin, '/')) {
            throw new Exception('自定义源站格式不正确');
        }
        if (preg_match('/:\d+$/', $origin)) {
            throw new Exception('自定义源站不支持端口');
        }
        if (filter_var($origin, FILTER_VALIDATE_IP)) {
            throw new Exception('自定义源站不支持 IP 地址，请填写域名');
        }
        if (!checkDomain($origin)) {
            throw new Exception('自定义源站格式不正确');
        }
    }

    private function extractCustomHostnameSslPayload(array $row, string $sslMethod = '', string $minTlsVersion = ''): array
    {
        $ssl = isset($row['ssl']) && is_array($row['ssl']) ? $row['ssl'] : [];
        $payload = [
            'method' => $sslMethod !== '' ? $sslMethod : trim((string)($ssl['method'] ?? 'http')),
            'type' => trim((string)($ssl['type'] ?? 'dv')),
        ];
        if ($payload['method'] === '') {
            $payload['method'] = 'http';
        }
        if ($payload['type'] === '') {
            $payload['type'] = 'dv';
        }
        
        // 添加 TLS 版本设置
        if ($minTlsVersion !== '') {
            $payload['settings'] = [
                'min_tls_version' => $minTlsVersion
            ];
        } elseif (isset($ssl['settings']) && is_array($ssl['settings'])) {
            $payload['settings'] = $ssl['settings'];
        }
        
        return $payload;
    }

    private function formatCustomHostnameRow(array $row): array
    {
        $ssl = isset($row['ssl']) && is_array($row['ssl']) ? $row['ssl'] : [];
        $ownership = isset($row['ownership_verification']) && is_array($row['ownership_verification']) ? $row['ownership_verification'] : [];
        $ownershipHttp = isset($row['ownership_verification_http']) && is_array($row['ownership_verification_http']) ? $row['ownership_verification_http'] : [];
        $verificationStatus = trim((string)($ownership['http']['status'] ?? $ownership['txt']['status'] ?? $ownership['status'] ?? ''));
        if ($verificationStatus === '' && (
            trim((string)($ownership['name'] ?? '')) !== ''
            || trim((string)($ownership['value'] ?? '')) !== ''
            || trim((string)($ownershipHttp['http_url'] ?? '')) !== ''
            || trim((string)($ownershipHttp['http_body'] ?? '')) !== ''
        )) {
            $verificationStatus = 'pending';
        }

        $validationErrors = [];
        if (!empty($row['verification_errors']) && is_array($row['verification_errors'])) {
            foreach ($row['verification_errors'] as $item) {
                $message = trim((string)($item['message'] ?? $item));
                if ($message !== '') {
                    $validationErrors[] = $message;
                }
            }
        }
        if (!empty($ssl['validation_errors']) && is_array($ssl['validation_errors'])) {
            foreach ($ssl['validation_errors'] as $item) {
                $message = trim((string)($item['message'] ?? $item));
                if ($message !== '') {
                    $validationErrors[] = $message;
                }
            }
        }

        $sslValidationRecords = [];
        if (!empty($ssl['validation_records']) && is_array($ssl['validation_records'])) {
            foreach ($ssl['validation_records'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $sslValidationRecords[] = [
                    'status' => trim((string)($item['status'] ?? '')),
                    'txt_name' => trim((string)($item['txt_name'] ?? '')),
                    'txt_value' => trim((string)($item['txt_value'] ?? '')),
                    'cname_name' => trim((string)($item['cname_name'] ?? '')),
                    'cname_target' => trim((string)($item['cname_target'] ?? '')),
                    'http_url' => trim((string)($item['http_url'] ?? '')),
                    'http_body' => trim((string)($item['http_body'] ?? '')),
                    'emails' => !empty($item['emails']) && is_array($item['emails']) ? array_values(array_filter(array_map('strval', $item['emails']))) : [],
                ];
            }
        }
        if (empty($sslValidationRecords) && (
            trim((string)($ssl['txt_name'] ?? '')) !== ''
            || trim((string)($ssl['txt_value'] ?? '')) !== ''
            || trim((string)($ssl['cname_name'] ?? '')) !== ''
            || trim((string)($ssl['cname_target'] ?? '')) !== ''
            || trim((string)($ssl['http_url'] ?? '')) !== ''
            || trim((string)($ssl['http_body'] ?? '')) !== ''
        )) {
            $sslValidationRecords[] = [
                'status' => trim((string)($ssl['status'] ?? '')),
                'txt_name' => trim((string)($ssl['txt_name'] ?? '')),
                'txt_value' => trim((string)($ssl['txt_value'] ?? '')),
                'cname_name' => trim((string)($ssl['cname_name'] ?? '')),
                'cname_target' => trim((string)($ssl['cname_target'] ?? '')),
                'http_url' => trim((string)($ssl['http_url'] ?? '')),
                'http_body' => trim((string)($ssl['http_body'] ?? '')),
                'emails' => [],
            ];
        }

        $sslValidationStatuses = [];
        foreach ($sslValidationRecords as $item) {
            $status = trim((string)($item['status'] ?? ''));
            if ($status !== '') {
                $sslValidationStatuses[] = $status;
            }
        }
        $sslValidationStatuses = array_values(array_unique(array_filter($sslValidationStatuses)));
        $sslValidationStatus = count($sslValidationStatuses) > 0 ? implode(' / ', $sslValidationStatuses) : trim((string)($ssl['status'] ?? ''));
        if ($sslValidationStatus === '') {
            $sslValidationStatus = '-';
        }

        return [
            'id' => trim((string)($row['id'] ?? '')),
            'hostname' => trim((string)($row['hostname'] ?? '')),
            'custom_origin_server' => trim((string)($row['custom_origin_server'] ?? '')),
            'status' => trim((string)($row['status'] ?? '')),
            'ssl' => $ssl,
            'ssl_status' => trim((string)($ssl['status'] ?? '')),
            'ssl_method' => trim((string)($ssl['method'] ?? '')),
            'ssl_min_tls_version' => trim((string)($ssl['settings']['min_tls_version'] ?? '')),
            'ssl_type' => trim((string)($ssl['type'] ?? '')),
            'ssl_validation_status' => $sslValidationStatus,
            'verification_status' => $verificationStatus !== '' ? $verificationStatus : '-',
            'created_on' => trim((string)($row['created_at'] ?? $row['created_on'] ?? '')),
            'validation_errors' => implode(' | ', array_values(array_unique(array_filter($validationErrors)))),
            'ownership_verification' => [
                'type' => trim((string)($ownership['type'] ?? '')),
                'name' => trim((string)($ownership['name'] ?? '')),
                'value' => trim((string)($ownership['value'] ?? '')),
                'status' => $verificationStatus !== '' ? $verificationStatus : '-',
            ],
            'ownership_verification_http' => [
                'http_url' => trim((string)($ownershipHttp['http_url'] ?? '')),
                'http_body' => trim((string)($ownershipHttp['http_body'] ?? '')),
            ],
            'ssl_validation_records' => $sslValidationRecords,
        ];
    }

    private function formatTunnelRow(array $row): array
    {
        $connections = isset($row['connections']) && is_array($row['connections']) ? array_values($row['connections']) : [];
        return [
            'id' => trim((string)($row['id'] ?? '')),
            'name' => trim((string)($row['name'] ?? '')),
            'status' => trim((string)($row['status'] ?? 'unknown')),
            'created_at' => trim((string)($row['created_at'] ?? '')),
            'deleted_at' => trim((string)($row['deleted_at'] ?? '')),
            'conns_active_at' => trim((string)($row['conns_active_at'] ?? '')),
            'connection_count' => count($connections),
            'connections' => $connections,
        ];
    }

    private function formatCidrRouteRow(array $row): array
    {
        return [
            'id' => trim((string)($row['id'] ?? '')),
            'network' => trim((string)($row['network'] ?? '')),
            'comment' => trim((string)($row['comment'] ?? '')),
            'virtual_network_id' => trim((string)($row['virtual_network_id'] ?? '')),
            'tunnel_id' => trim((string)($row['tunnel_id'] ?? '')),
            'created_at' => trim((string)($row['created_at'] ?? '')),
        ];
    }

    private function formatHostnameRouteRow(array $row): array
    {
        return [
            'id' => trim((string)($row['id'] ?? $row['hostname_route_id'] ?? '')),
            'hostname' => trim((string)($row['hostname'] ?? $row['hostname_pattern'] ?? '')),
            'comment' => trim((string)($row['comment'] ?? '')),
            'tunnel_id' => trim((string)($row['tunnel_id'] ?? '')),
            'created_at' => trim((string)($row['created_at'] ?? '')),
        ];
    }

    private function findTxtRecordTargetDomains(array $currentDomain, string $hostname): array
    {
        $rows = Db::name('domain')->alias('D')
            ->join('account A', 'D.aid = A.id')
            ->field('D.id,D.aid,D.name,A.type account_type,A.name account_name,A.remark account_remark')
            ->select()
            ->toArray();

        $candidates = [];
        $bestLength = -1;
        foreach ($rows as $row) {
            $recordName = $this->matchHostnameToDomainRecordName($hostname, $row['name'] ?? '');
            if ($recordName === null) {
                continue;
            }
            $domainName = $this->normalizeHostname($row['name'] ?? '');
            $matchedLength = strlen($domainName);
            if ($matchedLength > $bestLength) {
                $bestLength = $matchedLength;
                $candidates = [];
            }
            if ($matchedLength === $bestLength) {
                $candidates[] = $this->formatTxtTargetCandidate($row, $recordName, intval($currentDomain['id'] ?? 0));
            }
        }

        if (empty($candidates)) {
            $fallbackRecordName = $this->matchHostnameToDomainRecordName($hostname, $currentDomain['name'] ?? '', true);
            if ($fallbackRecordName !== null) {
                $candidates[] = $this->formatTxtTargetCandidate([
                    'id' => $currentDomain['id'] ?? 0,
                    'aid' => $currentDomain['aid'] ?? 0,
                    'name' => $currentDomain['name'] ?? '',
                    'account_type' => $currentDomain['type'] ?? '',
                    'account_name' => $currentDomain['account_name'] ?? '',
                    'account_remark' => $currentDomain['account_remark'] ?? '',
                ], $fallbackRecordName, intval($currentDomain['id'] ?? 0));
            }
        }

        usort($candidates, function ($a, $b) {
            if ($a['is_current_domain'] !== $b['is_current_domain']) {
                return $a['is_current_domain'] ? -1 : 1;
            }
            $providerCompare = strcmp($a['account_type_name'], $b['account_type_name']);
            if ($providerCompare !== 0) {
                return $providerCompare;
            }
            $accountCompare = strcmp($a['account_display_name'], $b['account_display_name']);
            if ($accountCompare !== 0) {
                return $accountCompare;
            }
            return strcmp($a['domain_name'], $b['domain_name']);
        });

        return $candidates;
    }

    private function formatTxtTargetCandidate(array $row, string $recordName, int $currentDomainId): array
    {
        $account = [
            'id' => intval($row['aid'] ?? 0),
            'name' => trim((string)($row['account_name'] ?? '')),
            'remark' => trim((string)($row['account_remark'] ?? '')),
        ];
        $accountType = trim((string)($row['account_type'] ?? ''));

        return [
            'domain_id' => intval($row['id'] ?? 0),
            'domain_name' => trim((string)($row['name'] ?? '')),
            'record_name' => $recordName,
            'account_id' => $account['id'],
            'account_type' => $accountType,
            'account_type_name' => $this->formatDnsTypeName($accountType),
            'account_display_name' => $this->formatAccountDisplayName($account),
            'is_current_domain' => intval($row['id'] ?? 0) === $currentDomainId,
        ];
    }

    private function formatAccountDisplayName(array $account): string
    {
        $name = trim((string)($account['name'] ?? ''));
        $remark = trim((string)($account['remark'] ?? ''));
        if ($remark !== '') {
            return $remark . ' (' . $name . ')';
        }
        return $name !== '' ? $name : ('Cloudflare账户#' . ($account['id'] ?? ''));
    }

    private function extractTunnelConfigObject(array $raw): array
    {
        if (isset($raw['config']) && is_array($raw['config'])) {
            return $raw['config'];
        }
        return $raw;
    }

    private function extractPublicHostnames(array $config): array
    {
        $rows = [];
        $ingress = isset($config['ingress']) && is_array($config['ingress']) ? array_values($config['ingress']) : [];
        foreach ($ingress as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $hostname = trim((string)($rule['hostname'] ?? ''));
            if ($hostname === '') {
                continue;
            }
            $rows[] = [
                'hostname' => $hostname,
                'path' => trim((string)($rule['path'] ?? '')),
                'service' => trim((string)($rule['service'] ?? '')),
            ];
        }
        return $rows;
    }

    private function ensureFallbackIngress(array $ingress): array
    {
        $rows = [];
        foreach ($ingress as $rule) {
            if (is_array($rule)) {
                $rows[] = $rule;
            }
        }
        if (empty($rows) || !$this->isFallbackIngressRule($rows[count($rows) - 1])) {
            $rows[] = ['service' => 'http_status:404'];
        }
        return $rows;
    }

    private function isFallbackIngressRule(array $rule): bool
    {
        return trim((string)($rule['hostname'] ?? '')) === '' && trim((string)($rule['path'] ?? '')) === '';
    }

    private function findFallbackIngressIndex(array $ingress): int
    {
        foreach ($ingress as $index => $rule) {
            if (is_array($rule) && $this->isFallbackIngressRule($rule)) {
                return $index;
            }
        }
        return -1;
    }

    private function findPublicHostnameIndex(array $ingress, string $hostname, string $path): int
    {
        foreach ($ingress as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $sameHostname = $this->normalizeHostname($rule['hostname'] ?? '') === $this->normalizeHostname($hostname);
            $samePath = trim((string)($rule['path'] ?? '')) === trim($path);
            if ($sameHostname && $samePath) {
                return $index;
            }
        }
        return -1;
    }

    private function findBestMatchingDomain(int $accountId, string $hostname): ?array
    {
        $hostname = preg_replace('/^\*\./', '', $this->normalizeHostname($hostname));
        $domains = Db::name('domain')->where('aid', $accountId)->select()->toArray();
        $best = null;
        $bestLength = -1;
        foreach ($domains as $domain) {
            $domainName = $this->normalizeHostname($domain['name'] ?? '');
            if ($domainName === '') {
                continue;
            }
            if ($this->matchHostnameToDomainRecordName($hostname, $domainName) !== null && strlen($domainName) > $bestLength) {
                $best = $domain;
                $bestLength = strlen($domainName);
            }
        }
        return $best;
    }

    private function matchHostnameToDomainRecordName(string $hostname, string $domainName, bool $allowRelative = false): ?string
    {
        $hostname = preg_replace('/^\*\./', '', $this->normalizeHostname($hostname));
        $domainName = $this->normalizeHostname($domainName);
        if ($hostname === '' || $domainName === '') {
            return null;
        }
        if ($hostname === $domainName) {
            return '@';
        }
        if (str_ends_with($hostname, '.' . $domainName)) {
            return substr($hostname, 0, -strlen($domainName) - 1);
        }
        if ($allowRelative) {
            if ($hostname === '@') {
                return '@';
            }
            if (!str_contains($hostname, '.')) {
                return $hostname;
            }
        }
        return null;
    }

    private function formatDnsTypeName(string $type): string
    {
        $dnsList = DnsHelper::getList();
        return $dnsList[$type]['name'] ?? ($type !== '' ? $type : '-');
    }

    private function normalizeHostname($hostname): string
    {
        $hostname = trim((string)$hostname);
        if ($hostname === '') {
            return '';
        }
        $hostname = convertDomainToAscii(rtrim($hostname, '.'));
        return strtolower($hostname);
    }

    private function isValidCidr(string $network): bool
    {
        if (!str_contains($network, '/')) {
            return false;
        }
        [$ip, $prefix] = explode('/', $network, 2);
        if (!is_numeric($prefix)) {
            return false;
        }
        $prefix = intval($prefix);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $prefix >= 0 && $prefix <= 32;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $prefix >= 0 && $prefix <= 128;
        }
        return false;
    }

    private function add_log(string $domain, string $action, string $data): void
    {
        if (strlen($data) > 500) {
            $data = substr($data, 0, 500);
        }
        Db::name('log')->insert([
            'uid' => request()->user['id'],
            'domain' => $domain,
            'action' => $action,
            'data' => $data,
            'addtime' => date('Y-m-d H:i:s'),
        ]);
    }
}
