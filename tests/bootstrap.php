<?php

/**
 * Boots PHPUnit so tests exercise the client like an app would.
 *
 * Use this file when test startup or Composer loading changes.
 * It gives every test the same client classes an application receives.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Support/StrandsFunctionOverrides.php';
