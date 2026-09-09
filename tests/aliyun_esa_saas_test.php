<?php

// Run with: php tests/aliyun_esa_saas_test.php (no Composer dependencies or network).
namespace app\lib\client {
    class Aliyun
    {
        public static $handler;
        public static $requests = [];

        public function __construct($key, $secret, private $endpoint, private $version, $proxy = false) {}

        public function request($params, $method = 'POST')
        {
            self::$requests[] = [$params, $method, $this->endpoint, $this->version];
            return (self::$handler)($params);
        }
    }
}

namespace {
    use app\lib\client\Aliyun;
    use app\lib\deploy\aliyun as Deployer;

    require __DIR__ . '/../app/lib/DeployInterface.php';
    require __DIR__ . '/../app/lib/deploy/aliyun.php';

    set_error_handler(function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    function check($condition, $message)
    {
        if (!$condition) throw new RuntimeException($message);
    }

    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => '*.example.com'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1, [], 12345);
    openssl_x509_export($cert, $fullchain);
    openssl_pkey_export($key, $privatekey);
    $serial = openssl_x509_parse($fullchain)['serialNumberHex'];

    class MockCloud
    {
        public $uploaded = true;
        public $responses = [];
        public $updateErrors = [];
        public $casError;
        public $siteResponse = ['TotalCount' => 1, 'Sites' => [['SiteId' => 744571165985008]]];

        public function request($params)
        {
            global $serial;
            switch ($params['Action']) {
                case 'ListUserCertificateOrder':
                    if ($this->casError) throw new Exception($this->casError);
                    return ['TotalCount' => $this->uploaded ? 1 : 0, 'CertificateOrderList' => [
                        ['SerialNo' => $serial, 'CertificateId' => 30000478, 'Name' => 'test-certificate'],
                    ]];
                case 'UploadUserCertificate':
                    $this->uploaded = true;
                    return ['CertId' => 30000478];
                case 'ListSites':
                    if ($this->siteResponse instanceof Exception) throw $this->siteResponse;
                    return $this->siteResponse;
                case 'ListCustomHostnames':
                    $domain = $params['Hostname'];
                    $response = $this->responses[$domain] ?? hostnames([$domain]);
                    if ($response instanceof Exception) throw $response;
                    return $response;
                case 'UpdateCustomHostname':
                    if (isset($this->updateErrors[$params['HostnameId']])) {
                        throw new Exception($this->updateErrors[$params['HostnameId']]);
                    }
                    return ['RequestId' => 'mock-request'];
            }
            throw new RuntimeException('Unexpected API action: ' . $params['Action']);
        }
    }

    function hostnames($domains)
    {
        return ['TotalCount' => count($domains), 'Hostnames' => array_map(fn($domain) => [
            'Hostname' => $domain, 'SiteId' => '744571165985008', 'HostnameId' => (string)(crc32(strtolower($domain)) + 1),
        ], $domains)];
    }

