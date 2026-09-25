<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Auth\Console\AuthScaffoldCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class AuthScaffoldCommandTest
 *
 * @package Tests\Console
 */
#[CoversClass(AuthScaffoldCommand::class)]
final class AuthScaffoldCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/ez-php-auth-scaffold-' . uniqid();
        mkdir($this->basePath . '/app/Controllers', 0o755, true);
        mkdir($this->basePath . '/routes', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->basePath);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }

        rmdir($path);
    }

    public function test_name_description_help(): void
    {
        $command = new AuthScaffoldCommand($this->basePath);

        $this->assertSame('auth:scaffold', $command->getName());
        $this->assertNotEmpty($command->getDescription());
        $this->assertNotEmpty($command->getHelp());
    }

    public function test_handle_writes_controller_and_routes_file(): void
    {
        $command = new AuthScaffoldCommand($this->basePath);

        ob_start();
        $code = $command->handle([]);
        ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . '/app/Controllers/AuthController.php');
        $this->assertFileExists($this->basePath . '/routes/auth.php');
    }

    public function test_controller_stub_uses_auth_facade(): void
    {
        $command = new AuthScaffoldCommand($this->basePath);

        ob_start();
        $command->handle([]);
        ob_get_clean();

        $content = file_get_contents($this->basePath . '/app/Controllers/AuthController.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('EzPhp\\Auth\\Auth', $content);
        $this->assertStringContainsString('function login', $content);
        $this->assertStringContainsString('function register', $content);
    }

    public function test_routes_stub_references_auth_controller(): void
    {
        $command = new AuthScaffoldCommand($this->basePath);

        ob_start();
        $command->handle([]);
        ob_get_clean();

        $content = file_get_contents($this->basePath . '/routes/auth.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('AuthController', $content);
    }

    public function test_handle_does_not_overwrite_existing_controller(): void
    {
        file_put_contents($this->basePath . '/app/Controllers/AuthController.php', '// custom');

        $command = new AuthScaffoldCommand($this->basePath);

        ob_start();
        $code = $command->handle([]);
        ob_get_clean();

        $this->assertSame(1, $code);
        $content = file_get_contents($this->basePath . '/app/Controllers/AuthController.php');
        $this->assertSame('// custom', $content);
    }

    /**
     * Regression: the documented `registerCommand(AuthScaffoldCommand::class)` failed
     * because the container cannot autowire a required `string $basePath`.
     *
     * @return void
     */
    public function test_defaults_to_the_working_directory_so_it_can_be_registered_by_class(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);
        chdir($this->basePath);

        try {
            ob_start();
            $exit = (new AuthScaffoldCommand())->handle([]);
            ob_end_clean();
        } finally {
            chdir($cwd);
        }

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->basePath . '/app/Controllers/AuthController.php');
        $this->assertFileExists($this->basePath . '/routes/auth.php');
    }
}
