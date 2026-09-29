<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DeviceSnService;
use App\Service\JwtService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeviceSnJwtTest extends TestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        $this->keyFile = tempnam(sys_get_temp_dir(), 'sn-jwt');
        file_put_contents($this->keyFile, 'y1-test-secret-long-enough-for-hs256');
    }

    protected function tearDown(): void
    {
        unlink($this->keyFile);
    }

    public function testAccessUsesExistingY1SigningAndChecksAccountBindingWithoutRequiringEnabledSn(): void
    {
        $sn = $this->createMock(DeviceSnService::class);
        $sn->expects($this->exactly(2))->method('authorizeSession')->with(19, 42, false);
        $jwt = new JwtService($this->keyFile, null, $sn);
        $token = $jwt->generateToken(42, ['auth_method' => 'device_sn', 'device_sn_id' => 19]);
        $this->assertSame(['user_id' => 42, 'auth_method' => 'device_sn', 'device_sn_id' => 19], $jwt->parseToken($token));
        $this->assertTrue($jwt->validateToken($token));
        $header = json_decode(base64_decode(explode('.', $token)[0]), true);
        $this->assertSame('HS256', $header['alg']);
        $this->assertNull((new JwtService($this->keyFile))->parseToken($token));
    }

    public function testDeletedPromotedOrUnavailableAccountRejectsExistingAccess(): void
    {
        $sn = $this->createMock(DeviceSnService::class);
        $sn->method('authorizeSession')->willThrowException(new RuntimeException('Account unavailable.', 401));
        $jwt = new JwtService($this->keyFile, null, $sn);
        $token = $jwt->generateToken(42, ['auth_method' => 'device_sn', 'device_sn_id' => 19]);
        $this->assertNull($jwt->parseToken($token));
        $this->assertFalse($jwt->validateToken($token));
        $this->assertSame(['user_id' => 42], $jwt->parseToken($jwt->generateToken(42)));
    }

    public function testMalformedSignedSourceCannotBecomeAnOrdinarySession(): void
    {
        $jwt = new JwtService($this->keyFile);
        foreach ([['auth_method' => 'device_sn'], ['device_sn_id' => 19],
            ['auth_method' => 'device_sn', 'device_sn_id' => '19'], ['auth_method' => 'password', 'device_sn_id' => 19]] as $context) {
            $encode = static fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
            $data = $encode(['alg' => 'HS256', 'typ' => 'JWT']) . '.' . $encode(['uid' => 42, 'iat' => time(), 'exp' => time() + 3600] + $context);
            $token = $data . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $data, trim(file_get_contents($this->keyFile)), true)), '+/', '-_'), '=');
            $this->assertNull($jwt->parseToken($token));
        }
    }
}
