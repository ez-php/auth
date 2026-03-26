<?php

declare(strict_types=1);

use EzPhp\Contracts\MigrationInterface;

/**
 * Create the personal_access_tokens table.
 *
 * Copy this file into your application's database/migrations/ directory
 * and run `php ez migrate` to apply it.
 */
return new class () implements MigrationInterface {
    /**
     * @param PDO $db
     *
     * @return void
     */
    public function up(PDO $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS personal_access_tokens (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     VARCHAR(255)    NOT NULL,
                name        VARCHAR(255)    NOT NULL,
                token       VARCHAR(64)     NOT NULL,
                abilities   TEXT            NOT NULL DEFAULT \'*\',
                last_used_at DATETIME       NULL DEFAULT NULL,
                expires_at  DATETIME        NULL DEFAULT NULL,
                created_at  DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unique_token (token),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    /**
     * @param PDO $db
     *
     * @return void
     */
    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS personal_access_tokens');
    }
};
