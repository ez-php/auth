<?php

declare(strict_types=1);

namespace EzPhp\Auth\Jwt;

/**
 * Class JwtManager
 *
 * Issues and validates stateless JSON Web Tokens using HMAC-HS256.
 *
 * Token structure: base64url(header) . '.' . base64url(payload) . '.' . base64url(signature)
 *
 * Claims produced by issue():
 *   - sub  — subject (user identifier, int or string)
 *   - iat  — issued-at Unix timestamp
 *   - exp  — expiry Unix timestamp (iat + ttl)
 *
 * Usage:
 *   $manager = new JwtManager(secret: 'secret', ttl: 3600);
 *   $token   = $manager->issue(userId: 42);
 *   $claims  = $manager->validate($token);  // throws JwtException on failure
 *
 * @package EzPhp\Auth\Jwt
 */
final class JwtManager
{
    private const HEADER = '{"alg":"HS256","typ":"JWT"}';

    /**
     * JwtManager Constructor
     *
     * @param string $secret Signing secret — must be kept confidential.
     * @param int    $ttl    Token lifetime in seconds (must be > 0).
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl,
    ) {
    }

    /**
     * Issue a new signed JWT for the given subject.
     *
     * @param int|string $sub User identifier stored in the `sub` claim.
     *
     * @return string Compact, dot-separated JWT string.
     */
    public function issue(int|string $sub): string
    {
        $now = time();

        $payload = json_encode([
            'sub' => $sub,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ], JSON_THROW_ON_ERROR);

        $headerEncoded = $this->base64UrlEncode(self::HEADER);
        $payloadEncoded = $this->base64UrlEncode($payload);
        $signingInput = $headerEncoded . '.' . $payloadEncoded;
        $signature = $this->sign($signingInput);

        return $signingInput . '.' . $signature;
    }

    /**
     * Validate a JWT and return its decoded claims.
     *
     * Checks structure, algorithm, signature, and expiry.
     *
     * @param string $token Compact JWT string.
     *
     * @return array<string, mixed> Decoded payload claims.
     *
     * @throws JwtException When the token is malformed, has an invalid signature, or is expired.
     */
    public function validate(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new JwtException('Malformed JWT: expected 3 segments.');
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $this->validateHeader($headerEncoded);
        $this->validateSignature($headerEncoded . '.' . $payloadEncoded, $signatureEncoded);

        $claims = $this->decodeClaims($payloadEncoded);
        $this->validateExpiry($claims);

        return $claims;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * @param string $headerEncoded
     *
     * @return void
     *
     * @throws JwtException
     */
    private function validateHeader(string $headerEncoded): void
    {
        $header = $this->base64UrlDecode($headerEncoded);

        /** @var mixed $decoded */
        $decoded = json_decode($header, true);

        if (!is_array($decoded)) {
            throw new JwtException('Malformed JWT: invalid header JSON.');
        }

        $alg = $decoded['alg'] ?? null;
        $typ = $decoded['typ'] ?? null;

        if ($alg !== 'HS256' || $typ !== 'JWT') {
            throw new JwtException('Unsupported JWT algorithm or type.');
        }
    }

    /**
     * @param string $signingInput
     * @param string $signatureEncoded
     *
     * @return void
     *
     * @throws JwtException
     */
    private function validateSignature(string $signingInput, string $signatureEncoded): void
    {
        $expected = $this->sign($signingInput);

        if (!hash_equals($expected, $signatureEncoded)) {
            throw new JwtException('JWT signature verification failed.');
        }
    }

    /**
     * @param string $payloadEncoded
     *
     * @return array<string, mixed>
     *
     * @throws JwtException
     */
    private function decodeClaims(string $payloadEncoded): array
    {
        $json = $this->base64UrlDecode($payloadEncoded);

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new JwtException('Malformed JWT: invalid payload JSON.');
        }

        /** @var array<string, mixed> $decoded */
        if (!isset($decoded['sub'], $decoded['iat'], $decoded['exp'])) {
            throw new JwtException('JWT missing required claims (sub, iat, exp).');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return void
     *
     * @throws JwtException
     */
    private function validateExpiry(array $claims): void
    {
        $exp = $claims['exp'] ?? null;

        if (!is_int($exp)) {
            throw new JwtException('JWT exp claim must be an integer.');
        }

        if (time() >= $exp) {
            throw new JwtException('JWT has expired.');
        }
    }

    /**
     * Compute the HMAC-SHA256 signature of the signing input.
     *
     * @param string $signingInput Header + '.' + payload, both base64url encoded.
     *
     * @return string Base64url-encoded signature.
     */
    private function sign(string $signingInput): string
    {
        $raw = hash_hmac('sha256', $signingInput, $this->secret, true);

        return $this->base64UrlEncode($raw);
    }

    /**
     * @param string $data
     *
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param string $data
     *
     * @return string
     *
     * @throws JwtException
     */
    private function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new JwtException('Malformed JWT: invalid base64url encoding.');
        }

        return $decoded;
    }
}
