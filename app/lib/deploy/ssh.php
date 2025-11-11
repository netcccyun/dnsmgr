<?php

namespace app\lib\deploy;

use app\lib\CertHelper;
use app\lib\DeployInterface;
use Exception;

class ssh implements DeployInterface
{
    private $logger;
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function check()
    {
        $this->connect();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $connection = $this->connect();
        if (isset($config['cmd_pre']) && !empty($config['cmd_pre'])) {
            $cmds = explode("\n", $config['cmd_pre']);
            foreach ($cmds as $cmd) {
                $cmd = trim($cmd);
                if (empty($cmd)) continue;
                $this->exec($connection, $cmd);
            }
        }
        $sftp = ssh2_sftp($connection);
        if ($config['format'] == 'pem') {
            $stream = fopen("ssh2.sftp://$sftp{$config['pem_cert_file']}", 'w');
            if (!$stream) {
                throw new Exception("无法创建证书文件：{$config['pem_cert_file']}");
            }
            fwrite($stream, $fullchain);
            fclose($stream);
            $this->log('证书已保存到：' . $config['pem_cert_file']);

            $stream = fopen("ssh2.sftp://$sftp{$config['pem_key_file']}", 'w');
            if (!$stream) {
                throw new Exception("无法创建私钥文件：{$config['pem_key_file']}");
            }
            fwrite($stream, $privatekey);
            fclose($stream);
            $this->log('私钥已保存到：' . $config['pem_key_file']);
        } elseif ($config['format'] == 'pfx') {
            $pfx_pass = $config['pfx_pass'] ?? null;
            $pfx = CertHelper::getPfx($fullchain, $privatekey, $pfx_pass);

            $stream = fopen("ssh2.sftp://$sftp{$config['pfx_file']}", 'w');
            if (!$stream) {
                throw new Exception("无法创建PFX证书文件：{$config['pfx_file']}");
            }
            fwrite($stream, $pfx);
            fclose($stream);
            $this->log('PFX证书已保存到：' . $config['pfx_file']);

            if ($config['uptype'] == '1' && !empty($config['iis_domain'])) {
                $cert_hash = openssl_x509_fingerprint($fullchain, 'sha1');
                $this->deploy_iis($connection, $config['iis_domain'], $config['pfx_file'], $config['pfx_pass'], $cert_hash);
                $config['cmd'] = null;
            }
        }
        if (!empty($config['cmd'])) {
            $cmds = explode("\n", $config['cmd']);
            foreach ($cmds as $cmd) {
                $cmd = trim($cmd);
                if (empty($cmd)) continue;
                $this->exec($connection, $cmd);
            }
        }
    }

    private function deploy_iis($connection, $domain, $pfx_file, $pfx_pass, $cert_hash)
    {
        if (!strpos($domain, ':')) {
            $domain .= ':443';
        }
        $ret = $this->exec($connection, 'netsh http show sslcert hostnameport=' . $domain);
        if (preg_match('/:\s+(\w{40})/', $ret, $match)) {
            if ($match[1] == $cert_hash) {
                $this->log('IIS域名 ' . $domain . ' 证书已存在，无需更新');
                return;
            }
        }
        $p = '-p ""';
        if (!empty($pfx_pass)) $p = '-p ' . $pfx_pass;
        if (substr($pfx_file, 0, 1) == '/') $pfx_file = substr($pfx_file, 1);
        $this->exec($connection, 'certutil ' . $p . ' -importPFX ' . $pfx_file);
        $this->exec($connection, 'netsh http delete sslcert hostnameport=' . $domain);
        $this->exec($connection, 'netsh http add sslcert hostnameport=' . $domain . ' certhash=' . $cert_hash . ' certstorename=MY appid=\'{' . $this->uuid() . '}\'');
        $this->log('IIS域名 ' . $domain . ' 证书已更新');
    }

    private function uuid()
    {
        $guid = md5(uniqid(mt_rand(), true));
        return substr($guid, 0, 8) . '-' . substr($guid, 8, 4) . '-4' . substr($guid, 12, 3) . '-' . substr($guid, 16, 4) . '-' . substr($guid, 20, 12);
    }

