<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class local implements DeployInterface
{
    private $logger;

    public function __construct($config) {}

    public function check() {}

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (!empty($config['cmd'])) {
            throw new Exception('Local deploy commands are disabled for security reasons.');
        }

        if ($config['format'] == 'pem') {
            $dir = dirname($config['pem_cert_file']);
            if (!is_dir($dir)) throw new Exception($dir . ' directory does not exist');
            if (!is_writable($dir)) throw new Exception($dir . ' directory is not writable');

            if (file_put_contents($config['pem_cert_file'], $fullchain)) {
                $this->log('Certificate saved to: ' . $config['pem_cert_file']);
            } else {
                throw new Exception('Failed to save certificate to ' . $config['pem_cert_file']);
            }
            if (file_put_contents($config['pem_key_file'], $privatekey)) {
                $this->log('Private key saved to: ' . $config['pem_key_file']);
            } else {
                throw new Exception('Failed to save private key to ' . $config['pem_key_file']);
            }
        } elseif ($config['format'] == 'pfx') {
            $dir = dirname($config['pfx_file']);
            if (!is_dir($dir)) throw new Exception($dir . ' directory does not exist');
            if (!is_writable($dir)) throw new Exception($dir . ' directory is not writable');

            $pfx = \app\lib\CertHelper::getPfx($fullchain, $privatekey, $config['pfx_pass'] ? $config['pfx_pass'] : null);
            if (file_put_contents($config['pfx_file'], $pfx)) {
                $this->log('PFX certificate saved to: ' . $config['pfx_file']);
            } else {
                throw new Exception('Failed to save PFX certificate to ' . $config['pfx_file']);
            }
        }
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
