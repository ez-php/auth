<?php

declare(strict_types=1);

namespace EzPhp\Auth\Middleware;

use EzPhp\Auth\Auth;
use EzPhp\Auth\Jwt\JwtBlacklist;
use EzPhp\Auth\Jwt\JwtException;
use EzPhp\Auth\Jwt\JwtManager;
use EzPhp\Auth\UserProviderInterface;
use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;

/**
 * Class JwtMiddleware
 *
 * Route-level middleware for stateless JWT authentication.
 *
 * Validates the `Authorization: Bearer <token>` header using HMAC-HS256.
 * Rejects the request with 401 when the token is missing, malformed, expired,
 * or present on the blacklist.
 *
 * When a UserProviderInterface is supplied the resolved `sub` claim is used to
 * look up the user via `findById()`, and `Auth::login()` is called so that
 * `Auth::user()` is available for the rest of the request.
 *
 * @package EzPhp\Auth\Middleware
 */
final readonly class JwtMiddleware implements MiddlewareInterface
{
    /**
     * JwtMiddleware Constructor
     *
     * @param JwtManager              $jwt          Token issuer and validator.
     * @param JwtBlacklist|null       $blacklist    Optional logout blacklist; skipped when null.
     * @param UserProviderInterface|null $userProvider Optional provider; resolves Auth user from sub claim.
     */
    public function __construct(
        private JwtManager $jwt,
        private ?JwtBlacklist $blacklist = null,
        private ?UserProviderInterface $userProvider = null,
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     *
     * @return Response
     */
    public function handle(RequestInterface $request, callable $next): Response
    {
        $authorization = $request->header('authorization', '');

        if (!is_string($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            return new Response('Unauthorized', 401);
        }

        $token = substr($authorization, 7);

        try {
            $claims = $this->jwt->validate($token);
        } catch (JwtException) {
            return new Response('Unauthorized', 401);
        }

        if ($this->blacklist !== null && $this->blacklist->isBlacklisted($token)) {
            return new Response('Unauthorized', 401);
        }

        if ($this->userProvider !== null) {
            /** @var int|string $sub */
            $sub = $claims['sub'];
            $user = $this->userProvider->findById($sub);

            if ($user === null) {
                return new Response('Unauthorized', 401);
            }

            Auth::login($user);
        }

        /** @var Response */
        return $next($request);
    }
}
