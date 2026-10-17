<?php

namespace app\service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\facade\Cache;
use ZipArchive;

/**
 * 在线更新服务
 *
 * 从 GitHub Release 下载最新安装包并覆盖更新，更新前自动备份历史版本，并保留配置文件。
 * 注意：本功能代码位于 app/ 内，被官方包覆盖更新后会失效（属预期行为）。
 */
class UpdateService
{
    /** 默认 GitHub 仓库 */
    const DEFAULT_REPO = 'netcccyun/dnsmgr';

    /**
     * 更新时需要保留的文件/目录（相对项目根目录，统一使用 / 分隔）
     *
     * runtime 下仅保留 log（框架日志与证书任务日志）与 backup（历史版本备份），
     * 其余（cache/temp/session/cert、@login.lock 等临时数据）随更新清空后自动重建。
     *
     * @return array
     */
    public static function keepPaths(): array
    {
        return [
            '.env',
            'public/static/css/custom.css',
            'public/static/js/custom.js',
            'runtime/log',
            'runtime/backup',
        ];
    }

    /**
     * 覆盖过程中额外需要保护的路径（不对外展示）
     *
     * - runtime/update        存放本次更新的安装包、解压目录与 task.json 进度文件，必须保留到更新结束
     * - runtime/.gitignore    仓库自带文件，无论安装包是否携带都不应被删除
     *
     * @return array
     */
    private static function transientSkip(): array
    {
        return array_merge(self::keepPaths(), ['runtime/update', 'runtime/.gitignore']);
    }

    /**
     * 获取当前已安装的 release 版本号
     *
     * @return string
     */
    public static function getCurrentRelease(): string
    {
        $v = config_get('release_version');
        if (isNullOrEmpty($v)) {
            $v = config('app.release');
        }
        return (string)$v;
    }

    /**
     * 获取完整版本号文本，例如：V2.21 (Build 1052)
     *
     * @return string
     */
    public static function getVersionText(): string
    {
        $text = 'V' . ltrim(self::getCurrentRelease(), 'vV');
        $build = (string)config('app.version');
        if ($build !== '') {
            $text .= ' (Build ' . $build . ')';
        }
        return $text;
    }

    /**
     * 获取指定 release 的 GitHub 页面地址
     *
     * @param string|null $release 留空表示当前版本
     * @return string
     */
    public static function releaseUrl(?string $release = null): string
    {
        $release = $release === null ? self::getCurrentRelease() : $release;
        return 'https://github.com/netcccyun/dnsmgr/releases/tag/' . rawurlencode(ltrim($release, 'vV'));
    }

    /**
     * 比较两个版本号（语义化，忽略 v 前缀与后缀）
     *
     * @param string $a
     * @param string $b
     * @return int $a > $b 返回 1，相等返回 0，$a < $b 返回 -1
     */
    public static function compareVersion(string $a, string $b): int
    {
        $normalize = function ($v) {
            $v = ltrim(trim((string)$v), 'vV');
            $v = preg_replace('/[^0-9.].*$/', '', $v);
            $parts = array_values(array_filter(explode('.', $v), fn($x) => $x !== ''));
            return $parts ? array_map('intval', $parts) : [0];
        };
        $a = $normalize($a);
        $b = $normalize($b);
        $len = max(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $x = $a[$i] ?? 0;
            $y = $b[$i] ?? 0;
            if ($x > $y) return 1;
            if ($x < $y) return -1;
        }
        return 0;
    }

    /**
     * 项目根目录（带结尾分隔符）
     *
     * @return string
     */
    public function rootPath(): string
    {
        return rtrim(app()->getRootPath(), '\\/') . DIRECTORY_SEPARATOR;
    }

    /**
     * 更新临时目录
     *
     * @return string
     */
    public function tmpDir(): string
    {
        return $this->rootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR;
    }

    /**
     * 历史版本备份目录
     *
     * @return string
     */
    public function backupDir(): string
    {
        return $this->rootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
    }

    public function getRepo(): string
    {
        $repo = trim((string)config_get('update_repo', self::DEFAULT_REPO));
        return $repo === '' ? self::DEFAULT_REPO : $repo;
    }

    public function getMirror(): string
    {
        return trim((string)config_get('update_mirror', ''));
    }

    public function getCheckSha(): bool
    {
        return (string)config_get('update_check_sha', '1') === '1';
    }

