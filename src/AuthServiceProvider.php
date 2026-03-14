<?php

declare(strict_types=1);

namespace EzPhp\Auth;

use EzPhp\Application\Application;
use EzPhp\ServiceProvider\ServiceProvider;
use Throwable;

/**
 * Class AuthServiceProvider
 *
 * Binds the Auth singleton and sets the static instance so that
 * Auth::user() / Auth::check() / Auth::login() are available
 * without resolving from the container explicitly.
 *
 * If a UserProviderInterface binding is registered before this provider
 * boots, it will be injected into Auth for session-based user restoration.
 *
 * @package EzPhp\Auth
 */
final class AuthServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(Auth::class, function (Application $app): Auth {
            $provider = null;

            try {
                $provider = $app->make(UserProviderInterface::class);
            } catch (Throwable) {
                // UserProviderInterface not bound — token/session auth
                // without automatic user restoration is still available.
            }

            $auth = new Auth($provider);
            Auth::setInstance($auth);

            return $auth;
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        // Auth is resolved lazily. The static instance is set the first time
        // $app->make(Auth::class) or Auth::getInstance() is called.
    }
}
