<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class ftp implements DeployInterface
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
        $conn_id = $this->connect();
        ftp_pasv($conn_id, true);
        if ($config['format'] == 'pem') {
            $temp_stream = fopen('php://temp', 'r+');
            fwrite($temp_stream, $fullchain);
            rewind($temp_stream);
            if (ftp_fput($conn_id, $config['pem_cert_file'], $temp_stream, FTP_BINARY)) {
                $this->log('证书文件上传成功：' . $config['pem_cert_file']);
            } else {
                fclose($temp_stream);
                ftp_close($conn_id);
                throw new Exception('证书文件上传失败：' . $config['pem_cert_file']);
            }
            fclose($temp_stream);

            $temp_stream = fopen('php://temp', 'r+');
            fwrite($temp_stream, $privatekey);
            rewind($temp_stream);
            if (ftp_fput($conn_id, $config['pem_key_file'], $temp_stream, FTP_BINARY)) {
                $this->log('私钥文件上传成功：' . $config['pem_key_file']);
            } else {
                fclose($temp_stream);
                ftp_close($conn_id);
                throw new Exception('私钥文件上传失败：' . $config['pem_key_file']);
            }
            fclose($temp_stream);
        } elseif ($config['format'] == 'pfx') {
            $pfx = \app\lib\CertHelper::getPfx($fullchain, $privatekey, $config['pfx_pass'] ? $config['pfx_pass'] : null);

            $temp_stream = fopen('php://temp', 'r+');
            fwrite($temp_stream, $pfx);
            rewind($temp_stream);
            if (ftp_fput($conn_id, $config['pfx_file'], $temp_stream, FTP_BINARY)) {
                $this->log('PFX证书文件上传成功：' . $config['pfx_file']);
            } else {
                fclose($temp_stream);
                ftp_close($conn_id);
                throw new Exception('PFX证书文件上传失败：' . $config['pfx_file']);
            }
            fclose($temp_stream);
        }
        ftp_close($conn_id);
    }

    private function connect()
    {
        if (!function_exists('ftp_connect')) {
            throw new Exception('ftp扩展未安装');
        }
        if (empty($this->config['host']) || empty($this->config['port']) || empty($this->config['username']) || empty($this->config['password'])) {
            throw new Exception('必填参数不能为空');
        }
        if (!filter_var($this->config['host'], FILTER_VALIDATE_IP) && !filter_var($this->config['host'], FILTER_VALIDATE_DOMAIN)) {
            throw new Exception('主机地址不合法');
        }
        if (!is_numeric($this->config['port']) || $this->config['port'] < 1 || $this->config['port'] > 65535) {
            throw new Exception('端口不合法');
        }

        if ($this->config['secure'] == '1') {
            $conn_id = ftp_ssl_connect($this->config['host'], intval($this->config['port']), 10);
            if (!$conn_id) {
                throw new Exception('FTP服务器无法连接(SSL)');
            }
        } else {
            $conn_id = ftp_connect($this->config['host'], intval($this->config['port']), 10);
            if (!$conn_id) {
                throw new Exception('FTP服务器无法连接');
            }
        }
        if (!ftp_login($conn_id, $this->config['username'], $this->config['password'])) {
            ftp_close($conn_id);
            throw new Exception('FTP登录失败');
        }
        return $conn_id;
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
