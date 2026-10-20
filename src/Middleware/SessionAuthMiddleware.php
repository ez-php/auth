<?php

declare(strict_types=1);

namespace EzPhp\Auth\Middleware;

use Closure;
use EzPhp\Auth\Auth;
use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseInterface;

/**
 * Class SessionAuthMiddleware
 *
 * Session guard: lets the request through only when `Auth::check()` reports
 * a logged-in user (the session-cookie flow of `Auth::login()`/`attempt()`),
 * otherwise answers 401 — the counterpart of AuthMiddleware, which checks
 * `Authorization: Bearer`. The session must already be started (e.g. by
 * ez-php/session's StartSessionMiddleware, registered before this one).
 *
 * The rejection is plain text, or the framework's JSON error envelope
 * `{"error":{"code":401,"message":"Unauthenticated."}}` when the Accept header
 * contains application/json — the same check and shape as CsrfMiddleware.
 * Pass `$unauthenticatedResponse` to build it yourself (an SPA's own JSON
 * format, a redirect to the login page, …).
 *
 * @package EzPhp\Auth\Middleware
 */
final readonly class SessionAuthMiddleware implements MiddlewareInterface
{
    public const string MESSAGE = 'Unauthenticated.';

    /**
     * @param (Closure(RequestInterface): ResponseInterface)|null $unauthenticatedResponse Replaces the built-in 401.
     */
    public function __construct(
        private ?Closure $unauthenticatedResponse = null,
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     *
     * @return ResponseInterface
     */
    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {
        if (!Auth::check()) {
            return $this->reject($request);
        }

        /** @var ResponseInterface */
        return $next($request);
    }

    /**
     * @param RequestInterface $request
     *
     * @return ResponseInterface
     */
    private function reject(RequestInterface $request): ResponseInterface
    {
        if ($this->unauthenticatedResponse !== null) {
            return ($this->unauthenticatedResponse)($request);
        }

        $accept = $request->header('accept', '');

        if (is_string($accept) && str_contains($accept, 'application/json')) {
            $json = json_encode(['error' => ['code' => 401, 'message' => self::MESSAGE]], JSON_THROW_ON_ERROR);

            return (new Response($json, 401))->withHeader('Content-Type', 'application/json');
        }

        return new Response(self::MESSAGE, 401);
    }
}
