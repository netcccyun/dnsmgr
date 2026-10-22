<?php

namespace app\controller;

use app\BaseController;
use app\service\CloudflareEnhanceService;
use Exception;
use think\facade\Db;
use think\facade\View;

class Optimizeipbatch extends BaseController
{
    private const MAX_BATCH_SIZE = 500;

    public function index()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');

        $rows = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->where('B.type', 'cloudflare')
            ->field('A.id,A.name,A.thirdid')
            ->order('A.name', 'asc')
            ->select()
            ->toArray();

        $cfDomains = [];
        foreach ($rows as $row) {
            if (trim((string)($row['thirdid'] ?? '')) === '') continue;
            $cfDomains[] = [
                'id' => intval($row['id']),
                'name' => trim((string)$row['name']),
            ];
        }

        View::assign('cfDomains', $cfDomains);
        View::assign('optimizeIpApi', config_get('optimize_ip_api', 0));
        return View::fetch('optimizeip/batch');
    }

    public function pull()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);

        try {
            $context = $this->getCloudflareContext(input('post.cf_domain_id/d'));
            $managedDomains = $this->getManagedDomains();
            $existing = $this->getExistingTaskMap();

            $rows = [];
            foreach ($context['service']->listCustomHostnames($context['domain']['thirdid']) as $item) {
                $hostname = $this->normalizeHostname($item['hostname'] ?? '');
                if ($hostname === '') continue;
                $candidates = $this->findManagedDomainCandidates($managedDomains, $hostname);
                foreach ($candidates as &$candidate) {
                    $taskKey = $candidate['did'] . '|' . $candidate['rr'];
                    $candidate['exists'] = isset($existing[$taskKey]);
                }
                unset($candidate);
                $first = $candidates[0] ?? null;
                $rows[] = [
                    'hostname' => $hostname,
                    'hostname_id' => trim((string)($item['id'] ?? '')),
                    'matched' => !empty($candidates),
                    'candidates' => $candidates,
                    'did' => $first['did'] ?? 0,
                    'rr' => $first['rr'] ?? '',
                    'domain_name' => $first['domain_name'] ?? '',
                    'dns_type' => $first['dns_type'] ?? '',
                    'dns_type_name' => $first['dns_type_name'] ?? '',
                    'exists' => $first['exists'] ?? false,
                ];
            }

            usort($rows, function ($a, $b) {
                return strcmp($a['hostname'], $b['hostname']);
            });

            return json([
                'code' => 0,
                'data' => [
                    'rows' => $rows,
                    'total' => count($rows),
                    'matched' => count(array_filter($rows, function ($row) { return $row['matched']; })),
                ],
            ]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function create()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);

        try {
            $context = $this->getCloudflareContext(input('post.cf_domain_id/d'));
            $hostnames = input('post.hostnames/a', []);
            if (!is_array($hostnames) || empty($hostnames)) {
                throw new Exception('请选择要创建任务的自定义主机名');
            }
            $targetSelections = json_decode((string)input('post.targets', '', 'trim'), true);
            if (!is_array($targetSelections)) $targetSelections = [];
            if (count($hostnames) > self::MAX_BATCH_SIZE) {
                throw new Exception('单次最多创建 ' . self::MAX_BATCH_SIZE . ' 个任务');
            }

            $type = input('post.type/d', 0) === 1 ? 1 : 0;
            $ipTypes = input('post.ip_type/a', ['v4']);
            $ipTypes = array_values(array_intersect(['v4', 'v6'], array_map('strval', (array)$ipTypes)));
            if (empty($ipTypes)) {
                throw new Exception('请至少选择一种解析IP类型');
            }
            if (intval(config_get('optimize_ip_api', 0)) === 2 && in_array('v6', $ipTypes, true)) {
                throw new Exception('当前优选IP接口不支持IPv6');
            }

            $recordnum = input('post.recordnum/d', 2);
            $ttl = input('post.ttl/d', 600);
            if ($recordnum < 1 || $recordnum > 50) {
                throw new Exception('每线路解析数量必须在 1-50 之间');
            }
            if ($ttl < 1 || $ttl > 3600) {
                throw new Exception('TTL 必须在 1-3600 之间');
            }

            $remark = trim((string)input('post.remark', '', 'trim'));
            if ($remark === '') $remark = 'CF自定义主机名批量任务';
            $active = input('post.active/d', 1) === 1 ? 1 : 0;
            $ipType = implode(',', $ipTypes);

            $allowedHostnames = [];
            foreach ($context['service']->listCustomHostnames($context['domain']['thirdid']) as $item) {
                $hostname = $this->normalizeHostname($item['hostname'] ?? '');
                if ($hostname !== '') $allowedHostnames[$hostname] = true;
            }

            $managedDomains = $this->getManagedDomains();
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            $seen = [];

            Db::startTrans();
            try {
                foreach ($hostnames as $hostnameValue) {
                    $hostname = $this->normalizeHostname($hostnameValue);
                    if ($hostname === '' || isset($seen[$hostname])) continue;
                    $seen[$hostname] = true;

                    if (!isset($allowedHostnames[$hostname])) {
                        $skipped++;
                        $errors[] = $hostname . '：不属于所选 Cloudflare 自定义主机名';
                        continue;
                    }

                    $candidates = $this->findManagedDomainCandidates($managedDomains, $hostname);
                    if (empty($candidates)) {
                        $skipped++;
                        $errors[] = $hostname . '：未找到可写入的非 Cloudflare 解析域名';
                        continue;
                    }

                    $selectedDid = intval($targetSelections[$hostname]['did'] ?? 0);
                    $selectedRr = trim((string)($targetSelections[$hostname]['rr'] ?? ''));
                    $match = null;
                    foreach ($candidates as $candidate) {
                        if ($candidate['did'] === $selectedDid && $candidate['rr'] === $selectedRr) {
                            $match = $candidate;
                            break;
                        }
                    }
                    if (!$match) {
                        $skipped++;
                        $errors[] = $hostname . '：请选择有效的解析服务商';
                        continue;
                    }

                    $task = [
                        'did' => $match['did'],
                        'rr' => $match['rr'],
                        'type' => $type,
                        'ip_type' => $ipType,
                        'cdn_type' => 1,
                        'recordnum' => $recordnum,
                        'ttl' => $ttl,
                        'remark' => mb_substr($remark, 0, 100),
                        'active' => $active,
                        'status' => 0,
                        'errmsg' => null,
                    ];

                    $existing = Db::name('optimizeip')
                        ->where('did', $match['did'])
                        ->where('rr', $match['rr'])
                        ->find();
                    if ($existing) {
                        Db::name('optimizeip')->where('id', $existing['id'])->update($task);
                        $updated++;
                    } else {
                        $task['addtime'] = date('Y-m-d H:i:s');
                        Db::name('optimizeip')->insert($task);
                        $created++;
                    }
                }
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                throw $e;
            }

            $msg = '批量任务处理完成：新建 ' . $created . ' 个，更新 ' . $updated . ' 个';
            if ($skipped > 0) $msg .= '，跳过 ' . $skipped . ' 个';
            return json([
                'code' => 0,
                'msg' => $msg,
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ],
            ]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function batch_edit_tasks()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);

        try {
            $ids = input('post.ids/a', []);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids), function ($id) {
                return $id > 0;
            })));
            if (empty($ids)) throw new Exception('请先选择要修改的任务');
            if (count($ids) > 2000) throw new Exception('单次最多修改 2000 个任务');

            $data = [];
            $lineType = trim((string)input('post.line_type', '', 'trim'));
            if ($lineType !== '') {
                if (!in_array($lineType, ['0', '1'], true)) throw new Exception('解析线路类型无效');
                $data['type'] = intval($lineType);
            }

            $cdnType = trim((string)input('post.cdn_type', '', 'trim'));
            if ($cdnType !== '') {
                if (!in_array($cdnType, ['1', '2', '3', '4'], true)) throw new Exception('CDN服务商无效');
                $data['cdn_type'] = intval($cdnType);
            }

            $ipType = trim((string)input('post.ip_type', '', 'trim'));
            if ($ipType !== '') {
                if (!in_array($ipType, ['v4', 'v6', 'v4,v6'], true)) throw new Exception('解析IP类型无效');
                if (intval(config_get('optimize_ip_api', 0)) === 2 && str_contains($ipType, 'v6')) {
                    throw new Exception('当前优选IP接口不支持IPv6');
                }
                $data['ip_type'] = $ipType;
            }

            $recordnum = trim((string)input('post.recordnum', '', 'trim'));
            if ($recordnum !== '') {
                $recordnum = intval($recordnum);
                if ($recordnum < 1 || $recordnum > 50) throw new Exception('每线路解析数量必须在 1-50 之间');
                $data['recordnum'] = $recordnum;
            }

            $ttl = trim((string)input('post.ttl', '', 'trim'));
            if ($ttl !== '') {
                $ttl = intval($ttl);
                if ($ttl < 1 || $ttl > 3600) throw new Exception('TTL 必须在 1-3600 之间');
                $data['ttl'] = $ttl;
            }

            $remarkMode = trim((string)input('post.remark_mode', '', 'trim'));
            if ($remarkMode === 'set') {
                $data['remark'] = mb_substr(trim((string)input('post.remark', '', 'trim')), 0, 100);
            } elseif ($remarkMode === 'clear') {
                $data['remark'] = null;
            } elseif ($remarkMode !== '' && $remarkMode !== 'keep') {
                throw new Exception('备注修改方式无效');
            }

            $active = trim((string)input('post.active', '', 'trim'));
            if ($active !== '') {
                if (!in_array($active, ['0', '1'], true)) throw new Exception('任务启用状态无效');
                $data['active'] = intval($active);
            }

            if (empty($data)) throw new Exception('没有填写需要修改的内容');

            $affected = Db::name('optimizeip')->whereIn('id', $ids)->update($data);
            return json(['code' => 0, 'msg' => '批量修改成功，共更新 ' . intval($affected) . ' 个任务']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function getCloudflareContext(int $domainId): array
    {
        if ($domainId <= 0) throw new Exception('请选择 Cloudflare 自定义主机名所属域名');

        $row = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->where('A.id', $domainId)
            ->field('A.*,B.type,B.config account_config')
            ->find();
        if (!$row) throw new Exception('Cloudflare 域名不存在');
        if (($row['type'] ?? '') !== 'cloudflare') throw new Exception('所选域名不是 Cloudflare 账户');
        if (trim((string)($row['thirdid'] ?? '')) === '') throw new Exception('当前域名缺少 Cloudflare Zone ID');

        $config = json_decode($row['account_config'] ?? '', true);
        if (!is_array($config)) $config = [];

        return [
            'domain' => $row,
            'service' => new CloudflareEnhanceService($config),
        ];
    }

    private function getManagedDomains(): array
    {
        $rows = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->where('B.type', '<>', 'cloudflare')
            ->field('A.id,A.name,A.aid,B.type,B.name account_name')
            ->select()
            ->toArray();

        $domains = [];
        foreach ($rows as $row) {
            $name = $this->normalizeHostname($row['name'] ?? '');
            if ($name === '') continue;
            $dnsType = trim((string)($row['type'] ?? ''));
            $domains[] = [
                'did' => intval($row['id']),
                'domain_name' => $name,
                'dns_type' => $dnsType,
                'dns_type_name' => $this->formatDnsTypeName($dnsType),
                'account_name' => trim((string)($row['account_name'] ?? '')),
            ];
        }
        return $domains;
    }

    private function getExistingTaskMap(): array
    {
        $map = [];
        foreach (Db::name('optimizeip')->field('id,did,rr')->select()->toArray() as $row) {
            $map[intval($row['did']) . '|' . trim((string)$row['rr'])] = intval($row['id']);
        }
        return $map;
    }

    private function findManagedDomainCandidates(array $domains, string $hostname): array
    {
        $matchHostname = preg_replace('/^\*\./', '', $hostname);
        $bestLength = -1;
        $candidates = [];

        foreach ($domains as $domain) {
            $domainName = $domain['domain_name'];
            $rr = null;
            if ($matchHostname === $domainName) {
                $rr = '@';
            } elseif (str_ends_with($matchHostname, '.' . $domainName)) {
                $rr = substr($matchHostname, 0, -strlen($domainName) - 1);
                if (str_starts_with($hostname, '*.')) {
                    $rr = $rr === '@' ? '*' : ('*.' . $rr);
                }
            }
            if ($rr === null) continue;

            $candidate = [
                'did' => $domain['did'],
                'rr' => $rr,
                'domain_name' => $domainName,
                'dns_type' => $domain['dns_type'],
                'dns_type_name' => $domain['dns_type_name'],
                'account_name' => $domain['account_name'],
            ];

            $domainLength = strlen($domainName);
            if ($domainLength > $bestLength) {
                $bestLength = $domainLength;
                $candidates = [$candidate];
            } elseif ($domainLength === $bestLength) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, function ($a, $b) {
            $providerCompare = strcmp($a['dns_type_name'], $b['dns_type_name']);
            if ($providerCompare !== 0) return $providerCompare;
            $accountCompare = strcmp($a['account_name'], $b['account_name']);
            if ($accountCompare !== 0) return $accountCompare;
            return strcmp($a['domain_name'], $b['domain_name']);
        });

        return $candidates;
    }

    private function normalizeHostname($hostname): string
    {
        $hostname = trim((string)$hostname);
        if ($hostname === '') return '';
        $hostname = convertDomainToAscii(rtrim($hostname, '.'));
        return strtolower($hostname);
    }

    private function formatDnsTypeName(string $type): string
    {
        $list = \app\lib\DnsHelper::getList();
        return $list[$type]['name'] ?? ($type !== '' ? $type : '-');
    }
}
