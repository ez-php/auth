<?php

declare(strict_types=1);

namespace EzPhp\Auth\Console;

use EzPhp\Auth\PersonalAccessTokenManager;
use EzPhp\Console\CommandInterface;
use EzPhp\Console\Input;

/**
 * Class TokenCommand
 *
 * Console command: auth:token
 *
 * Generates a personal access token for a user and prints it to the terminal.
 * The raw token is shown once — copy it immediately.
 *
 * Usage:
 *   ez auth:token <user_id> <name> [--abilities=read,write] [--expires=3600]
 *
 * Arguments:
 *   user_id     — The owning user's ID (integer or string)
 *   name        — Human-readable label for the token (e.g. "CI/CD pipeline")
 *
 * Options:
 *   --abilities  — Comma-separated ability list (default: "*")
 *   --expires    — Seconds until the token expires; omit for no expiry
 *
 * @package EzPhp\Auth\Console
 */
final class TokenCommand implements CommandInterface
{
    /**
     * TokenCommand Constructor
     *
     * @param PersonalAccessTokenManager $manager
     */
    public function __construct(private readonly PersonalAccessTokenManager $manager)
    {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'auth:token';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Generate a personal access token for a user';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return <<<'HELP'
            Usage: auth:token <user_id> <name> [--abilities=*] [--expires=<seconds>]

            Arguments:
              user_id      The owning user ID (integer or string)
              name         Human-readable token label (e.g. "CI/CD pipeline")

            Options:
              --abilities  Comma-separated ability list (default: "*" = all)
              --expires    Seconds until expiry; omit for permanent token
            HELP;
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $input = new Input($args);
        $userId = $input->argument(0);
        $name = $input->argument(1);

        if ($userId === null || $userId === '') {
            echo "Error: missing required argument: user_id\n";

            return 1;
        }

        if ($name === null || $name === '') {
            echo "Error: missing required argument: name\n";

            return 1;
        }

        $abilitiesStr = $input->option('abilities', '*');
        $abilities = array_filter(
            array_map('trim', explode(',', $abilitiesStr)),
            static fn (string $a): bool => $a !== '',
        );

        if ($abilities === []) {
            $abilities = ['*'];
        }

        $expiresStr = $input->option('expires', '');
        $expiresIn = $expiresStr !== '' ? (int) $expiresStr : null;

        [$rawToken, $token] = $this->manager->create(
            userId: $userId,
            name: $name,
            abilities: array_values($abilities),
            expiresIn: $expiresIn,
        );

        echo "Personal access token created successfully.\n";
        echo "\n";
        echo "  Token ID : {$token->id}\n";
        echo "  User ID  : {$token->userId}\n";
        echo "  Name     : {$token->name}\n";
        echo '  Abilities: ' . implode(', ', $token->abilities) . "\n";

        if ($token->expiresAt !== null) {
            echo '  Expires  : ' . $token->expiresAt->format('Y-m-d H:i:s') . "\n";
        } else {
            echo "  Expires  : never\n";
        }

        echo "\n";
        echo "  Raw token (copy now — shown once):\n";
        echo "  {$rawToken}\n";

        return 0;
    }
}
