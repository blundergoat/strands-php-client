<?php

declare(strict_types=1);

namespace Strands;

/**
 * Test-only function override to control class_exists() checks inside the Strands namespace.
 *
 * @internal
 */
function class_exists(string $class, bool $autoload = true): bool
{
    /** @var array<string, bool>|null $overrides */
    $overrides = $GLOBALS['__strands_class_exists_overrides'] ?? null;

    if (is_array($overrides) && array_key_exists($class, $overrides)) {
        return $overrides[$class];
    }

    return \class_exists($class, $autoload);
}
