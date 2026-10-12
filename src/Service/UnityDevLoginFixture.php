<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * Fixed Unity authentication fixture for the develop environment.
 *
 * The raw login key is never stored in the repository. Only its SHA-256
 * digest is kept here, so rotating or revoking the fixture is an exact,
 * isolated code/configuration change. The fixture never enters the shared
 * short-lived login-code Redis protocol.
 */
final class UnityDevLoginFixture
{
    public const DEFAULT_KEY_SHA256 = 'aa733f55cbebde0187eee46795dc01e41eb39c3a9daa3c725c7f02014a175ff8';
    public const DEFAULT_FRONTEND_DOMAIN = 'd.dev.xrugc.com';
    public const USER_ID = 900_000_001;
    public const FAKE_ACCESS_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1aWQiOjkwMDAwMDAwMSwiZGV2X2ZpeHR1cmUiOnRydWUsImV4cCI6NDEwMjQ0NDc5OX0.aW52YWxpZA';
    public const FAKE_REFRESH_TOKEN = 'unity-dev-fixture-refresh-token-not-valid';
    public const FAKE_EXPIRES_AT = '2099-12-31 23:59:59';

    public function __construct(
        private readonly bool $enabled,
        string $environment,
        private readonly string $keySha256 = self::DEFAULT_KEY_SHA256,
        private readonly string $frontendDomain = self::DEFAULT_FRONTEND_DOMAIN,
    ) {
        $environment = strtolower(trim($environment));

        if ($this->enabled && $environment !== 'dev') {
            throw new InvalidArgumentException('Unity dev login fixture may only be enabled when YII_ENV=dev.');
        }

        if ($this->enabled && preg_match('/^[a-f0-9]{64}$/D', $this->keySha256) !== 1) {
            throw new InvalidArgumentException('UNITY_DEV_LOGIN_KEY_SHA256 must be a lowercase SHA-256 digest.');
        }

        if ($this->enabled && !$this->isValidFrontendDomain($this->frontendDomain)) {
            throw new InvalidArgumentException('UNITY_DEV_LOGIN_FRONTEND_DOMAIN must be a valid lowercase hostname.');
        }
    }

    public function matches(string $rawLoginKey): bool
    {
        return $this->enabled
            && hash_equals($this->keySha256, hash('sha256', trim($rawLoginKey)));
    }

    public function nickname(): string
    {
        return 'Unity Dev Fixture';
    }

    /** @return array{id: int, username: string, nickname: string, fixture: true} */
    public function userPayload(): array
    {
        return [
            'id' => self::USER_ID,
            'username' => 'unity_dev_fixture',
            'nickname' => $this->nickname(),
            'fixture' => true,
        ];
    }

    public function frontendDomain(): string
    {
        return $this->frontendDomain;
    }

    /** @return array<string, mixed> */
    public function exchangeResponse(): array
    {
        return [
            'success' => true,
            'message' => 'keyToTokenWithUrl',
            'nickname' => $this->nickname(),
            'token' => [
                // JWT-shaped for client parsing, deliberately signed with an
                // invalid fixed signature so it can never authorize an API.
                'accessToken' => self::FAKE_ACCESS_TOKEN,
                'expires' => self::FAKE_EXPIRES_AT,
                'refreshToken' => self::FAKE_REFRESH_TOKEN,
            ],
            'user' => $this->userPayload(),
            'url' => 'https://' . $this->frontendDomain,
        ];
    }

    /** @return array{success: true, message: string, frontendDomain: string} */
    public function contextResponse(): array
    {
        return [
            'success' => true,
            'message' => 'loginCodeContext',
            'frontendDomain' => $this->frontendDomain,
        ];
    }

    private function isValidFrontendDomain(string $domain): bool
    {
        if ($domain === '' || $domain !== strtolower($domain) || str_ends_with($domain, '.')) {
            return false;
        }

        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
