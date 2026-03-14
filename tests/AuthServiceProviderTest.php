<?php

declare(strict_types=1);

namespace Tests\Auth;

use EzPhp\Application\Application;
use EzPhp\Application\CoreServiceProviders;
use EzPhp\Auth\Auth;
use EzPhp\Auth\AuthServiceProvider;
use EzPhp\Auth\UserInterface;
use EzPhp\Auth\UserProviderInterface;
use EzPhp\Config\Config;
use EzPhp\Config\ConfigLoader;
use EzPhp\Config\ConfigServiceProvider;
use EzPhp\Console\Command\MakeControllerCommand;
use EzPhp\Console\Command\MakeMiddlewareCommand;
use EzPhp\Console\Command\MakeMigrationCommand;
use EzPhp\Console\Command\MakeProviderCommand;
use EzPhp\Console\Command\MigrateCommand;
use EzPhp\Console\Command\MigrateRollbackCommand;
use EzPhp\Console\Console;
use EzPhp\Console\ConsoleServiceProvider;
use EzPhp\Console\Input;
use EzPhp\Console\Output;
use EzPhp\Container\Container;
use EzPhp\Database\Database;
use EzPhp\Database\DatabaseServiceProvider;
use EzPhp\Exceptions\ApplicationException;
use EzPhp\Exceptions\ContainerException;
use EzPhp\Exceptions\DefaultExceptionHandler;
use EzPhp\Exceptions\ExceptionHandlerServiceProvider;
use EzPhp\Migration\MigrationServiceProvider;
use EzPhp\Migration\Migrator;
use EzPhp\Routing\Route;
use EzPhp\Routing\Router;
use EzPhp\Routing\RouterServiceProvider;
use EzPhp\ServiceProvider\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionException;
use Tests\DatabaseTestCase;

/**
 * Class AuthServiceProviderTest
 *
 * @package Tests\Auth
 */
#[CoversClass(AuthServiceProvider::class)]
#[UsesClass(Auth::class)]
#[UsesClass(Application::class)]
#[UsesClass(Container::class)]
#[UsesClass(CoreServiceProviders::class)]
#[UsesClass(Config::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConfigServiceProvider::class)]
#[UsesClass(Database::class)]
#[UsesClass(DatabaseServiceProvider::class)]
#[UsesClass(MigrationServiceProvider::class)]
#[UsesClass(Migrator::class)]
#[UsesClass(RouterServiceProvider::class)]
#[UsesClass(Route::class)]
#[UsesClass(Router::class)]
#[UsesClass(DefaultExceptionHandler::class)]
#[UsesClass(ExceptionHandlerServiceProvider::class)]
#[UsesClass(ConsoleServiceProvider::class)]
#[UsesClass(Console::class)]
#[UsesClass(MigrateCommand::class)]
#[UsesClass(MigrateRollbackCommand::class)]
#[UsesClass(MakeMigrationCommand::class)]
#[UsesClass(MakeControllerCommand::class)]
#[UsesClass(MakeMiddlewareCommand::class)]
#[UsesClass(MakeProviderCommand::class)]
#[UsesClass(Input::class)]
#[UsesClass(Output::class)]
#[UsesClass(ServiceProvider::class)]
final class AuthServiceProviderTest extends DatabaseTestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Auth::resetInstance();
        parent::tearDown();
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_auth_is_bound_in_container(): void
    {
        $app = new Application();
        $app->register(AuthServiceProvider::class);
        $app->bootstrap();

        $auth = $app->make(Auth::class);

        $this->assertInstanceOf(Auth::class, $auth);
    }

    /**
     */
    public function test_auth_check_returns_false_by_default(): void
    {
        $app = new Application();
        $app->register(AuthServiceProvider::class);
        $app->bootstrap();

        $this->assertFalse(Auth::check());
    }

    /**
     */
    public function test_auth_static_methods_work_after_bootstrap(): void
    {
        $app = new Application();
        $app->register(AuthServiceProvider::class);
        $app->bootstrap();

        $user = new class () implements UserInterface {
            /**
             * @return int
             */
            public function getAuthId(): int
            {
                return 1;
            }
        };

        Auth::login($user);

        $this->assertTrue(Auth::check());
        $this->assertSame($user, Auth::user());
        $this->assertSame(1, Auth::id());

        Auth::logout();

        $this->assertFalse(Auth::check());
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_auth_uses_user_provider_when_bound(): void
    {
        $user = new class () implements UserInterface {
            /**
             * @return int
             */
            public function getAuthId(): int
            {
                return 42;
            }
        };
        $provider = new class ($user) implements UserProviderInterface {
            /**
             * Constructor
             *
             * @param UserInterface $u
             */
            public function __construct(private readonly UserInterface $u)
            {
            }

            /**
             * @param int|string $id
             *
             * @return UserInterface|null
             */
            public function findById(int|string $id): ?UserInterface
            {
                return $this->u->getAuthId() === $id ? $this->u : null;
            }

            /**
             * @param string $token
             *
             * @return UserInterface|null
             */
            public function findByToken(string $token): ?UserInterface
            {
                return null;
            }
        };

        $app = new Application();
        $app->register(AuthServiceProvider::class);
        $app->bootstrap();

        // Bind UserProviderInterface after bootstrap (container now exists),
        // then resolve Auth so the binding closure picks up the provider.
        $app->bind(UserProviderInterface::class, fn (): UserProviderInterface => $provider);
        $app->make(Auth::class);

        Auth::login($user);
        $this->assertSame($user, Auth::user());
    }
}
