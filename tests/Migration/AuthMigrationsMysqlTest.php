<?php

declare(strict_types=1);

namespace Tests\Migration;

use EzPhp\Database\Database;
use EzPhp\Migration\MigrationInterface;
use EzPhp\Orm\Schema\Schema;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Runs every migration this package ships (database/migrations/*.php) up and
 * down against a real MySQL database — the dialect production uses and the
 * one where DDL mistakes (e.g. a DEFAULT on a TEXT column) actually fail.
 *
 * Uses the testing database (DB_TESTING_DATABASE) with the configured user,
 * like ez-php/orm's MySQL schema tests; any table a migration leaves behind
 * is dropped in tearDown(). Skipped when DB_HOST is not set (standalone runs
 * without MySQL).
 */
#[CoversNothing]
final class AuthMigrationsMysqlTest extends TestCase
{
    private ?PDO $pdo = null;

    private ?Schema $schema = null;

    private string $database = '';

    /**
     * @var list<string>
     */
    private array $tablesBefore = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function migrationFiles(): iterable
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');

        foreach ($files === false ? [] : $files as $file) {
            yield basename($file) => [$file];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) getenv('DB_HOST');

        if ($host === '') {
            $this->markTestSkipped('MySQL not available — set DB_HOST to run this test.');
        }

        $port = (string) (getenv('DB_PORT') ?: '3306');
        $this->database = (string) (getenv('DB_TESTING_DATABASE') ?: 'ez-php_testing');
        $dsn = "mysql:host={$host};port={$port};dbname={$this->database};charset=utf8mb4";
        $username = (string) getenv('DB_USERNAME');
        $password = (string) getenv('DB_PASSWORD');

        $this->pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->schema = new Schema(new Database($dsn, $username, $password));
        $this->tablesBefore = $this->tables();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach (array_diff($this->tables(), $this->tablesBefore) as $leftover) {
                $this->pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $leftover) . '`');
            }
        }

        parent::tearDown();
    }

    public function testThePackageShipsMigrations(): void
    {
        self::assertNotSame([], iterator_to_array(self::migrationFiles()));
    }

    #[DataProvider('migrationFiles')]
    public function testMigrationRunsUpAndDownOnMysql(string $file): void
    {
        self::assertNotNull($this->schema);
        $schema = $this->schema;

        /** @var mixed $migration */
        $migration = require $file;
        self::assertInstanceOf(MigrationInterface::class, $migration);

        $before = $this->tablesBefore;
        $migration->up($schema);
        $created = array_values(array_diff($this->tables(), $before));

        self::assertNotSame([], $created, basename($file) . ' created no table.');

        $migration->down($schema);

        self::assertSame($before, $this->tables(), basename($file) . ' down() did not remove what up() created.');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        self::assertNotNull($this->pdo);
        $stmt = $this->pdo->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name'
        );
        $stmt->execute([$this->database]);

        /** @var list<string> $names */
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }
}
