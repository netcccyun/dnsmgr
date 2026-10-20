<?php

namespace app\service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\facade\Cache;
use ZipArchive;

class UpdateService
{
    const CHECK_URL = 'https://auth.cccyun.cc/app/dnsmgr.php';

    public function currentVersion(): string
    {
        return (string)config('app.version');
    }

    public function checkEnv(): array
    {
        $root = rtrim(app()->getRootPath(), '/\\');
        return [
            'ziparchive' => class_exists(ZipArchive::class),
            'writable' => is_dir($root) && is_writable($root),
            'root' => $root,
        ];
    }

    public function checkUpdate(): array
    {
        $ver = $this->currentVersion();
        $url = self::CHECK_URL . '?ver=' . urlencode($ver) . '&format=json';
        $data = $this->requestJson($url);
        if (!isset($data['code'])) {
            throw new Exception('检测更新失败，更新服务器返回数据异常');
        }
        if ((int)$data['code'] !== 0) {
            throw new Exception(is_string($data['msg'] ?? null) && $data['msg'] !== '' ? strip_tags($data['msg']) : '检测更新失败');
        }

        $versionText = (string)($data['version_text'] ?? '');
        $latestBuild = $this->parseLatestBuild((string)($data['msg'] ?? ''));

        return [
            'current_version' => $ver,
            'new_version' => $this->hasNewVersion($data['new_version'] ?? false),
            'version_text' => $versionText,
            'latest_version' => $latestBuild,
            'download_url' => (string)($data['download_url'] ?? ''),
            'download_url_mirror' => (string)($data['download_url_mirror'] ?? ''),
        ];
    }

    public function update(bool $useMirror = false): string
    {
        $env = $this->checkEnv();
        if (!$env['ziparchive']) {
            throw new Exception('当前PHP未安装ZipArchive扩展，无法在线更新');
        }
        if (!$env['writable']) {
            throw new Exception('网站根目录不可写，无法在线更新');
        }

        $info = $this->checkUpdate();
        if (!$info['new_version']) {
            throw new Exception('当前已是最新版本');
        }

        $url = $useMirror && $info['download_url_mirror'] !== '' ? $info['download_url_mirror'] : $info['download_url'];
        if (!preg_match('#^https?://#i', $url)) {
            throw new Exception('下载地址无效');
        }

        $zipFile = app()->getRuntimePath() . 'package.zip';
        if (is_file($zipFile)) {
            @unlink($zipFile);
        }

        try {
            $this->download($url, $zipFile);
            $this->extractZip($zipFile, $env['root']);
        } finally {
            if (is_file($zipFile)) {
                @unlink($zipFile);
            }
        }

        Cache::clear();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return $info['version_text'] !== '' ? $info['version_text'] : $this->currentVersion();
    }

    private function parseLatestBuild(string $msg): string
    {
        $msg = strip_tags($msg);
        if (preg_match('/Build\s*(\d+)/i', $msg, $matches)) {
            return $matches[1];
        }
        if (preg_match('/\((\d{4,})\)/', $msg, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function hasNewVersion($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }
        return !empty($value);
    }

    private function requestJson(string $url): array
    {
        try {
            $client = new Client($this->httpOptions(15, 10));
            $response = $client->request('GET', $url);
            $code = $response->getStatusCode();
            if ($code < 200 || $code >= 300) {
                throw new Exception('检测更新失败，HTTP状态码：' . $code);
            }
            $body = $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception('检测更新失败：' . guzzle_error($e));
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception('检测更新失败，更新服务器返回数据异常');
        }
        return $data;
    }

    private function download(string $url, string $savePath): void
    {
        try {
            $client = new Client($this->httpOptions(180, 20));
            $response = $client->request('GET', $url, ['sink' => $savePath]);
            $code = $response->getStatusCode();
            if ($code < 200 || $code >= 300) {
                throw new Exception('下载更新包失败，HTTP状态码：' . $code);
            }
        } catch (GuzzleException $e) {
            if (is_file($savePath)) {
                @unlink($savePath);
            }
            throw new Exception('下载更新包失败：' . guzzle_error($e));
        }

        if (!is_file($savePath) || filesize($savePath) < 100) {
            if (is_file($savePath)) {
                @unlink($savePath);
            }
            throw new Exception('下载更新包失败，文件为空');
        }

        $fh = fopen($savePath, 'rb');
        $magic = $fh ? fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "PK\x03\x04" && $magic !== "PK\x05\x06" && $magic !== "PK\x07\x08") {
            @unlink($savePath);
            throw new Exception('下载的文件不是有效的Zip安装包，请尝试切换镜像后重试');
        }
    }

    private function extractZip(string $zipFile, string $dest): void
    {
        $zip = new ZipArchive();
        $open = $zip->open($zipFile);
        if ($open !== true) {
            throw new Exception('打开更新包失败（错误码：' . $open . '）');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../')) {
                $zip->close();
                throw new Exception('更新包包含非法路径，已中止');
            }
        }

        $ok = $zip->extractTo($dest);
        $zip->close();
        if (!$ok) {
            throw new Exception('解压更新包失败');
        }
    }

    private function httpOptions(int $timeout, int $connectTimeout): array
    {
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'verify' => false,
            'http_errors' => false,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            ],
        ];
        $options['headers'] = normalize_http_headers($options['headers']);
        return $options;
    }
}
