<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Auth\Console\TokenCommand;
use EzPhp\Auth\PersonalAccessToken;
use EzPhp\Auth\PersonalAccessTokenManager;
use EzPhp\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class TokenCommandTest
 *
 * @package Tests\Console
 */
#[CoversClass(TokenCommand::class)]
#[UsesClass(PersonalAccessTokenManager::class)]
#[UsesClass(PersonalAccessToken::class)]
final class TokenCommandTest extends TestCase
{
    private Database $db;

    private TokenCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Database('sqlite::memory:', '', '');
        $this->db->getPdo()->exec(
            'CREATE TABLE personal_access_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                abilities TEXT NOT NULL DEFAULT "*",
                last_used_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL
            )'
        );

        $this->command = new TokenCommand(new PersonalAccessTokenManager($this->db));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function runCommand(array $args): array
    {
        ob_start();
        $code = $this->command->handle($args);

        return [$code, (string) ob_get_clean()];
    }

    private function tokenCount(): int
    {
        $count = $this->db->getPdo()->query('SELECT COUNT(*) FROM personal_access_tokens');
        $this->assertNotFalse($count);

        return (int) $count->fetchColumn();
    }

    public function test_metadata(): void
    {
        $this->assertSame('auth:token', $this->command->getName());
        $this->assertNotSame('', $this->command->getDescription());
        $this->assertStringContainsString('auth:token <user_id> <name>', $this->command->getHelp());
    }

    public function test_missing_user_id_fails_without_creating_a_token(): void
    {
        [$code, $out] = $this->runCommand([]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('user_id', $out);
        $this->assertSame(0, $this->tokenCount());
    }

    public function test_missing_name_fails_without_creating_a_token(): void
    {
        [$code, $out] = $this->runCommand(['7']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('name', $out);
        $this->assertSame(0, $this->tokenCount());
    }

    public function test_creates_token_with_defaults_and_prints_raw_token_once(): void
    {
        [$code, $out] = $this->runCommand(['7', 'CI pipeline']);

        $this->assertSame(0, $code);
        $this->assertSame(1, $this->tokenCount());
        $this->assertStringContainsString('CI pipeline', $out);
        $this->assertStringContainsString('Abilities: *', $out);
        $this->assertStringContainsString('Expires  : never', $out);

        $raw = preg_match('/^  ([0-9a-f]{80})$/m', $out, $m) === 1 ? $m[1] : '';
        $this->assertNotSame('', $raw, 'raw token is printed');
        $this->assertNotNull((new PersonalAccessTokenManager($this->db))->find($raw));
    }

    public function test_abilities_and_expiry_options_are_applied(): void
    {
        [$code, $out] = $this->runCommand(['7', 'deploy', '--abilities=read, write,,', '--expires=3600']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Abilities: read, write', $out);
        $this->assertStringNotContainsString('Expires  : never', $out);
        $this->assertMatchesRegularExpression('/Expires  : \d{4}-\d{2}-\d{2} /', $out);
    }

    public function test_empty_abilities_list_falls_back_to_wildcard(): void
    {
        [, $out] = $this->runCommand(['7', 'x', '--abilities=,']);

        $this->assertStringContainsString('Abilities: *', $out);
    }
}
