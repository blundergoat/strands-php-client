<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Fixtures\Compatibility;

use StrandsPhpClient\Streaming\StreamParser;

/**
 * Mimics a consumer StreamParser subclass compiled against the constructor behavior published in 1.4.
 *
 * In 1.4 the parent had no constructor, so an application constructor had no parent initialization to call.
 * Use this fixture to ensure the first streamed frame still works after upgrading the client.
 */
final class V1StreamParserExtension extends StreamParser
{
    /**
     * Represents an application-owned constructor that intentionally does not call parent::__construct().
     * Use it to reproduce the construction pattern available to StreamParser subclasses in 1.4.
     */
    public function __construct()
    {
    }
}
