<?php

declare(strict_types=1);

namespace Tests\Jwt;

use EzPhp\Auth\Jwt\JwtException;
use EzPhp\Auth\Jwt\JwtManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class JwtManagerTest
 *
 * @package Tests\Jwt
 */
#[CoversClass(JwtManager::class)]
#[CoversClass(JwtException::class)]
final class JwtManagerTest extends TestCase
{
    private JwtManager $jwt;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new JwtManager(secret: 'test-secret', ttl: 3600);
    }

    // ─── issue() ──────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_issue_returns_three_segment_string(): void
    {
        $token = $this->jwt->issue(42);

        $this->assertSame(3, count(explode('.', $token)));
    }

    /**
     * @return void
     */
    public function test_issue_with_string_subject(): void
    {
        $token = $this->jwt->issue('uuid-abc');
        $claims = $this->jwt->validate($token);

        $this->assertSame('uuid-abc', $claims['sub']);
    }

    /**
     * @return void
     */
    public function test_issue_with_integer_subject(): void
    {
        $token = $this->jwt->issue(99);
        $claims = $this->jwt->validate($token);

        $this->assertSame(99, $claims['sub']);
    }

    // ─── validate() — happy path ──────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_validate_returns_sub_iat_exp_claims(): void
    {
        $before = time();
        $token = $this->jwt->issue(1);
        $after = time();

        $claims = $this->jwt->validate($token);

        $this->assertSame(1, $claims['sub']);
        $this->assertIsInt($claims['iat']);
        $this->assertIsInt($claims['exp']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual($after, $claims['iat']);
        $this->assertSame($claims['iat'] + 3600, $claims['exp']);
    }

    /**
     * @return void
     */
    public function test_validate_accepts_tokens_from_different_instances_with_same_secret(): void
    {
        $issuer = new JwtManager(secret: 'shared-secret', ttl: 3600);
        $validator = new JwtManager(secret: 'shared-secret', ttl: 7200);

        $token = $issuer->issue(5);
        $claims = $validator->validate($token);

        $this->assertSame(5, $claims['sub']);
    }

    // ─── validate() — failure cases ───────────────────────────────────────────

    /**
     * @return void
     */
    public function test_validate_throws_on_wrong_segment_count(): void
    {
        $this->expectException(JwtException::class);
        $this->jwt->validate('only.two');
    }

    /**
     * @return void
     */
    public function test_validate_throws_on_invalid_signature(): void
    {
        $token = $this->jwt->issue(1);
        $parts = explode('.', $token);
        $tampered = $parts[0] . '.' . $parts[1] . '.invalidsignature';

        $this->expectException(JwtException::class);
        $this->jwt->validate($tampered);
    }

    /**
     * @return void
     */
    public function test_validate_throws_on_tampered_payload(): void
    {
        $token = $this->jwt->issue(1);
        $parts = explode('.', $token);

        // Replace payload with a different one while keeping the original signature.
        $fakePayload = base64_encode(json_encode(['sub' => 999, 'iat' => time(), 'exp' => time() + 9999], JSON_THROW_ON_ERROR));
        $fakePayload = rtrim(strtr($fakePayload, '+/', '-_'), '=');
        $tampered = $parts[0] . '.' . $fakePayload . '.' . $parts[2];

        $this->expectException(JwtException::class);
        $this->jwt->validate($tampered);
    }

    /**
     * @return void
     */
    public function test_validate_throws_on_wrong_secret(): void
    {
        $token = $this->jwt->issue(1);

        $other = new JwtManager(secret: 'wrong-secret', ttl: 3600);

        $this->expectException(JwtException::class);
        $other->validate($token);
    }

    /**
     * @return void
     */
    public function test_validate_throws_on_expired_token(): void
    {
        $expired = new JwtManager(secret: 'test-secret', ttl: -1);
        $token = $expired->issue(1);

        $this->expectException(JwtException::class);
        $this->expectExceptionMessage('expired');
        $this->jwt->validate($token);
    }

    /**
     * @return void
     */
    public function test_validate_throws_on_invalid_base64(): void
    {
        $this->expectException(JwtException::class);
        $this->jwt->validate('!!!.!!!.!!!');
    }

    /**
     * @return void
     */
    public function test_validate_throws_on_unsupported_algorithm(): void
    {
        // Craft a token with alg=RS256 header.
        $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode('{"sub":1,"iat":' . time() . ',"exp":' . (time() + 3600) . '}'), '+/', '-_'), '=');

        $this->expectException(JwtException::class);
        $this->jwt->validate($header . '.' . $payload . '.fakesig');
    }
}
