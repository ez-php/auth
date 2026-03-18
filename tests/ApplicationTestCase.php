<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Testing\ApplicationTestCase as EzPhpApplicationTestCase;

/**
 * Base class for auth module tests that need a bootstrapped Application.
 *
 * The default getBasePath() from EzPhp\Testing\ApplicationTestCase creates a
 * temporary directory with an empty config/ subdirectory. This satisfies
 * ConfigLoader without requiring a real application structure, and keeps all
 * service bindings lazy — the Database and ORM are never resolved unless a
 * test explicitly calls make() on them.
 *
 * Override configureApplication() to register providers or bind services
 * before bootstrap.
 *
 * @package Tests
 */
abstract class ApplicationTestCase extends EzPhpApplicationTestCase
{
}