    /**
     * 给 URL 加上加速镜像前缀
     *
     * @param string $url
     * @return string
     */
    private function withMirror(string $url): string
    {
        $mirror = $this->getMirror();
        if ($mirror === '') return $url;
        return rtrim($mirror, '/') . '/' . $url;
    }

    /**
     * 解析 GitHub asset digest 字段，返回 sha256（无则返回空串）
     *
     * @param mixed $digest
     * @return string
     */
    private function parseDigest($digest): string
    {
        $digest = trim((string)$digest);
        if ($digest === '') return '';
        if (stripos($digest, 'sha256:') === 0) {
            return strtolower(substr($digest, 7));
        }
        return preg_match('/^[a-f0-9]{64}$/i', $digest) ? strtolower($digest) : '';
    }

    /**
     * 查询 GitHub 最新 Release 信息
     *
     * @param bool $useCache 是否使用缓存
     * @return array
     * @throws Exception
     */
    public function checkLatest(bool $useCache = true): array
    {
        $cacheKey = 'update_latest_release_' . md5($this->getRepo());
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached['tag'])) {
                return $cached;
            }
        }

        $url = 'https://api.github.com/repos/' . $this->getRepo() . '/releases/latest';
        $url = $this->withMirror($url);

        $useProxy = !isNullOrEmpty(config_get('proxy_server')) && intval(config_get('proxy_port')) > 0;
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'dnsmgr-updater',
        ];

        // 允许最多 3 次重定向（部分加速镜像会 302 跳转）
        $res = null;
        for ($i = 0; $i < 3; $i++) {
            $res = http_request($url, null, null, null, $headers, $useProxy, 'GET', 15);
            if ($res['code'] >= 300 && $res['code'] < 400 && !empty($res['redirect_url'])) {
                $url = $res['redirect_url'];
                continue;
            }
            break;
        }

        if ($res['code'] != 200) {
            throw new Exception('获取版本信息失败（HTTP ' . $res['code'] . '）');
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data) || empty($data['tag_name'])) {
            throw new Exception('解析版本信息失败');
        }

        $asset = null;
        foreach ($data['assets'] ?? [] as $item) {
            $name = $item['name'] ?? '';
            if (preg_match('/\.zip$/i', $name) && stripos($name, 'dnsmgr') !== false) {
                $asset = $item;
                break;
            }
        }
        if (!$asset) {
            foreach ($data['assets'] ?? [] as $item) {
                if (preg_match('/\.zip$/i', $item['name'] ?? '')) {
                    $asset = $item;
                    break;
                }
            }
        }
        if (!$asset) {
            throw new Exception('未找到可下载的安装包');
        }

        $info = [
            'tag'          => (string)$data['tag_name'],
            'name'         => (string)($data['name'] ?? $data['tag_name']),
            'body'         => (string)($data['body'] ?? ''),
            'published_at' => (string)($data['published_at'] ?? ''),
            'html_url'    => (string)($data['html_url'] ?? ''),
            'asset'        => [
                'name'   => (string)$asset['name'],
                'size'   => (int)($asset['size'] ?? 0),
                'url'    => (string)($asset['browser_download_url'] ?? ''),
                'sha256' => $this->parseDigest($asset['digest'] ?? ''),
            ],
        ];

        Cache::set($cacheKey, $info, 3600);
        return $info;
    }

    /**
     * 检查是否存在新版本
     *
     * @param bool $useCache
     * @return array
     * @throws Exception
     */
    public function check(bool $useCache = true): array
    {
        $latest = $this->checkLatest($useCache);
        $current = self::getCurrentRelease();
        $hasUpdate = self::compareVersion($latest['tag'], $current) > 0;
        return [
            'current'      => $current,
            'current_text' => self::getVersionText(),
            'latest'       => ltrim((string)$latest['tag'], 'vV'),
            'latest_text'  => 'V' . ltrim((string)$latest['tag'], 'vV'),
            'latest_name'  => $latest['name'],
            'has_update'   => $hasUpdate,
            'published_at' => $latest['published_at'],
            'html_url'     => self::releaseUrl($latest['tag']),
            'body'         => $latest['body'],
            'asset'        => $latest['asset'],
        ];
    }

    /**
     * 检查写入权限，返回不可写的目录列表
     *
     * @return array
     */
    public function checkWritable(): array
    {
        $root = $this->rootPath();
        $targets = [
            $root . 'app',
            $root . 'config',
            $root . 'public',
            $root . 'route',
            $root . 'vendor',
            $root . 'runtime',
        ];
        $bad = [];
        foreach ($targets as $t) {
            if (is_dir($t) && !is_writable($t)) {
                $bad[] = str_replace('\\', '/', substr($t, strlen($root)));
            }
        }
        return $bad;
    }

    /**
     * 列出历史版本备份
     *
     * @return array
     */
    public function listBackups(): array
    {
        $dir = $this->backupDir();
        if (!is_dir($dir)) return [];
        $list = [];
        foreach (glob($dir . '*.zip') ?: [] as $file) {
            $list[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'time' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }
        usort($list, fn($a, $b) => strcmp($b['time'], $a['time']));
        return $list;
    }

    /**
     * 创建更新任务，返回 token（不执行更新）
     *
     * @return string
     */
    public function createTask(): string
    {
        $token = md5(random(16) . microtime(true));
        $this->setProgress([
            'token'   => $token,
            'status'  => 'pending',
            'percent' => 0,
            'step'    => 'init',
            'message' => '任务已创建，准备开始…',
            'error'   => '',
            'from'    => self::getCurrentRelease(),
            'to'      => '',
        ]);
        return $token;
    }

    /**
     * 尝试在后台启动更新进程
     *
     * @param string $token
     * @return bool 成功启动返回 true
     */
    public function launchBackground(string $token): bool
    {
        $php = PHP_BINARY;
        if (empty($php) || !is_file($php)) {
            return false;
        }
        $think = $this->rootPath() . 'think';
        if (!is_file($think)) {
            return false;
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($think) . ' update:run ' . escapeshellarg($token);
        $disabled = $this->disabledFunctions();
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if (function_exists('popen') && !in_array('popen', $disabled)) {
            $run = $isWin ? 'start /B ' . $cmd . ' > NUL 2>&1' : $cmd . ' > /dev/null 2>&1 &';
            $h = @popen($run, 'r');
            if ($h !== false) {
                @pclose($h);
                return true;
            }
        }
        if (function_exists('exec') && !in_array('exec', $disabled)) {
            if ($isWin) {
                @exec('start /B ' . $cmd . ' > NUL 2>&1');
            } else {
                @exec($cmd . ' > /dev/null 2>&1 &');
            }
            return true;
        }
        if (function_exists('proc_open') && !in_array('proc_open', $disabled)) {
            $desc = $isWin
                ? ['file', 'NUL', 'w']
                : ['file', '/dev/null', 'w'];
            $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => $desc, 2 => $desc], $pipes);
            if (is_resource($p)) {
                @proc_close($p);
                return true;
            }
        }
        return false;
    }

    private function disabledFunctions(): array
    {
        $list = (string)ini_get('disable_functions');
        return $list === '' ? [] : array_map('trim', explode(',', strtolower($list)));
    }

    /**
     * 执行更新主流程（同步，供后台进程或同步回退调用）
     *
     * @param string $token
     * @return void
     */
    public function runTask(string $token): void
    {
        if (function_exists('set_time_limit')) @set_time_limit(0);
        if (function_exists('ignore_user_abort')) @ignore_user_abort(true);

        $backupFile = '';
        try {
            $this->step($token, 'check', 5, '正在检查最新版本…');

            if (!class_exists('ZipArchive')) {
                throw new Exception('服务器未安装 zip 扩展，无法解压更新包');
            }

            $bad = $this->checkWritable();
            if (!empty($bad)) {
                throw new Exception('以下目录无写入权限：' . implode('、', $bad) . '；Docker/只读环境请挂载可写目录后重试');
            }

            $latest = $this->checkLatest(false);
            $to = $latest['tag'];
            $this->setProgress($this->mergeProgress($token, ['to' => $to]));

            $tmp = $this->tmpDir();
            $this->ensureDir($tmp);
            $extractDir = $tmp . 'extract';
            $this->removePathRecursive($extractDir);
            $package = $tmp . 'package.zip';
            if (is_file($package)) @unlink($package);

            // 下载
            $this->step($token, 'download', 10, '正在下载安装包…');
            $dlUrl = $this->withMirror($latest['asset']['url']);
            $this->download($dlUrl, $package);
            $this->step($token, 'download', 45, '安装包下载完成');

            // 校验
            if ($this->getCheckSha() && !empty($latest['asset']['sha256'])) {
                $this->step($token, 'verify', 50, '正在校验安装包完整性…');
                $sha = hash_file('sha256', $package);
                if (!hash_equals($latest['asset']['sha256'], strtolower($sha))) {
                    throw new Exception('安装包校验失败（sha256 不匹配），已中止更新');
                }
            }

            // 备份
            $this->step($token, 'backup', 55, '正在备份当前版本…');
            $backupFile = $this->backup($token);
            $this->step($token, 'backup', 70, '已备份到 ' . basename($backupFile));
            // 解压
            $this->step($token, 'extract', 75, '正在解压安装包…');
            $this->extract($package, $extractDir);
            $entries = $this->topEntries($extractDir);
            if (empty($entries)) {
                throw new Exception('安装包内容为空，已中止更新');
            }

            // 覆盖（逐个顶层条目：先删除旧文件，再复制新文件）
            $this->step($token, 'copy', 80, '正在替换程序文件…');
            $root = $this->rootPath();
            $skip = self::transientSkip();
            $total = count($entries);
            $i = 0;
            foreach ($entries as $name) {
                $this->removePathRecursive($root . $name, $skip, $root);
                $this->copyPath($extractDir . DIRECTORY_SEPARATOR . $name, $root . $name, $skip, $root);
                $i++;
                $this->step($token, 'copy', 80 + (int)round($i / $total * 15), '正在替换程序文件…（' . $name . '）');
            }
            $this->step($token, 'copy', 95, '程序文件已更新');

            // Linux 下恢复 think 可执行权限
            if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && is_file($root . 'think')) {
                @chmod($root . 'think', 0755);
            }

            // 清理 runtime 下除日志与历史备份之外的内容（缓存等会自动重建）
            $this->step($token, 'copy', 97, '正在清理运行缓存…');
            $this->cleanRuntime();

            // 写入版本号
            config_set('release_version', $to);
            Cache::delete('configs');
            Cache::delete('update_latest_release_' . md5($this->getRepo()));

            // 清理
            $this->removePathRecursive($extractDir);
            if (is_file($package)) @unlink($package);

            $this->step($token, 'finish', 100, '更新完成', 'success');
        } catch (\Throwable $e) {
            // 覆盖过程中失败：尝试用备份自动恢复
            $restoreMsg = '';
            if ($backupFile !== '' && is_file($backupFile)) {
                try {
                    $this->step($token, 'restore', 0, '更新失败，正在尝试自动恢复…');
                    $this->restoreFrom($backupFile);
                    $restoreMsg = '；已尝试从备份自动恢复';
                } catch (\Throwable $e2) {
                    $restoreMsg = '；自动恢复失败：' . $e2->getMessage();
                }
            }
            $this->setProgress($this->mergeProgress($token, [
                'status'  => 'failed',
                'error'   => $e->getMessage() . $restoreMsg,
                'message' => '更新失败：' . $e->getMessage() . $restoreMsg,
            ]));
        }
    }

    /**
     * 从备份 zip 恢复文件（仅覆盖备份中包含的条目）
     *
     * @param string $zipFile
     * @return void
     * @throws Exception
     */
    private function restoreFrom(string $zipFile): void
    {
        if (!is_file($zipFile)) {
            throw new Exception('备份文件不存在');
        }
        $root = $this->rootPath();
        $tmp = $this->tmpDir() . 'restore';
        $this->removePathRecursive($tmp);
        $this->extract($zipFile, $tmp);
        $entries = $this->topEntries($tmp);
        $skip = self::transientSkip();
        foreach ($entries as $name) {
            $this->removePathRecursive($root . $name, $skip, $root);
            $this->copyPath($tmp . DIRECTORY_SEPARATOR . $name, $root . $name, $skip, $root);
        }
        $this->removePathRecursive($tmp);
    }

    /**
     * 下载文件
     *
     * @param string $url
     * @param string $file
     * @return void
     * @throws Exception
     */
    private function download(string $url, string $file): void
    {
        $options = [
            'sink'            => $file,
            'timeout'         => 600,
            'connect_timeout' => 15,
            'verify'          => false,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            ],
        ];
        $this->applyProxy($options);
        try {
            $client = new Client();
            $res = $client->request('GET', $url, $options);
            $code = $res->getStatusCode();
        } catch (GuzzleException $e) {
            throw new Exception('下载失败：' . guzzle_error($e));
        }
        if ($code != 200) {
            throw new Exception('下载失败，HTTP ' . $code);
        }
        if (!is_file($file) || filesize($file) < 10240) {
            throw new Exception('下载的安装包无效');
        }
    }

    /**
     * 将代理配置写入 Guzzle options
     *
     * @param array $options
     * @return void
     */
    private function applyProxy(array &$options): void
    {
        $server = config_get('proxy_server');
        $port = intval(config_get('proxy_port'));
        if (isNullOrEmpty($server) || empty($port)) return;
        $userpwd = config_get('proxy_user') . ':' . config_get('proxy_pwd');
        $scheme = match ((string)config_get('proxy_type')) {
            'https' => 'https',
            'sock4' => 'socks4',
            'sock5' => 'socks5',
            'sock5h' => 'socks5h',
            default => 'http',
        };
        $str = $scheme . '://';
        if ($userpwd !== ':') {
            $str .= $userpwd . '@';
        }
        $str .= $server . ':' . $port;
        $options['proxy'] = $str;
    }

    /**
     * 备份当前代码为历史版本 zip
     *
     * @param string $token
     * @return string 备份文件完整路径
     * @throws Exception
     */
    private function backup(string $token): string
    {
        $root = $this->rootPath();
        $backupDir = $this->backupDir();
        $this->ensureDir($backupDir);

        $current = self::getCurrentRelease();
        $build = (string)config('app.version');
        $file = $backupDir . 'dnsmgr_' . $current . '_' . $build . '_' . date('YmdHis') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('无法创建备份文件，请检查 runtime/backup 目录写入权限');
        }

        // 需要备份的顶层条目（与官方包结构一致）
        $entries = [
            'app', 'config', 'public', 'route', 'vendor',
            'composer.json', 'composer.lock', 'think',
            'LICENSE', 'README.md', '.example.env',
            '.gitignore', '.gitattributes',
        ];
        // 备份时跳过用户保留文件
        $skip = self::keepPaths();
        $added = 0;
        foreach ($entries as $name) {
            $path = $root . $name;
            if (!file_exists($path)) continue;
            $added += $this->zipAddPath($zip, $path, $name, $skip, $root);
        }
        $zip->close();

        if ($added === 0) {
            @unlink($file);
            throw new Exception('备份内容为空，已中止更新');
        }
        return $file;
    }

    /**
     * 递归将路径加入 zip
     *
     * @param ZipArchive $zip
     * @param string $path 磁盘路径
     * @param string $rel 相对 zip 根目录的路径
     * @param array $skip 跳过的相对路径
     * @param string $root
     * @return int 加入的文件数
     */
    private function zipAddPath(ZipArchive $zip, string $path, string $rel, array $skip, string $root): int
    {
        $rel = str_replace('\\', '/', $rel);
        $rel = trim($rel, '/');
        if ($this->isProtected($rel, $skip)) return 0;

        if (is_file($path)) {
            $zip->addFile($path, $rel);
            return 1;
        }
        if (!is_dir($path)) return 0;

        $count = 0;
        $items = @scandir($path) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $count += $this->zipAddPath($zip, $path . DIRECTORY_SEPARATOR . $it, $rel . '/' . $it, $skip, $root);
        }
        return $count;
    }

    /**
     * 解压安装包
     *
     * @param string $package
     * @param string $target
     * @return void
     * @throws Exception
     */
    private function extract(string $package, string $target): void
    {
        $this->ensureDir($target);
        $zip = new ZipArchive();
        if ($zip->open($package) !== true) {
            throw new Exception('无法打开安装包');
        }
        if (!$zip->extractTo($target)) {
            $zip->close();
            throw new Exception('解压安装包失败');
        }
        $zip->close();
    }

    /**
     * 获取解压目录的顶层条目名
     *
     * @param string $dir
     * @return array
     */
    private function topEntries(string $dir): array
    {
        $items = @scandir($dir) ?: [];
        $result = [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $result[] = $it;
        }
        return $result;
    }

    /**
     * 判断相对路径是否受保护（等于或位于受保护路径之下）
     *
     * @param string $rel
     * @param array $skip
     * @return bool
     */
    private function isProtected(string $rel, array $skip): bool
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel === '') return false;
        foreach ($skip as $s) {
            $s = trim(str_replace('\\', '/', $s), '/');
            if ($s === '') continue;
            if ($rel === $s || str_starts_with($rel, $s . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * 相对化路径
     *
     * @param string $path
     * @param string $root
     * @return string
     */
    private function rel(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }
        return $path;
    }

    /**
     * 递归删除路径（跳过受保护路径）
     *
     * @param string $path
     * @param array $skip
     * @param string $root
     * @return void
     */
    private function removePathRecursive(string $path, array $skip = [], string $root = ''): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if ($root !== '' && $this->isProtected($this->rel($path, $root), $skip)) return;

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) return;

        $items = @scandir($path) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $this->removePathRecursive($path . DIRECTORY_SEPARATOR . $it, $skip, $root);
        }
        @rmdir($path);
    }

    /**
     * 递归复制路径（跳过受保护路径）
     *
     * @param string $src
     * @param string $dst
     * @param array $skip
     * @param string $root
     * @return void
     */
    private function copyPath(string $src, string $dst, array $skip, string $root): void
    {
        if ($this->isProtected($this->rel($dst, $root), $skip)) return;

        if (is_dir($src)) {
            if (!is_dir($dst)) @mkdir($dst, 0755, true);
            $items = @scandir($src) ?: [];
            foreach ($items as $it) {
                if ($it === '.' || $it === '..') continue;
                $this->copyPath($src . DIRECTORY_SEPARATOR . $it, $dst . DIRECTORY_SEPARATOR . $it, $skip, $root);
            }
            return;
        }
        if (is_file($src)) {
            $parent = dirname($dst);
            if (!is_dir($parent)) @mkdir($parent, 0755, true);
            @copy($src, $dst);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }

    /**
     * 清理 runtime 时需要保留的顶层条目
     *
     * - log        日志目录（框架日志 + 证书任务日志，Cert 控制器「查看日志」会读取）
     * - backup     历史版本备份
     * - update     本次更新的安装包、解压目录与 task.json 进度
     * - .gitignore 仓库自带文件（被 git 跟踪，删除会导致工作区出现删除记录）
     *
     * 其余内容（cache/temp/session/cert、@login.lock 等）均为可重建的临时数据，更新后自动生成。
     *
     * @return array
     */
    private static function runtimeKeepItems(): array
    {
        return ['log', 'backup', 'update', '.gitignore'];
    }

    /**
     * 清理 runtime 下除日志、历史版本备份与更新临时目录之外的内容
     *
     * 说明：cache/temp 为框架缓存与模板编译产物，session 仅用于登录页（图形验证码、
     * 动态口令中间态，后台登录本身依赖 cookie token 而非 session），cert 为证书接口的
     * 下载解压临时目录（源码中用完即清），均为可自动重建的数据，故一并清除。
     *
     * @return void
     */
    private function cleanRuntime(): void
    {
        $runtime = $this->rootPath() . 'runtime';
        if (!is_dir($runtime)) return;
        $keep = self::runtimeKeepItems();
        $items = @scandir($runtime) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            if (in_array($it, $keep, true)) continue;
            $this->removePathRecursive($runtime . DIRECTORY_SEPARATOR . $it);
        }
    }

    /**
     * 写入一次进度
     *
     * @param string $token
     * @param string $step
     * @param int $percent
     * @param string $message
     * @param string $status
     * @return void
     */
    private function step(string $token, string $step, int $percent, string $message, string $status = 'running'): void
    {
        $this->setProgress($this->mergeProgress($token, [
            'status'  => $status,
            'step'    => $step,
            'percent' => $percent,
            'message' => $message,
        ]));
    }

    /**
     * 合并进度并保留原有 token/from/to
     *
     * @param string $token
     * @param array $data
     * @return array
     */
    private function mergeProgress(string $token, array $data): array
    {
        $old = $this->getProgress($token);
        $base = [
            'token'   => $token,
            'status'  => $old['status'] ?? 'running',
            'percent' => $old['percent'] ?? 0,
            'step'    => $old['step'] ?? 'init',
            'message' => $old['message'] ?? '',
            'error'   => $old['error'] ?? '',
            'from'    => $old['from'] ?? self::getCurrentRelease(),
            'to'      => $old['to'] ?? '',
        ];
        return array_merge($base, $data);
    }

    /**
     * 直接写入进度（自动补全 token）
     *
     * @param array $data
     * @return void
     */
    public function setProgress(array $data): void
    {
        $file = $this->tmpDir() . 'task.json';
        $this->ensureDir(dirname($file));
        $data['time'] = time();
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * 读取进度（token 不匹配返回空数组）
     *
     * @param string $token
     * @return array
     */
    public function getProgress(string $token): array
    {
        $file = $this->tmpDir() . 'task.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) return [];
        if ($token !== '' && ($data['token'] ?? '') !== $token) return [];
        return $data;
    }
}
