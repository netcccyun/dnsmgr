<?php

namespace app\controller;

use Exception;
use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;
use app\service\EoDnsSyncService;

/**
 * 腾讯云EO 加速域名 → CNAME 自动同步
 */
class Eosync extends BaseController
{
    // 设置页
    public function eoset()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if ($this->request->isPost()) {
            if (!$this->sameOrigin()) return json(['code' => -1, 'msg' => '请求来源校验失败，请刷新页面重试']);
            return $this->saveSetting();
        }
        View::assign('eoSites', $this->siteSummary());
        View::assign('domains', $this->domainList());
        return View::fetch();
    }

    // 预览（只读）
    public function eolist()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        View::assign('domains', $this->domainList());
        return View::fetch();
    }

    public function eolist_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        try {
            $service = new EoDnsSyncService(true);
            $items = $service->buildPlan();
            $filter = (string)input('post.action', '');
            if ($filter !== '') {
                $items = array_values(array_filter($items, function ($v) use ($filter) {
                    return isset($v['action']) && $v['action'] === $filter;
                }));
            }
            $rows = [];
            foreach (array_slice($items, 0, 2000) as $i => $item) {
                $rows[] = [
                    'index'   => $i + 1,
                    'zone'    => isset($item['zone']) ? $item['zone'] : '',
                    'fqdn'    => isset($item['fqdn']) ? $item['fqdn'] : '',
                    'host'    => isset($item['host']) ? $item['host'] : '',
                    'cname'   => isset($item['cname']) ? $item['cname'] : '',
                    'action'  => isset($item['action']) ? $item['action'] : '',
                    'message' => isset($item['message']) ? $item['message'] : '',
                ];
            }
            return json(['total' => count($rows), 'rows' => $rows]);
        } catch (Exception $e) {
            return json(['total' => 0, 'rows' => [], 'msg' => $e->getMessage()]);
        }
    }

    // 执行同步
    public function eorun()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        if (!$this->sameOrigin()) return json(['code' => -1, 'msg' => '请求来源校验失败，请刷新页面重试']);
        try {
            $service = new EoDnsSyncService(false, $this->request->user['id'] ?? null);
            $result = $service->run();
            return json(['code' => $result['stat']['error'] > 0 ? 1 : 0, 'msg' => '同步完成', 'data' => $result['stat']]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function saveSetting()
    {
        $allow = ['eo_sync_enable', 'eo_sync_did', 'eo_sync_ttl', 'eo_sync_line', 'eo_sync_exclude', 'eo_sync_update', 'eo_sync_overwrite', 'eo_sync_min'];
        $intKeys = ['eo_sync_did', 'eo_sync_ttl', 'eo_sync_min'];
        $boolKeys = ['eo_sync_enable', 'eo_sync_update', 'eo_sync_overwrite'];
        $params = input('post.');
        if (!is_array($params)) return json(['code' => -1, 'msg' => '参数错误']);

        $data = [];
        foreach ($params as $key => $value) {
            if (!in_array($key, $allow, true)) continue;
            if (is_array($value)) return json(['code' => -1, 'msg' => $key . ' 参数格式错误']);
            $value = trim((string)$value);
            if (in_array($key, $intKeys, true)) {
                if (!preg_match('/^\d+$/', $value)) return json(['code' => -1, 'msg' => $key . ' 必须是整数']);
                $value = (string)intval($value);
            }
            if (in_array($key, $boolKeys, true) && !in_array($value, ['0', '1'], true)) {
                return json(['code' => -1, 'msg' => $key . ' 取值错误']);
            }
            $data[$key] = $value;
        }
        if (isset($data['eo_sync_min']) && intval($data['eo_sync_min']) < 1) {
            return json(['code' => -1, 'msg' => '同步间隔不能小于 1 分钟']);
        }
        if (isset($data['eo_sync_ttl']) && (intval($data['eo_sync_ttl']) < 60 || intval($data['eo_sync_ttl']) > 604800)) {
            return json(['code' => -1, 'msg' => 'TTL 需在 60~604800 秒之间']);
        }
        if (isset($data['eo_sync_line']) && !in_array($data['eo_sync_line'], ['0', '1'], true)) {
            return json(['code' => -1, 'msg' => '解析线路取值错误']);
        }
        if (isset($data['eo_sync_did']) && intval($data['eo_sync_did']) > 0) {
            $exists = Db::name('domain')->where('id', intval($data['eo_sync_did']))->count();
            if (!$exists) return json(['code' => -1, 'msg' => '指定的目标域名不存在']);
        }
        Db::startTrans();
        try {
            foreach ($data as $key => $value) {
                config_set($key, $value);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            return json(['code' => -1, 'msg' => '保存失败：' . $e->getMessage()]);
        }
        Cache::delete('configs');
        return json(['code' => 0, 'msg' => 'succ']);
    }

    /**
     * 轻量 CSRF 防护：同源校验（Origin 优先，其次 Referer）
     * 该面板未启用 session 中间件，无法依赖框架 token，因此校验来源主机。
     */
    private function sameOrigin()
    {
        $host = strtolower($this->request->host(true));
        $source = $this->request->header('origin');
        if (!$source) $source = $this->request->header('referer');
        if (!$source) return false;
        $parts = parse_url($source);
        if (empty($parts['host'])) return false;
        return strtolower($parts['host']) === $host;
    }

    // 汇总EO站点（设置页展示）
    private function siteSummary()
    {
        try {
            $service = new EoDnsSyncService(true);
            $out = [];
            foreach ($service->fetchEoSites() as $site) {
                $out[] = [
                    'account' => $site['account_name'],
                    'zone' => $site['zone'],
                    'zone_id' => $site['zone_id'],
                    'count' => count($site['domains']),
                    'error' => isset($site['error']) ? $site['error'] : '',
                ];
            }
            return $out;
        } catch (Exception $e) {
            return [['account' => '-', 'zone' => '-', 'zone_id' => '-', 'count' => 0, 'error' => $e->getMessage()]];
        }
    }

    private function domainList()
    {
        $rows = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')
            ->where('B.type', '<>', 'tencenteo')->field('A.id,A.name')->order('A.id', 'asc')->select();
        $list = [];
        foreach ($rows as $row) {
            $list[$row['id']] = $row['name'];
        }
        return $list;
    }
}
