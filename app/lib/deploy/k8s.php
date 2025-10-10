<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Symfony\Component\Yaml\Yaml;
use Exception;

class k8s implements DeployInterface
{
    private $logger;
    private $kubeconfig;
    private $server;
    private $bearerToken;
    private $tls = [];
    private $proxy;

    public function __construct($config)
    {
        $this->kubeconfig = $config['kubeconfig'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->kubeconfig)) throw new Exception('Kubeconfig不能为空');
        $this->verify();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $namespace = $config['namespace'];
        $secretName = $config['secret_name'];
        if (empty($namespace)) throw new Exception('命名空间不能为空');
        if (empty($secretName)) throw new Exception('Secret名称不能为空');

        $this->parse();

        $secretPayload = [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $secretName, 'namespace' => $namespace],
            'type' => 'kubernetes.io/tls',
            'data' => [
                'tls.crt' => base64_encode($config['fullchain']),
                'tls.key' => base64_encode($config['privatekey']),
            ],
        ];

        $secretUrl = '/api/v1/namespaces/' . $namespace . '/secrets/' . $secretName;
        list($sCode, $sBody, $sErr) = $this->k8s_request('GET', $secretUrl);

        if ($sCode === 404) {
            $createUrl = '/api/v1/namespaces/' . $namespace . '/secrets';
            $this->log('Secret:' . $secretName . ' 不存在，正在创建...');
            list($cCode, $cBody, $cErr) = $this->k8s_request('POST', $createUrl, json_encode($secretPayload));
            if ($cCode < 200 || $cCode >= 300) throw new Exception("创建Secret失败 (HTTP $cCode): $cBody | $cErr");
            $this->log('Secret:' . $namespace . ' 创建成功');
        } elseif ($sCode >= 200 && $sCode < 300) {
            $this->log('Secret:' . $secretName . ' 已存在，正在更新...');
            $patch = ['data' => $secretPayload['data'], 'type' => 'kubernetes.io/tls'];
            list($pCode, $pBody, $pErr) = $this->k8s_request('PATCH', $secretUrl, json_encode($patch));
            if ($pCode < 200 || $pCode >= 300) throw new Exception("更新Secret失败 (HTTP $pCode): $pBody | $pErr");
            $this->log('Secret:' . $secretName . ' 更新成功');
        } else {
            throw new Exception("获取Secret失败 (HTTP $sCode): $sBody | $sErr");
        }

        // Bind Secret to specified Ingresses (merge spec.tls & hosts) ----
        if (!empty($config['ingresses'])) {
            $ingressUrl = '/apis/networking.k8s.io/v1/namespaces/' . $namespace . '/ingresses';
            foreach (explode(',', $config['ingresses']) as $ingName) {
                list($gCode, $gBody, $gErr) = $this->k8s_request('GET', $ingressUrl . '/' . $ingName);
                if ($gCode < 200 || $gCode >= 300) throw new Exception("获取Ingress '$ingName' 失败 (HTTP $gCode): $gBody | $gErr");
                $ing = json_decode($gBody, true);
                if (!$ing) throw new Exception("解析Ingress '$ingName' JSON失败: $gBody");

                // collect hosts from spec.rules
                $hosts = [];
                foreach (($ing['spec']['rules'] ?? []) as $rule) {
                    if (!empty($rule['host'])) $hosts[] = $rule['host'];
                }
                $hosts = array_values(array_unique($hosts));

                // merge/ensure spec.tls entry
                $tls = $ing['spec']['tls'] ?? [];
                $found = false;
                foreach ($tls as &$entry) {
                    if (($entry['secretName'] ?? '') === $secretName) {
                        $found = true;
                        $existingHosts = $entry['hosts'] ?? [];
                        $entry['hosts'] = array_values(array_unique(array_merge($existingHosts, $hosts)));
                    }
                }
                unset($entry);
                if (!$found) {
                    $tls[] = ['secretName' => $secretName, 'hosts' => $hosts];
                }

                $patch = ['spec' => ['tls' => $tls]];
                list($iCode, $iBody, $iErr) = $this->k8s_request('PATCH', $ingressUrl . '/' . $ingName, json_encode($patch, JSON_UNESCAPED_SLASHES));
                if ($iCode < 200 || $iCode >= 300) throw new Exception("更新Ingress '$ingName' 失败 (HTTP $iCode): $iBody | $iErr");
                $this->log("Ingress '$ingName' 更新TLS成功");
            }
        }
    }

    private function parse()
    {
        $kcfg = Yaml::parse($this->kubeconfig);
        if (!$kcfg) throw new Exception('Kubeconfig格式错误');
        $curr = $kcfg['current-context'] ?? null;
        if (!$curr) throw new Exception('Kubeconfig缺少current-context');

        $contexts = $this->index_by_name($kcfg['contexts'] ?? []);
        $clusters = $this->index_by_name($kcfg['clusters'] ?? []);
        $users    = $this->index_by_name($kcfg['users'] ?? []);

        $ctx = $contexts[$curr] ?? null;
        if (!$ctx) throw new Exception("Kubeconfig中找不到current-context: $curr");

        $clusterName = $ctx['context']['cluster'] ?? null;
        $userName    = $ctx['context']['user'] ?? null;
        if (!$clusterName || !$userName) throw new Exception("Kubeconfig中context缺少cluster或user: $curr");

        $cluster = $clusters[$clusterName] ?? null;
        $user = $users[$userName] ?? null;
        if (!$cluster) throw new Exception("Kubeconfig中找不到cluster: $clusterName");
        if (!$user) throw new Exception("Kubeconfig中找不到user: $userName");

        $this->server = $cluster['cluster']['server'] ?? null;
        if (!$this->server) throw new Exception("Kubeconfig中找不到cluster.server");
        $this->server = rtrim($this->server, '/');

        $this->bearerToken = $user['user']['token'] ?? ($user['user']['auth-provider']['config']['access-token'] ?? null);
        $clientCertFile = $clientKeyFile = null;
        if (!empty($user['user']['client-certificate-data']) && !empty($user['user']['client-key-data'])) {
            $clientCertFile = tempnam(sys_get_temp_dir(), 'kcc_');
            $clientKeyFile  = tempnam(sys_get_temp_dir(), 'kck_');
            file_put_contents($clientCertFile, base64_decode($user['user']['client-certificate-data']));
            file_put_contents($clientKeyFile,  base64_decode($user['user']['client-key-data']));
        } elseif (!empty($user['user']['client-certificate']) && !empty($user['user']['client-key'])) {
            $clientCertFile = $user['user']['client-certificate'];
            $clientKeyFile  = $user['user']['client-key'];
        }
        $this->tls = ['cert' => $clientCertFile, 'key' => $clientKeyFile];
    }

    private function verify()
    {
        $this->parse();
        list($vCode, $vBody, $vErr) = $this->k8s_request('GET', '/version');
        if ($vErr) throw new Exception("连接Kubernetes API服务器失败: $vErr");
        if ($vCode != 200) throw new Exception("连接Kubernetes API服务器失败: HTTP $vCode $vBody");
    }

    private function k8s_request($method, $path, $body = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->server . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $headers = ['Accept: application/json'];
        if ($this->bearerToken) $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if (!empty($this->tls['cert']) && !empty($this->tls['key'])) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->tls['cert']);
            curl_setopt($ch, CURLOPT_SSLKEY,  $this->tls['key']);
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$code, $resp, $err];
    }

    private function index_by_name($arr)
    {
        $out = [];
        foreach ($arr as $item) {
            if (isset($item['name'])) $out[$item['name']] = $item;
        }
        return $out;
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
