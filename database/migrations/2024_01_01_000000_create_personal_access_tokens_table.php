<?php

declare(strict_types=1);

use EzPhp\Contracts\Schema\SchemaInterface;
use EzPhp\Migration\MigrationInterface;
use EzPhp\Orm\Schema\Blueprint;

/**
 * Create the personal_access_tokens table.
 *
 * Copy this file into your application's database/migrations/ directory
 * and run `php ez migrate` to apply it.
 */
return new class () implements MigrationInterface {
    /**
     * @param SchemaInterface $schema
     *
     * @return void
     */
    public function up(SchemaInterface $schema): void
    {
        $schema->create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 255);
            $table->string('name', 255);
            $table->string('token', 64)->unique();
            $table->text('abilities')->default('*');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    /**
     * @param SchemaInterface $schema
     *
     * @return void
     */
    public function down(SchemaInterface $schema): void
    {
        $schema->dropIfExists('personal_access_tokens');
    }
};
