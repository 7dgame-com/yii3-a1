<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\IdentityAuthorityClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real loopback HTTP verifies the adapter, with an isolated contract fixture. */
final class IdentityAuthorityHttpTest extends TestCase
{
    private static mixed $process;
    private static string $directory;
    private static string $url;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if ($socket === false) throw new RuntimeException('Loopback test listener is unavailable.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$url = 'http://' . $address;
        self::$directory = sys_get_temp_dir() . '/yii3-identity-http-' . bin2hex(random_bytes(8));
        mkdir(self::$directory, 0700);
        file_put_contents(self::$directory . '/router.php', <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$subject = ['id'=>42,'username'=>'fixture-user','nickname'=>'fixture','status'=>10,'roles'=>['user']];
$tokens = ['accessToken'=>'fixture-access','refreshToken'=>'fixture-refresh','expires'=>'2026-10-12T12:00:00.000Z'];
if (str_starts_with($path, '/internal/')) {
    $expected = str_starts_with($path, '/internal/yii3/') ? 'fixture-auth' : 'fixture-iam';
    if (($_SERVER['HTTP_X_IDENTITY_INTERNAL_TOKEN'] ?? '') !== $expected) {
        http_response_code(401); echo '{}'; return;
    }
}
if ($path === '/internal/yii3/refresh') {
    if (($body['refreshToken'] ?? '') === 'outage') { http_response_code(503); echo '{}'; return; }
    if (($body['refreshToken'] ?? '') === 'invalid') { http_response_code(401); echo '{}'; return; }
    if (($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '') !== 'same-operation') { http_response_code(400); echo '{}'; return; }
    echo json_encode(['authTokens'=>$tokens,'subject'=>$subject]); return;
}
if ($path === '/internal/yii3/login-code') {
    if (($body['operation'] ?? '') === 'context') { echo '{"frontendDomain":"dev.xrugc.com"}'; return; }
    echo json_encode(['authTokens'=>$tokens,'subject'=>$subject,'frontendDomain'=>'dev.xrugc.com']); return;
}
if ($path === '/internal/auth/verify-access-token') { echo json_encode(['data'=>$subject]); return; }
if ($path === '/internal/identity/subjects/42' && $_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode(['data'=>$subject]); return; }
if ($path === '/v1/auth/login') { echo json_encode(['success'=>true,'token'=>$tokens]); return; }
http_response_code(404); echo '{}';
PHP);
        self::$process = proc_open([PHP_BINARY, '-S', $address, self::$directory . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, self::$directory);
        if (!is_resource(self::$process)) throw new RuntimeException('Loopback fixture could not start.');
        for ($i = 0; $i < 100; $i++) {
            $ready = @stream_socket_client('tcp://' . $address, $error, $message, 0.05);
            if (is_resource($ready)) { fclose($ready); return; }
            usleep(10000);
        }
        throw new RuntimeException('Loopback fixture did not become ready.');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$process) && is_resource(self::$process)) { proc_terminate(self::$process); proc_close(self::$process); }
        if (isset(self::$directory)) { @unlink(self::$directory . '/router.php'); @rmdir(self::$directory); }
    }

    private function client(): IdentityAuthorityClient
    {
        return new IdentityAuthorityClient('identity', self::$url, 'fixture-auth', 'fixture-iam', 1000, true);
    }

    public function testRealHttpScopesBodyAndIdempotencyReachTheSingleAuthority(): void
    {
        $client = $this->client();
        self::assertSame(42, $client->login('fixture', 'fixture-password')['subject']['id']);
        self::assertSame(42, $client->deviceAccount(42)['id']);
        $response = $client->refresh('fixture-refresh', false, ['idempotencyKey' => 'same-operation']);
        self::assertSame('fixture-refresh', $response['authTokens']['refreshToken']);
        self::assertSame('2026-10-12 20:00:00', $response['authTokens']['expires']);
        self::assertSame('dev.xrugc.com', $client->loginCode('fixture-code', true)['frontendDomain']);
        self::assertSame(42, $client->loginCode('fixture-code')['subject']['id']);
    }

    public function testRealHttpInvalidAndUnavailableResponsesStayDistinctAndDoNotFallback(): void
    {
        foreach (['invalid' => 401, 'outage' => 503] as $input => $status) {
            try { $this->client()->refresh($input, true, ['idempotencyKey' => 'same-operation']); self::fail('Must fail.'); }
            catch (RuntimeException $error) { self::assertSame($status, $error->getCode()); }
        }
    }
}
