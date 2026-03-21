<?php

declare(strict_types=1);

namespace EzPhp\Auth\Middleware;

use EzPhp\Auth\Auth;
use EzPhp\Auth\UserProviderInterface;
use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\Request;
use EzPhp\Http\Response;

/**
 * Class AuthMiddleware
 *
 * Validates Bearer tokens from the Authorization header.
 *
 * Two modes of operation:
 *   1. Static token list — pass an array of accepted tokens.
 *      `new AuthMiddleware(['my-api-key'])`
 *   2. UserProvider — pass a UserProviderInterface to look up users by token.
 *      `new AuthMiddleware(userProvider: $provider)`
 *      When the token resolves to a user, Auth::login($user) is called so that
 *      the user is available via Auth::user() for the rest of the request.
 *
 * @package EzPhp\Auth\Middleware
 */
final readonly class AuthMiddleware implements MiddlewareInterface
{
    /**
     * AuthMiddleware Constructor
     *
     * @param list<string>              $validTokens   Static list of accepted Bearer tokens.
     * @param UserProviderInterface|null $userProvider  Optional provider for dynamic user lookup.
     */
    public function __construct(
        private array $validTokens = [],
        private ?UserProviderInterface $userProvider = null,
    ) {
    }

    /**
     * @param Request  $request
     * @param callable $next
     *
     * @return Response
     */
    public function handle(Request $request, callable $next): Response
    {
        $authorization = $request->header('authorization', '');

        if (!is_string($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            return new Response('Unauthorized', 401);
        }

        $token = substr($authorization, 7);

        if ($this->userProvider !== null) {
            $user = $this->userProvider->findByToken($token);

            if ($user === null) {
                return new Response('Unauthorized', 401);
            }

            Auth::login($user);
        } elseif ($this->validTokens !== [] && !in_array($token, $this->validTokens, true)) {
            return new Response('Unauthorized', 401);
        }

        /** @var Response */
        return $next($request);
    }
}
