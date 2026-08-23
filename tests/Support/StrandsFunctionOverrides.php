<?php

/**
 * Provides a test-only class_exists() override for integration auto-detection.
 *
 * Use this file when a test models an optional framework class as installed or missing.
 * It keeps client-factory behavior deterministic without changing application dependencies.
 */

declare(strict_types=1);

namespace StrandsPhpClient;

/**
 * Test-only function override to control class_exists() checks inside the Strands namespace.
 * Use it when an integration test simulates a Laravel or Symfony dependency being available.
 *
 * @internal
 *
 * @param string $class Non-empty class name checked by integration auto-detection.
 * @param bool $autoload Whether PHP should autoload while checking the class.
 * @return bool True when the simulated or real class exists.
 */
function class_exists(string $class, bool $autoload = true): bool
{
    /** @var array<string, bool>|null $overrides validated before app code uses it. */
    $overrides = $GLOBALS['__strands_class_exists_overrides'] ?? null;

    // A test-supplied answer simulates whether an optional framework class is available to the app.
    if (is_array($overrides) && array_key_exists($class, $overrides)) {
        return $overrides[$class];
    }

    return \class_exists($class, $autoload);
}
