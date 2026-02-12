<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Clock\ClockInterface;

/**
 * JWT token generation and validation service.
 *
 * Uses lcobucci/jwt v5 with HS256 symmetric signing.
 * Key is read from a file path specified by the JWT_KEY environment variable.
 *
 * Replaces Yii2's bizley/jwt bridge package.
 *
 * @see Requirements 3.1, 3.6
 */
final class JwtService
{
    private Configuration $config;

    /**
     * Token time-to-live in seconds (3 hours).
     */
    private int $ttl = 10800;

    private ClockInterface $clock;

    /**
     * @param string $keyFilePath Path to the file containing the JWT signing key.
     * @param ClockInterface|null $clock Optional clock for testing. Defaults to system clock.
     */
    public function __construct(string $keyFilePath, ?ClockInterface $clock = null)
    {
        $keyContent = trim(file_get_contents($keyFilePath));

        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($keyContent),
        );

        $this->clock = $clock ?? new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
            }
        };
    }

    /**
     * Generate a JWT access token for the given user ID.
     *
     * The token includes:
     * - user_id claim
     * - Issued at (iat) timestamp
     * - Expiration (exp) timestamp (current time + 3 hours)
     *
     * @param int $userId The user ID to encode in the token.
     * @return string The encoded JWT token string.
     */
    public function generateToken(int $userId): string
    {
        $now = $this->clock->now();

        $token = $this->config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify("+{$this->ttl} seconds"))
            ->withClaim('uid', $userId)
            ->getToken($this->config->signer(), $this->config->signingKey());

        return $token->toString();
    }

    /**
     * Parse a JWT token and extract the user identity.
     *
     * @param string $token The JWT token string to parse.
     * @return array{user_id: int}|null The parsed claims or null if parsing/validation fails.
     */
    public function parseToken(string $token): ?array
    {
        try {
            $parsedToken = $this->config->parser()->parse($token);

            if (!$parsedToken instanceof Plain) {
                return null;
            }

            // Validate signature and expiry
            $constraints = $this->getValidationConstraints();
            if (!$this->config->validator()->validate($parsedToken, ...$constraints)) {
                return null;
            }

            $userId = $parsedToken->claims()->get('uid');
            if ($userId === null) {
                return null;
            }

            return ['user_id' => (int) $userId];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validate a JWT token (signature and expiry).
     *
     * @param string $token The JWT token string to validate.
     * @return bool True if the token is valid, false otherwise.
     */
    public function validateToken(string $token): bool
    {
        try {
            $parsedToken = $this->config->parser()->parse($token);

            if (!$parsedToken instanceof Plain) {
                return false;
            }

            $constraints = $this->getValidationConstraints();

            return $this->config->validator()->validate($parsedToken, ...$constraints);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the validation constraints for token verification.
     *
     * Uses LooseValidAt instead of StrictValidAt because we only set iat and exp
     * (not nbf), and StrictValidAt requires all three to be present.
     *
     * @return array<\Lcobucci\JWT\Validation\Constraint>
     */
    private function getValidationConstraints(): array
    {
        return [
            new SignedWith($this->config->signer(), $this->config->verificationKey()),
            new LooseValidAt($this->clock),
        ];
    }
}
