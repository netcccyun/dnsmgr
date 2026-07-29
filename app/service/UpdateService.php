<?php

namespace app\service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\facade\Cache;
use think\facade\Db;

/**
 * 在线更新服务
 *
 * 对照官方 Release 安装包（扁平目录 + vendor，无 .github）设计：
 * - 只覆盖包内文件，不删除本地多出来的文件（Update 控制器/服务会保留）
 * - 保护 .env / runtime / .git / 下载包 等
 * - 更新后自愈菜单入口（因官方包会覆盖 layout.html / route/app.php）
 */
class UpdateService
{
    private const REPO = 'netcccyun/dnsmgr';
    private const GITHUB_API = 'https://api.github.com';
    private const MIRRORS = [
        'https://ghproxy.net/',
        'https://mirror.ghproxy.com/',
        'https://gitproxy.click/',
    ];

    /** 允许的下载域名（防 SSRF） */
    private const ALLOWED_HOSTS = [
        'github.com',
        'api.github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
        'ghproxy.net',
        'mirror.ghproxy.com',
        'gitproxy.click',
        'cdn.jsdelivr.net',
        'raw.githubusercontent.com',
    ];

    /** 永远不覆盖的相对路径前缀/文件（本地二次开发与在线更新自身） */
    private const PROTECTED_PREFIXES = [
        '.env',
        '.git/',
        'runtime/',
        '下载包/',
        'route/update.php',
        'app/service/UpdateService.php',
        'app/controller/Update.php',
        'app/view/update/',
    ];

    private string $root;
    private string $tmpDir;
    /** @var resource|null */
    private $lockFp = null;

