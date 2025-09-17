<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class fnos implements DeployInterface
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
        $domains = $config['domainList'];
        if (empty($domains)) throw new Exception('没有设置要部署的域名');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');

        $connection = $this->connect();
        $cert_all = $this->exec($connection, '获取证书列表', 'cat /usr/trim/etc/network_cert_all.conf');
        $list = json_decode($cert_all, true);
        if (!$list) throw new Exception('获取证书列表失败');

        $success = 0;
        foreach ($list as $row) {
            if (empty($row['san'])) continue;
            $cert_domains = $row['san'];
            $flag = false;
            foreach ($cert_domains as $domain) {
                if (in_array($domain, $domains)) {
                    $flag = true;
                    break;
                }
            }
            if ($flag) {
                $certPath = $row['certificate'];
                $keyPath = $row['privateKey'];
                $certDir = dirname($certPath);
                $this->exec($connection, '上传证书文件', "sudo tee ".$certPath." > /dev/null <<'EOF'\n".$fullchain."\nEOF");
                $this->exec($connection, '上传私钥文件', "sudo tee ".$keyPath." > /dev/null <<'EOF'\n".$privatekey."\nEOF");
                $this->exec($connection, '刷新目录权限', 'sudo chmod 0755 "'.$certDir.'" -R');
                $this->exec($connection, '更新数据表', 'sudo -u postgres psql -d trim_connect -c "UPDATE cert SET  valid_to='.$certInfo['validTo_time_t'].'000,valid_from='.$certInfo['validFrom_time_t'].'000,issued_by=\''.$certInfo['issuer']['CN'].'\',updated_time='.getMillisecond().' WHERE private_key=\''.$keyPath.'\'"');
                $this->log('证书 '.$row['domain'].' 更新成功');
                $success++;
            }
        }
        if ($success == 0) {
            throw new Exception('没有要更新的证书');
        } else {
            $this->exec($connection, '重启webdav', 'sudo systemctl restart webdav.service');
            $this->exec($connection, '重启smbftpd', 'sudo systemctl restart smbftpd.service');
            $this->exec($connection, '重启trim_nginx', 'sudo systemctl restart trim_nginx.service');
        }
    }

    private function exec($connection, $name, $cmd)
    {
        $stream = ssh2_exec($connection, $cmd);
        $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
        if (!$stream || !$errorStream) {
            throw new Exception($name.'执行命令失败');
        }
        stream_set_blocking($stream, true);
        stream_set_blocking($errorStream, true);
        $output = stream_get_contents($stream);
        $errorOutput = stream_get_contents($errorStream);
        fclose($stream);
        fclose($errorStream);
        if (trim($errorOutput)) {
            if (strpos($errorOutput, 'a password is required') !== false) {
                throw new Exception('权限不足，请先配置 sudo 免密');
            }
            throw new Exception($name.'失败：' . trim($errorOutput));
        } else {
            if (strlen($output) > 200) {
                return $output;
            }
            $this->log($name.'成功 ' . trim($output));
            return $output;
        }
    }

    private function connect()
    {
        if (!function_exists('ssh2_connect')) {
            throw new Exception('ssh2扩展未安装');
        }
        if (empty($this->config['host']) || empty($this->config['port']) || empty($this->config['username']) || empty($this->config['password'])) {
            throw new Exception('必填参数不能为空');
        }
        if (!filter_var($this->config['host'], FILTER_VALIDATE_IP) && !filter_var($this->config['host'], FILTER_VALIDATE_DOMAIN)) {
            throw new Exception('主机地址不合法');
        }
        if (!is_numeric($this->config['port']) || $this->config['port'] < 1 || $this->config['port'] > 65535) {
            throw new Exception('SSH端口不合法');
        }

        $connection = ssh2_connect($this->config['host'], intval($this->config['port']));
        if (!$connection) {
            throw new Exception('SSH连接失败');
        }
        if (!ssh2_auth_password($connection, $this->config['username'], $this->config['password'])) {
            throw new Exception('用户名或密码错误');
        }
        return $connection;
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }
}
