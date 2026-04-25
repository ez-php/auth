<?php

declare(strict_types=1);

namespace EzPhp\Auth\Jwt;

use EzPhp\Contracts\EzPhpException;

/**
 * Class JwtException
 *
 * Thrown when a JWT token cannot be validated: malformed structure,
 * invalid signature, expired token, or missing required claims.
 *
 * @package EzPhp\Auth\Jwt
 */
final class JwtException extends EzPhpException
{
}