    public function __construct()
    {
        $this->root = rtrim(app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR;
        $this->tmpDir = $this->root . 'runtime' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR;
    }

    /**
     * @return array{code:int,msg:string,data?:array}
     */
    public function check(): array
    {
        $localVersion = (string)config('app.version');
        $localDbVersion = (string)config('app.dbversion');

        try {
            $release = $this->fetchLatestRelease();
        } catch (Exception $e) {
            return ['code' => -1, 'msg' => '获取版本信息失败：' . $e->getMessage() . '（可在系统设置→代理设置中配置代理后重试）'];
        }

        $remoteVersion = $this->resolveRemoteVersion($release);
        // 仅当 remote 是纯 Build 数字（>=1000）时做数字比较；tag 如 2.19 走弱判断
        $isBuildNum = (bool)preg_match('/^\d{4,6}$/', trim($remoteVersion));
        $localBuild = (int)preg_replace('/\D/', '', $localVersion);
        if ($isBuildNum) {
            $hasUpdate = ((int)$remoteVersion) > $localBuild;
        } else {
            $hasUpdate = $this->isTagProbablyNewer((string)($release['tag_name'] ?? $remoteVersion), $localVersion);
            // 展示用：带上 tag，避免用户以为没检查到
            if (!str_contains($remoteVersion, 'Build') && !preg_match('/^\d{4,6}$/', $remoteVersion)) {
                $remoteVersion = $remoteVersion . '（未解析到 Build 号，可强制重装）';
            }
        }

        return [
            'code' => 0,
            'msg' => $hasUpdate ? '发现新版本' : '当前已是最新版本',
            'data' => [
                'has_update' => $hasUpdate,
                'local_version' => $localVersion,
                'local_dbversion' => $localDbVersion,
                'remote_version' => $remoteVersion,
                'tag' => $release['tag_name'] ?? '',
                'name' => $release['name'] ?? '',
                'body' => $release['body'] ?? '',
                'published_at' => $release['published_at'] ?? '',
                'html_url' => $release['html_url'] ?? '',
                'download_url' => $this->pickDownloadUrl($release),
                'zipball_url' => $release['zipball_url'] ?? '',
            ],
        ];
    }

    /**
     * @return array{code:int,msg:string,data?:array}
     */
    public function apply(?string $downloadUrl = null, bool $force = false): array
    {
        if (!checkPermission(2)) {
            return ['code' => -1, 'msg' => '无权限'];
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $steps = [];
        try {
            $this->acquireLock();
            $steps[] = '获取更新锁成功';

            foreach (['app', 'config', 'route', 'public'] as $dir) {
                $path = $this->root . $dir;
                if (is_dir($path) && !is_writable($path)) {
                    throw new Exception("目录不可写：{$dir}/ ，请检查权限");
                }
            }
            if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0755, true)) {
                throw new Exception('无法创建临时目录 runtime/update');
            }

            $steps[] = '检查更新信息';
            $check = $this->check();
            if ($check['code'] !== 0) {
                throw new Exception($check['msg']);
            }
            $info = $check['data'];
            if (empty($downloadUrl)) {
                if (!$info['has_update'] && !$force) {
                    return ['code' => 0, 'msg' => '当前已是最新版本，无需更新', 'data' => ['steps' => $steps]];
                }
                $downloadUrl = $info['download_url'] ?: $info['zipball_url'];
            }
            if (empty($downloadUrl)) {
                throw new Exception('未找到可用的下载地址');
            }
            $this->assertSafeDownloadUrl($downloadUrl);

            $steps[] = '下载更新包';
            $zipFile = $this->tmpDir . 'package_' . date('YmdHis') . '.zip';
            $this->downloadFile($downloadUrl, $zipFile);
            if (!is_file($zipFile) || filesize($zipFile) < 1024) {
                throw new Exception('下载的更新包无效或过小');
            }
            $steps[] = '下载完成（' . $this->formatSize(filesize($zipFile)) . '）';

            $steps[] = '解压更新包';
            $extractDir = $this->tmpDir . 'extract_' . date('YmdHis') . DIRECTORY_SEPARATOR;
            if (!@mkdir($extractDir, 0755, true)) {
                throw new Exception('无法创建解压目录');
            }
            $this->extractZip($zipFile, $extractDir);

            $packageRoot = $this->findPackageRoot($extractDir);
            if (!$packageRoot) {
                throw new Exception('更新包结构异常，未找到项目根目录（需含 app/ 或 composer.json）');
            }
            // 官方包特征：扁平 + vendor
            $hasVendor = is_dir($packageRoot . 'vendor');
            $steps[] = '定位更新包成功' . ($hasVendor ? '（含 vendor）' : '（源码包，保留本地 vendor）');

            $steps[] = '备份关键文件';
            $backupDir = $this->tmpDir . 'backup_' . date('YmdHis') . DIRECTORY_SEPARATOR;
            $this->backupKeyFiles($backupDir);

            $steps[] = '覆盖程序文件';
            $copied = $this->copyPackage($packageRoot, $this->root, $hasVendor);
            $steps[] = '已覆盖 ' . $copied . ' 个文件';

            $steps[] = '执行数据库升级';
            $this->runDbUpdate();
            $steps[] = '数据库升级完成';

            $steps[] = '恢复在线更新入口';
            $healed = $this->healLocalEntrypoints();
            $steps[] = $healed ? '菜单/入口已自愈' : '入口文件已是最新或写入跳过';

            $steps[] = '清理缓存';
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            Cache::clear();
            if (function_exists('clearDirectory')) {
                clearDirectory(app()->getRuntimePath() . 'cache/');
                clearDirectory(app()->getRuntimePath() . 'temp/');
            }

            @unlink($zipFile);
            $this->removeDir($extractDir);

            $newVersion = $this->readAppConfigValue('version') ?: (string)config('app.version');

            return [
                'code' => 0,
                'msg' => '更新成功！当前版本 Build ' . $newVersion . '，请刷新页面（可访问 /update）',
                'data' => [
                    'steps' => $steps,
                    'version' => $newVersion,
                    'backup' => $backupDir,
                ],
            ];
        } catch (Exception $e) {
            $steps[] = '失败：' . $e->getMessage();
            return ['code' => -1, 'msg' => $e->getMessage(), 'data' => ['steps' => $steps]];
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): void
    {
        if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0755, true)) {
            throw new Exception('无法创建临时目录 runtime/update');
        }
        $lockFile = $this->tmpDir . 'update.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            throw new Exception('无法创建更新锁文件');
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new Exception('已有更新任务正在进行，请稍后再试');
        }
        ftruncate($fp, 0);
        fwrite($fp, (string)getmypid() . ' ' . date('c'));
        fflush($fp);
        $this->lockFp = $fp;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockFp)) {
            flock($this->lockFp, LOCK_UN);
            fclose($this->lockFp);
            $this->lockFp = null;
        }
    }

    private function fetchLatestRelease(): array
    {
        $path = '/repos/' . self::REPO . '/releases/latest';
        $urls = [
            self::GITHUB_API . $path,
            'https://ghproxy.net/' . self::GITHUB_API . $path,
            'https://mirror.ghproxy.com/' . self::GITHUB_API . $path,
        ];
        $errors = [];
        foreach ($urls as $url) {
            try {
                $body = $this->httpGet($url, [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'dnsmgr-updater',
                ], 15);
                $data = json_decode($body, true);
                if (is_array($data) && !empty($data['tag_name'])) {
                    return $data;
                }
                $errors[] = $url . ' => 返回无效';
            } catch (\Throwable $e) {
                $errors[] = $url . ' => ' . $e->getMessage();
            }
        }
        throw new Exception("无法获取 GitHub Release：\n" . implode("\n", $errors));
    }

    private function resolveRemoteVersion(array $release): string
    {
        $body = (string)($release['body'] ?? '');
        if (preg_match('/Build\s*[#:]?\s*(\d{3,6})/i', $body, $m)) {
            return $m[1];
        }
        // 更新说明里常见：V2.19 (Build 1051)
        if (preg_match('/V?\d+\.\d+\s*\(\s*Build\s*(\d{3,6})\s*\)/i', $body, $m)) {
            return $m[1];
        }
        if (preg_match('/\b(\d{4,6})\b/', (string)($release['name'] ?? ''), $m) && (int)$m[1] >= 1000) {
            return $m[1];
        }

        $tag = (string)($release['tag_name'] ?? 'main');

        // 附件名 dnsmgr_2.19.zip 无法直接得 Build，继续读 config
        // 优先 jsDelivr（国内较稳），短超时；避免 raw.githubusercontent 长时间卡住整次检查
        $rawUrls = [
            'https://cdn.jsdelivr.net/gh/' . self::REPO . '@' . rawurlencode($tag) . '/config/app.php',
            'https://fastly.jsdelivr.net/gh/' . self::REPO . '@' . rawurlencode($tag) . '/config/app.php',
            'https://ghproxy.net/https://raw.githubusercontent.com/' . self::REPO . '/' . rawurlencode($tag) . '/config/app.php',
        ];
        foreach ($rawUrls as $rawUrl) {
            try {
                $content = $this->httpGet($rawUrl, ['User-Agent' => 'dnsmgr-updater'], 6);
                if (preg_match("/['\"]version['\"]\s*=>\s*['\"](\d+)['\"]/", $content, $m)) {
                    return $m[1];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // 本地「下载包」若是同版本官方包，直接读 Build（你已有 1051 包时可秒出结果）
        $localPkg = $this->root . '下载包' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        if (is_file($localPkg)) {
            $content = (string)@file_get_contents($localPkg);
            if ($content !== '' && preg_match("/['\"]version['\"]\s*=>\s*['\"](\d+)['\"]/", $content, $m)) {
                return $m[1];
            }
        }

        // 最后返回 tag 原文，交给 check() 做弱判断（不再把 2.19 当成 Build 219）
        return $tag !== '' ? $tag : '0';
    }

    private function compareVersion(string $a, string $b): int
    {
        return ((int)preg_replace('/\D/', '', $a)) <=> ((int)preg_replace('/\D/', '', $b));
    }

    /**
     * 解析不到 Build 时：有合法 release tag 就提示可更新/可重装，避免误报“已是最新”。
     */
    private function isTagProbablyNewer(string $tag, string $localBuild): bool
    {
        $tag = ltrim(trim($tag), 'vV');
        return $tag !== '';
    }

    private function pickDownloadUrl(array $release): string
    {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                $name = strtolower((string)($asset['name'] ?? ''));
                $url = (string)($asset['browser_download_url'] ?? '');
                if ($url && str_ends_with($name, '.zip') && !str_contains($name, 'source')) {
                    return $url;
                }
            }
            foreach ($release['assets'] as $asset) {
                $name = strtolower((string)($asset['name'] ?? ''));
                $url = (string)($asset['browser_download_url'] ?? '');
                if ($url && str_ends_with($name, '.zip')) {
                    return $url;
                }
            }
        }
        return (string)($release['zipball_url'] ?? '');
    }

    private function assertSafeDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new Exception('下载地址协议不合法');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            throw new Exception('下载地址无效');
        }
        $ok = false;
        foreach (self::ALLOWED_HOSTS as $allow) {
            if ($host === $allow || str_ends_with($host, '.' . $allow)) {
                $ok = true;
                break;
            }
        }
        // 镜像站常把原 URL 接在 path 后，host 是镜像域名
        if (!$ok) {
            throw new Exception('下载地址不在允许列表：' . $host);
        }
    }

    private function downloadFile(string $url, string $savePath): void
    {
        $errors = [];
        foreach ($this->buildMirrorUrls($url) as $tryUrl) {
            try {
                $this->assertSafeDownloadUrl($tryUrl);
                $this->httpDownload($tryUrl, $savePath);
                if (!is_file($savePath) || filesize($savePath) <= 1024) {
                    @unlink($savePath);
                    throw new Exception('下载文件过小');
                }
                $fh = fopen($savePath, 'rb');
                $magic = $fh ? fread($fh, 2) : '';
                if ($fh) {
                    fclose($fh);
                }
                if ($magic !== 'PK') {
                    @unlink($savePath);
                    throw new Exception('文件不是有效的 ZIP 包');
                }
                return;
            } catch (Exception $e) {
                $errors[] = $tryUrl . ' => ' . $e->getMessage();
                @unlink($savePath);
            }
        }
        throw new Exception("全部下载源失败：\n" . implode("\n", $errors));
    }

    private function buildMirrorUrls(string $url): array
    {
        $list = [$url];
        if (preg_match('#^https://(github\.com|objects\.githubusercontent\.com|codeload\.github\.com|release-assets\.githubusercontent\.com)/#', $url)) {
            foreach (self::MIRRORS as $mirror) {
                $list[] = rtrim($mirror, '/') . '/' . $url;
            }
        }
        return array_values(array_unique($list));
    }

    private function httpGet(string $url, array $headers = [], int $timeout = 30): string
    {
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => min(10, $timeout),
            'allow_redirects' => true,
            'verify' => false,
            'http_errors' => false,
            'headers' => array_merge(['User-Agent' => 'dnsmgr-updater'], $headers),
        ];
        $this->applyProxy($options);
        try {
            $response = (new Client())->request('GET', $url, $options);
            $code = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            if ($code >= 400) {
                throw new Exception('HTTP ' . $code);
            }
            if ($body === '' || $body === null) {
                throw new Exception('空响应');
            }
            return $body;
        } catch (GuzzleException $e) {
            throw new Exception(guzzle_error($e));
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Exception($e->getMessage());
        }
    }

    private function httpDownload(string $url, string $savePath): void
    {
        $options = [
            'timeout' => 300,
            'connect_timeout' => 20,
            'allow_redirects' => true,
            'verify' => false,
            'http_errors' => false,
            'sink' => $savePath,
            'headers' => [
                'User-Agent' => 'dnsmgr-updater',
                'Accept' => 'application/octet-stream',
            ],
        ];
        $this->applyProxy($options);
        try {
            $response = (new Client())->request('GET', $url, $options);
            if ($response->getStatusCode() >= 400) {
                throw new Exception('HTTP ' . $response->getStatusCode());
            }
        } catch (GuzzleException $e) {
            throw new Exception(guzzle_error($e));
        }
    }

    private function applyProxy(array &$options): void
    {
        $proxy_server = config_get('proxy_server');
        $proxy_port = intval(config_get('proxy_port'));
        if (empty($proxy_server) || empty($proxy_port)) {
            return;
        }
        $proxy_userpwd = config_get('proxy_user') . ':' . config_get('proxy_pwd');
        $proxy_type = config_get('proxy_type');
        $proxy_string = match ($proxy_type) {
            'https' => 'https://',
            'sock4' => 'socks4://',
            'sock5' => 'socks5://',
            'sock5h' => 'socks5h://',
            default => 'http://',
        };
        if ($proxy_userpwd != ':') {
            $proxy_string .= $proxy_userpwd . '@';
        }
        $proxy_string .= $proxy_server . ':' . $proxy_port;
        $options['proxy'] = $proxy_string;
    }

    private function extractZip(string $zipFile, string $dest): void
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $res = $zip->open($zipFile);
            if ($res !== true) {
                throw new Exception('ZipArchive 打开失败，错误码：' . $res);
            }
            if (!$zip->extractTo($dest)) {
                $zip->close();
                throw new Exception('ZipArchive 解压失败');
            }
            $zip->close();
            return;
        }

        if ($this->commandExists('unzip')) {
            $cmd = 'unzip -o -q ' . escapeshellarg($zipFile) . ' -d ' . escapeshellarg($dest);
            exec($cmd . ' 2>&1', $output, $code);
            if ($code === 0) {
                return;
            }
            throw new Exception('unzip 解压失败：' . implode("\n", $output));
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $zipFileWin = str_replace('/', '\\', $zipFile);
            $destWin = str_replace('/', '\\', $dest);
            $ps = 'powershell -NoProfile -Command "Expand-Archive -LiteralPath \'' . str_replace("'", "''", $zipFileWin) . '\' -DestinationPath \'' . str_replace("'", "''", $destWin) . '\' -Force"';
            exec($ps . ' 2>&1', $output, $code);
            if ($code === 0 && $this->dirHasFiles($dest)) {
                return;
            }
            throw new Exception('PowerShell 解压失败：' . implode("\n", $output));
        }

        throw new Exception('服务器未安装 zip 扩展，且无可用的 unzip 命令，无法解压更新包');
    }

    private function findPackageRoot(string $extractDir): ?string
    {
        $extractDir = rtrim($extractDir, '/\\') . DIRECTORY_SEPARATOR;
        // 官方 Release 包：解压后直接是项目根（扁平）
        if (is_dir($extractDir . 'app') && is_file($extractDir . 'composer.json')) {
            return $extractDir;
        }
        if (is_file($extractDir . 'composer.json') || is_dir($extractDir . 'app')) {
            return $extractDir;
        }
        // GitHub zipball：多一层目录
        $items = @scandir($extractDir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $extractDir . $item . DIRECTORY_SEPARATOR;
            if (is_dir($path) && (is_file($path . 'composer.json') || is_dir($path . 'app'))) {
                return $path;
            }
        }
        return null;
    }

    private function backupKeyFiles(string $backupDir): void
    {
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }
        $files = [
            'config/app.php',
            'composer.json',
            'composer.lock',
            'route/app.php',
            'app/view/common/layout.html',
            'app/controller/System.php',
        ];
        foreach ($files as $rel) {
            $src = $this->root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($src)) {
                continue;
            }
            $dst = $backupDir . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $dir = dirname($dst);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @copy($src, $dst);
        }
        if (is_file($this->root . '.env')) {
            @copy($this->root . '.env', $backupDir . '.env');
        }
    }

    private function copyPackage(string $from, string $to, bool $copyVendor): int
    {
        $from = rtrim($from, '/\\') . DIRECTORY_SEPARATOR;
        $to = rtrim($to, '/\\') . DIRECTORY_SEPARATOR;
        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $rel = substr($item->getPathname(), strlen($from));
            $rel = str_replace('\\', '/', $rel);

            if ($this->shouldSkip($rel, $copyVendor)) {
                continue;
            }

            $target = $to . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!@copy($item->getPathname(), $target)) {
                throw new Exception('复制文件失败：' . $rel);
            }
            $count++;
        }
        return $count;
    }

    private function shouldSkip(string $rel, bool $copyVendor): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');

        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if ($rel === rtrim($prefix, '/') || str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        // vendor：包内有才覆盖
        if ($rel === 'vendor' || str_starts_with($rel, 'vendor/')) {
            return !$copyVendor;
        }

        // 用户自定义 CSS
        if ($rel === 'public/static/css/custom.css') {
            return is_file($this->root . 'public' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'custom.css');
        }

        return false;
    }

    private function runDbUpdate(): void
    {
        $sqlFile = $this->root . 'app' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'update.sql';
        if (!is_file($sqlFile)) {
            return;
        }
        $sqls = file_get_contents($sqlFile);
        $mysql_prefix = env('database.prefix', 'dnsmgr_');
        foreach (explode(';', $sqls) as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $value = str_replace('dnsmgr_', $mysql_prefix, $value);
            try {
                Db::execute($value);
            } catch (Exception $e) {
                // 兼容已存在表/字段
            }
        }
        $dbversion = $this->readAppConfigValue('dbversion') ?: config('app.dbversion');
        config_set('version', $dbversion);
        Cache::delete('configs');
    }

    /**
     * 官方包会覆盖 layout / 首页，导致菜单消失。
     * 路由在 route/update.php（官方包不含此文件），升级后路由仍在。
     * 这里尽量把菜单和首页入口补回去。
     */
    private function healLocalEntrypoints(): bool
    {
        $changed = false;

        $layout = $this->root . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'layout.html';
        if (is_file($layout) && is_writable($layout)) {
            $html = file_get_contents($layout);
            if ($html !== false && !str_contains($html, 'href="/update"') && !str_contains($html, 'href=\'/update\'')) {
                $needle = '<li class="{:checkIfActive(\'proxyset\')}"><a href="/system/proxyset"><i class="fa fa-circle-o"></i> 代理设置</a></li>';
                $inject = $needle . "\n              <li><a href=\"/update\"><i class=\"fa fa-circle-o\"></i> 在线更新</a></li>";
                if (str_contains($html, $needle)) {
                    $html = str_replace($needle, $inject, $html);
                    if (@file_put_contents($layout, $html) !== false) {
                        $changed = true;
                    }
                }
            }
        }

        $index = $this->root . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($index) && is_writable($index)) {
            $html = file_get_contents($index);
            if ($html !== false && !str_contains($html, 'href="/update"') && preg_match('/<button[^>]*onclick="cleancache\(\)"[^>]*>.*?<\/button>/s', $html, $m)) {
                $btn = $m[0];
                $inject = "{if request()->user['level'] eq 2}\n\t\t\t<a href=\"/update\" class=\"btn btn-primary btn-block\"><i class=\"fa fa-cloud-download\"></i> 在线更新</a>\n\t\t\t{/if}\n\t\t\t" . $btn;
                $html = str_replace($btn, $inject, $html);
                if (@file_put_contents($index, $html) !== false) {
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    private function readAppConfigValue(string $key): ?string
    {
        $appConfigFile = $this->root . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        if (!is_file($appConfigFile)) {
            return null;
        }
        $content = (string)@file_get_contents($appConfigFile);
        if ($content === '') {
            return null;
        }
        if (preg_match("/['\"]" . preg_quote($key, '/') . "['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
            return $m[1];
        }
        return null;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function dirHasFiles(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        foreach (@scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                return true;
            }
        }
        return false;
    }

    private function commandExists(string $cmd): bool
    {
        $out = [];
        $code = 1;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @exec('where ' . $cmd . ' 2>NUL', $out, $code);
        } else {
            @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
        }
        return $code === 0 && !empty($out);
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float)$bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
}