    private function exec($connection, $cmd)
    {
        $this->log('执行命令：' . $cmd);
        $stream = ssh2_exec($connection, $cmd);
        $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
        if (!$stream || !$errorStream) {
            throw new Exception('执行命令失败');
        }
        stream_set_blocking($stream, true);
        stream_set_blocking($errorStream, true);
        $output = stream_get_contents($stream);
        $errorOutput = stream_get_contents($errorStream);
        fclose($stream);
        fclose($errorStream);
        if (trim($errorOutput)) {
            if ($this->config['windows'] == '1' && $this->containsGBKChinese($errorOutput)) {
                $errorOutput = mb_convert_encoding($errorOutput, 'UTF-8', 'GBK');
            }
            throw new Exception('执行命令失败：' . trim($errorOutput));
        } else {
            if ($this->config['windows'] == '1' && $this->containsGBKChinese($output)) {
                $output = mb_convert_encoding($output, 'UTF-8', 'GBK');
            }
            $this->log('执行命令成功：' . trim($output));
            return $output;
        }
    }

    private function connect()
    {
        if (!function_exists('ssh2_connect')) {
            throw new Exception('ssh2扩展未安装');
        }
        if (empty($this->config['host']) || empty($this->config['port']) || empty($this->config['username']) || $this->config['auth'] == '0' && empty($this->config['password']) || $this->config['auth'] == '1' && empty($this->config['privatekey'])) {
            throw new Exception('必填参数不能为空');
        }
        if (!filter_var($this->config['host'], FILTER_VALIDATE_IP) && !filter_var($this->config['host'], FILTER_VALIDATE_DOMAIN)) {
            throw new Exception('主机地址不合法');
        }
        if (!is_numeric($this->config['port']) || $this->config['port'] < 1 || $this->config['port'] > 65535) {
            throw new Exception('端口不合法');
        }

        $connection = ssh2_connect($this->config['host'], intval($this->config['port']));
        if (!$connection) {
            throw new Exception('SSH连接失败');
        }
        if ($this->config['auth'] == '1') {
            $publicKey = $this->getPublicKey($this->config['privatekey']);
            $publicKeyPath = app()->getRuntimePath() . $this->config['host'] . '.pub';
            $privateKeyPath = app()->getRuntimePath() . $this->config['host'] . '.key';
            $umask = umask(0066);
            file_put_contents($privateKeyPath, $this->config['privatekey']);
            file_put_contents($publicKeyPath, $publicKey);
            umask($umask);
            $passphrase = $this->config['passphrase'] ?? null; // 私钥密码
            if (!ssh2_auth_pubkey_file($connection, $this->config['username'], $publicKeyPath, $privateKeyPath, $passphrase)) {
                throw new Exception('私钥认证失败');
            }
        } else {
            if (!ssh2_auth_password($connection, $this->config['username'], $this->config['password'])) {
                throw new Exception('用户名或密码错误');
            }
        }
        return $connection;
    }

    private function getPublicKey($privateKey)
    {
        $res = openssl_pkey_get_private($privateKey);
        if (!$res) {
            throw new Exception('加载私钥失败');
        }
        $details = openssl_pkey_get_details($res);
        if (!$details || !isset($details['key'])) {
            throw new Exception('从私钥导出公钥失败');
        }
        $buffer = pack("N", 7) . "ssh-rsa" .
            $this->sshEncodeBuffer($details['rsa']['e']) .
            $this->sshEncodeBuffer($details['rsa']['n']);
        return "ssh-rsa " . base64_encode($buffer);
    }

    private function sshEncodeBuffer($buffer)
    {
        $len = strlen($buffer);
        if (ord($buffer[0]) & 0x80) {
            $len++;
            $buffer = "\x00" . $buffer;
        }
        return pack("Na*", $len, $buffer);
    }

    private function containsGBKChinese($string)
    {
        return preg_match('/[\x81-\xFE][\x40-\xFE]/', $string) === 1;
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
}
