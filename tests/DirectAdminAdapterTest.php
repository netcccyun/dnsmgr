<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/app/lib/DeployInterface.php';

use app\lib\deploy\directadmin;

final class FakeDirectAdminTransport
{
    public array $requests = [];
    public array $responses = [];

    public function __invoke(string $method, string $url, array $options): array
    {
        $this->requests[] = compact('method', 'url', 'options');
        if (!$this->responses) {
            throw new RuntimeException('No fake response queued');
        }
        return array_shift($this->responses);
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function expectException(callable $callback, string $contains): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        assertTrueValue(str_contains($e->getMessage(), $contains), "exception should contain {$contains}; got {$e->getMessage()}");
        return;
    }
    fwrite(STDERR, "FAIL: expected exception containing {$contains}\n");
    exit(1);
}

require $root . '/app/lib/deploy/directadmin.php';
require $root . '/app/lib/DeployHelper.php';

assertTrueValue(isset(\app\lib\DeployHelper::$deploy_config['directadmin']), 'DirectAdmin must be registered as a native deployment type');
$registration = \app\lib\DeployHelper::$deploy_config['directadmin'];
assertSameValue('DirectAdmin', $registration['name'], 'native deployment type name');
assertTrueValue(isset($registration['inputs']['url'], $registration['inputs']['username'], $registration['inputs']['password']), 'account fields');
assertTrueValue(isset($registration['taskinputs']['domain']), 'deployment task domain field');

// check(): HTTPS URL, Basic authentication, TLS verification and non-destructive login-test endpoint.
$transport = new FakeDirectAdminTransport();
$transport->responses[] = ['code' => 200, 'body' => 'error=0&text=Login+Test+Successful'];
$client = new directadmin([
    'url' => 'https://da.example.test:2222/',
    'username' => 'alice',
    'password' => 'secret-value',
    'verify_tls' => '1',
    'proxy' => '0',
], $transport);
$client->check();
$request = $transport->requests[0];
assertSameValue('GET', $request['method'], 'check method');
assertSameValue('https://da.example.test:2222/CMD_API_LOGIN_TEST', $request['url'], 'check endpoint');
assertSameValue(['alice', 'secret-value'], $request['options']['auth'], 'Basic auth credentials');
assertSameValue(true, $request['options']['verify'], 'TLS verification defaults on');
assertTrueValue(!str_contains(json_encode($request['options']), 'Authorization'), 'adapter should delegate Basic auth and not hand-build/log Authorization header');

// deploy(): submits private key + full chain as one certificate field and never logs secrets.
$transport = new FakeDirectAdminTransport();
$transport->responses[] = ['code' => 200, 'body' => 'error=0&text=Certificate+and+Key+Saved.'];
$logs = [];
$client = new directadmin([
    'url' => 'https://da.example.test:2222',
    'username' => 'alice',
    'password' => 'secret-value',
    'verify_tls' => '1',
    'proxy' => '0',
], $transport);
$client->setLogger(function (string $line) use (&$logs): void { $logs[] = $line; });
$info = [];
$rsaResource = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
]);
assertTrueValue($rsaResource !== false, 'generate RSA test key');
openssl_pkey_export($rsaResource, $rsaPkcs8);
$client->deploy("-----BEGIN CERTIFICATE-----\nleaf-and-chain\n-----END CERTIFICATE-----\n", $rsaPkcs8, ['domain' => 'example.com'], $info);
$request = $transport->requests[0];
assertSameValue('POST', $request['method'], 'deploy method');
assertSameValue('https://da.example.test:2222/CMD_API_SSL', $request['url'], 'deploy endpoint');
assertSameValue('example.com', $request['options']['form_params']['domain'], 'target domain');
assertSameValue('save', $request['options']['form_params']['action'], 'save action');
assertSameValue('paste', $request['options']['form_params']['type'], 'paste type');
$payload = $request['options']['form_params']['certificate'];
assertTrueValue(str_starts_with($payload, '-----BEGIN PRIVATE KEY-----'), 'private key must precede certificate');
assertTrueValue(str_contains($payload, 'leaf-and-chain'), 'full chain is included');
assertTrueValue(!str_contains(implode("\n", $logs), 'BEGIN PRIVATE KEY'), 'private key must not be logged');
assertTrueValue(!str_contains(implode("\n", $logs), 'secret-value'), 'password must not be logged');

