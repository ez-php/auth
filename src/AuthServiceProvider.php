<?php

declare(strict_types=1);

namespace EzPhp\Auth;

use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class AuthServiceProvider
 *
 * Binds the Auth singleton and, in boot(), sets the static instance so that
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
        $this->app->bind(Auth::class, function (ContainerInterface $app): Auth {
            // Not bound: token/session auth without automatic user restoration is
            // still available. Bound but failing (e.g. its DB connection): the error
            // surfaces instead of silently building a provider-less Auth.
            return new Auth(
                $app->has(UserProviderInterface::class) ? $app->make(UserProviderInterface::class) : null,
            );
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        // Point the static facade at the container-managed instance. Done here rather
        // than in the binding closure so resolving Auth has no global side effect and
        // Auth::user() / Auth::check() never fall back to a provider-less instance.
        Auth::setInstance($this->app->make(Auth::class));
    }
}