    function deploy($input, $cloud = null, $overrides = [])
    {
        global $fullchain, $privatekey;
        $cloud ??= new MockCloud();
        Aliyun::$handler = [$cloud, 'request'];
        Aliyun::$requests = [];
        $client = new Deployer(['AccessKeyId' => 'test-key', 'AccessKeySecret' => 'test-secret']);
        $logs = [];
        $client->setLogger(function ($message) use (&$logs) { $logs[] = $message; });
        $info = [];
        $error = null;
        try {
            $client->deploy($fullchain, $privatekey, array_merge([
                'product' => 'esa_saas', 'esa_sitename' => 'example.net',
                'esa_saas_sitename' => $input, 'region' => 'cn-hangzhou',
            ], $overrides), $info);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        return ['error' => $error, 'logs' => implode("\n", $logs), 'info' => $info, 'requests' => Aliyun::$requests];
    }

    function calls($result, $action)
    {
        return array_values(array_filter($result['requests'], fn($call) => $call[0]['Action'] === $action));
    }

    function queried($result)
    {
        return array_map(fn($call) => $call[0]['Hostname'], calls($result, 'ListCustomHostnames'));
    }

    $tests = [];
    $tests['legacy single domain'] = function () {
        $result = deploy('a.example.com');
        check($result['error'] === null, 'Single domain failed');
        check(count(calls($result, 'UpdateCustomHostname')) === 1, 'Expected one update');
        check($result['info']['cert_id'] === 30000478, 'Certificate info not saved');
    };
    $tests['newline normalization and stable deduplication'] = function () {
        $result = deploy(" \tA.Example.com \r\n\r\nb.example.com\rc.example.com\na.EXAMPLE.com\n b.example.com \n");
        check($result['error'] === null, 'Normalized deployment failed');
        check(queried($result) === ['a.example.com', 'b.example.com', 'c.example.com'], 'Incorrect order or duplicates');
        check(str_contains($result['logs'], '成功 3 个，失败 0 个'), 'Success summary missing');
    };
    $tests['commas are not separators'] = function () {
        foreach ([',', '，'] as $separator) {
            $input = 'a.example.com' . $separator . 'b.example.com';
            $cloud = new MockCloud();
            $cloud->responses[$input] = hostnames([]);
            $result = deploy($input, $cloud);
            check(queried($result) === [$input], 'Comma unexpectedly split input');
            check(count(calls($result, 'UpdateCustomHostname')) === 0, 'Unmatched input updated');
        }
    };
    $tests['empty and non-string input'] = function () {
        foreach (['', " \n\r\n\t\r ", null, []] as $input) {
            $result = deploy($input);
            check($result['error'] !== null, 'Invalid input accepted');
            check(count(calls($result, 'ListSites')) === 0, 'Invalid input reached ESA');
        }
    };
    $tests['one certificate upload and site lookup in both regions'] = function () {
        foreach (['cn-hangzhou', 'ap-southeast-1'] as $region) {
            $cloud = new MockCloud();
            $cloud->uploaded = false;
            $result = deploy("a.example.com\nb.example.com", $cloud, ['region' => $region]);
            check($result['error'] === null, 'Regional deployment failed');
            check(count(calls($result, 'UploadUserCertificate')) === 1, 'Certificate uploaded more than once');
            check(count(calls($result, 'ListSites')) === 1, 'Site queried more than once');
            foreach (calls($result, 'ListCustomHostnames') as [$params, $method, $endpoint, $version]) {
                check($params['SiteId'] === 744571165985008 && $params['NameMatchType'] === 'exact', 'Incorrect query');
                check(!isset($params['SiteName']) && !isset($params['SiteSearchType']), 'Old query parameters retained');
                check($method === 'GET' && $endpoint === "esa.$region.aliyuncs.com" && $version === '2024-09-10', 'Incorrect ESA client');
            }
            foreach (calls($result, 'UpdateCustomHostname') as [$params, $method]) {
                check($params['CasId'] === 30000478 && $params['CasRegion'] === $region, 'Incorrect certificate binding');
                check($params['SslFlag'] === 'on' && $params['CertType'] === 'cas' && $method === 'POST', 'Incorrect update');
            }
        }
    };
    $tests['select matching record instead of first result'] = function () {
        $cloud = new MockCloud();
        $cloud->responses['a.example.com'] = hostnames(['wrong.example.com', 'A.EXAMPLE.COM']);
        $result = deploy('a.example.com', $cloud);
        check($result['error'] === null, 'Matching record rejected');
        check(calls($result, 'UpdateCustomHostname')[0][0]['HostnameId'] === crc32('a.example.com') + 1, 'Wrong record updated');
    };
    $tests['invalid results fail individually and later domains continue'] = function () {
        $wrongSite = hostnames(['bad.example.com']);
        $wrongSite['Hostnames'][0]['SiteId'] = 999;
        $badId = hostnames(['bad.example.com']);
        unset($badId['Hostnames'][0]['HostnameId']);
        $invalid = [hostnames([]), [], ['TotalCount' => 1, 'Hostnames' => null],
            hostnames(['other.example.com']), $wrongSite, $badId,
            hostnames(['bad.example.com', 'bad.example.com']), new Exception('query unavailable')];
        foreach ([0, -1, 'invalid', '1.5'] as $id) {
            $response = hostnames(['bad.example.com']);
            $response['Hostnames'][0]['HostnameId'] = $id;
            $invalid[] = $response;
        }
        foreach ($invalid as $response) {
            $cloud = new MockCloud();
            $cloud->responses['bad.example.com'] = $response;
            $result = deploy("bad.example.com\ngood.example.com", $cloud);
            check(str_contains($result['error'] ?? '', '成功 1 个，失败 1 个'), 'Incorrect partial result');
            check(count(calls($result, 'UpdateCustomHostname')) === 1, 'Invalid record updated or later target skipped');
            check(calls($result, 'UpdateCustomHostname')[0][0]['HostnameId'] === crc32('good.example.com') + 1, 'Wrong target updated');
            check(str_contains($result['logs'], '[Error] ESA SAAS站点 bad.example.com 查询失败'), 'Domain failure missing');
        }
    };
    $tests['update failure continues, keeps full log and bounds summary'] = function () {
        $cloud = new MockCloud();
        $message = str_repeat('远端暂时不可用', 100);
        $cloud->updateErrors[crc32('bad.example.com') + 1] = $message;
        $result = deploy("bad.example.com\ngood.example.com", $cloud);
        check(str_contains($result['error'] ?? '', '成功 1 个，失败 1 个'), 'Failure not aggregated');
        check(str_contains($result['error'], '详见部署日志') && str_contains($result['error'], 'bad.example.com'), 'Summary lacks context');
        check(strlen($result['error']) <= 300 && mb_check_encoding($result['error'], 'UTF-8'), 'Summary exceeds storage or breaks UTF-8');
        check(str_contains($result['logs'], $message), 'Full API error lost');
        check(count(calls($result, 'UpdateCustomHostname')) === 2, 'Later target skipped');
    };
    $tests['all targets fail without reporting success'] = function () {
        $cloud = new MockCloud();
        $cloud->responses['a.example.com'] = hostnames([]);
        $cloud->updateErrors[crc32('b.example.com') + 1] = 'update unavailable';
        $result = deploy("a.example.com\nb.example.com", $cloud);
        check(str_contains($result['error'] ?? '', '成功 0 个，失败 2 个'), 'All-failed result incorrect');
        check(!str_contains($result['logs'], '证书添加成功'), 'False success logged');
    };
    $tests['whole-task retry revisits successful targets and reuses certificate'] = function () {
        $cloud = new MockCloud();
        $cloud->uploaded = false;
        $cloud->updateErrors[crc32('b.example.com') + 1] = 'temporary failure';
        $first = deploy("a.example.com\nb.example.com", $cloud);
        check($first['error'] !== null, 'First attempt should fail');
        $cloud->updateErrors = [];
        $retry = deploy("a.example.com\nb.example.com", $cloud);
        check($retry['error'] === null, 'Retry failed');
        check(queried($retry) === ['a.example.com', 'b.example.com'], 'Retry skipped a target');
        check(count(calls($retry, 'UpdateCustomHostname')) === 2, 'Retry did not redeploy all targets');
        check(count(calls($retry, 'UploadUserCertificate')) === 0, 'Retry uploaded duplicate certificate');
    };
    $tests['shared prerequisites fail before any hostname update'] = function () {
        foreach ([hostnames([]), ['TotalCount' => 1, 'Sites' => [[]]], new Exception('site query unavailable')] as $response) {
            $cloud = new MockCloud();
            $cloud->siteResponse = $response;
            $result = deploy("a.example.com\nb.example.com", $cloud);
            check($result['error'] !== null && queried($result) === [], 'Site failure did not stop task');
        }
        $cloud = new MockCloud();
        $cloud->casError = 'certificate service unavailable';
        $result = deploy('a.example.com', $cloud);
        check($result['error'] !== null && count(calls($result, 'ListSites')) === 0, 'Certificate failure reached ESA');
        $result = deploy('a.example.com', null, ['esa_sitename' => '  ']);
        check($result['error'] !== null && count(calls($result, 'ListSites')) === 0, 'Empty site accepted');
    };

    foreach ($tests as $name => $test) {
        $test();
        echo "PASS $name\n";
    }
    echo count($tests) . " tests passed\n";
}