// DirectAdmin rejects PKCS#8 ECC keys as "Invalid key"; adapter must convert them to SEC1 EC PRIVATE KEY.
$ecResource = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
assertTrueValue($ecResource !== false, 'generate ECC test key');
openssl_pkey_export($ecResource, $ecPkcs8);
assertTrueValue(str_starts_with($ecPkcs8, '-----BEGIN PRIVATE KEY-----'), 'PHP exports ECC as PKCS#8');
$transport = new FakeDirectAdminTransport();
$transport->responses[] = ['code' => 200, 'body' => 'error=0&text=Certificate+and+Key+Saved.'];
$client = new directadmin([
    'url' => 'https://da.example.test:2222',
    'username' => 'alice',
    'password' => 'secret-value',
    'verify_tls' => '1',
    'proxy' => '0',
], $transport);
$info = [];
$client->deploy("-----BEGIN CERTIFICATE-----\ntest-certificate\n-----END CERTIFICATE-----\n", $ecPkcs8, ['domain' => 'example.com'], $info);
$ecPayload = $transport->requests[0]['options']['form_params']['certificate'];
assertTrueValue(str_starts_with($ecPayload, '-----BEGIN EC PRIVATE KEY-----'), 'ECC key must be converted from PKCS#8 to SEC1 for DirectAdmin');
$tmpEc = tempnam(sys_get_temp_dir(), 'da-ec-');
file_put_contents($tmpEc, strstr($ecPayload, '-----BEGIN CERTIFICATE-----', true));
$parsedEc = openssl_pkey_get_private(file_get_contents($tmpEc));
unlink($tmpEc);
assertTrueValue($parsedEc !== false, 'converted SEC1 key must remain valid');

// Multiple target domains are independently deployed and normalized.
$transport = new FakeDirectAdminTransport();
$transport->responses = [
    ['code' => 200, 'body' => 'error=0&text=Certificate+and+Key+Saved.'],
    ['code' => 200, 'body' => 'error=0&text=Certificate+and+Key+Saved.'],
];
$client = new directadmin([
    'url' => 'https://da.example.test:2222',
    'username' => 'alice',
    'password' => 'secret-value',
    'verify_tls' => '1',
    'proxy' => '0',
], $transport);
$info = [];
$client->deploy('CERT', 'KEY', ['domain' => "example.com\n second.example \n"], $info);
assertSameValue(2, count($transport->requests), 'two domains should make two requests');
assertSameValue('second.example', $transport->requests[1]['options']['form_params']['domain'], 'domain trimming');

// Refuse insecure panel URLs and surface DirectAdmin API errors.
expectException(function (): void {
    new directadmin(['url' => 'http://da.example.test:2222', 'username' => 'a', 'password' => 'b'], new FakeDirectAdminTransport());
}, 'HTTPS');

$transport = new FakeDirectAdminTransport();
$transport->responses[] = ['code' => 200, 'body' => 'error=1&text=Cannot+Execute+Your+Request&details=Domain+not+found'];
$client = new directadmin([
    'url' => 'https://da.example.test:2222',
    'username' => 'alice',
    'password' => 'secret-value',
    'verify_tls' => '1',
    'proxy' => '0',
], $transport);
expectException(fn() => $client->check(), 'Domain not found');

// Fail closed on empty, HTML, malformed, or structurally unexpected successful HTTP responses.
foreach ([
    '',
    '<html><body>Login</body></html>',
    '{malformed json',
    'text=Saved',
] as $unexpectedBody) {
    $transport = new FakeDirectAdminTransport();
    $transport->responses[] = ['code' => 200, 'body' => $unexpectedBody];
    $client = new directadmin([
        'url' => 'https://da.example.test:2222',
        'username' => 'alice',
        'password' => 'secret-value',
        'verify_tls' => '1',
        'proxy' => '0',
    ], $transport);
    expectException(fn() => $client->check(), '响应格式异常');
}

// Reject ambiguous/malformed panel URLs rather than concatenating API endpoints onto them.
foreach ([
    'https://user:pass@da.example.test:2222',
    'https://da.example.test:2222/path',
    'https://da.example.test:2222?query=1',
    'https://da.example.test:2222/#fragment',
    'https:///missing-host',
] as $invalidUrl) {
    expectException(function () use ($invalidUrl): void {
        new directadmin(['url' => $invalidUrl, 'username' => 'a', 'password' => 'b'], new FakeDirectAdminTransport());
    }, '面板地址');
}

echo "All DirectAdmin adapter tests passed.\n";
