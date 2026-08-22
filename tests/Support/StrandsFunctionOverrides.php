<?php

/**
 * Exercises caller-visible Strands Function Overrides behavior for app integrations.
 *
 * Use this file when changing Strands Function Overrides or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

declare(strict_types=1);

namespace StrandsPhpClient;

/**
 * Test-only function override to control class_exists() checks inside the Strands namespace.
 *
 * @internal
 *
 * @param string $class Class name being checked by integration auto-detection.
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
