# Contributing

Thanks for your interest in contributing to the Strands PHP Client! This guide covers everything you need to get started.

## Getting Started

### Prerequisites

- PHP 8.2+
- Composer 2.x
- Git

### Setup

```bash
git clone https://github.com/blundergoat/strands-php-client.git
cd strands-php-client
composer install
```

### Verify everything works

```bash
composer test          # Run PHPUnit tests
composer analyse       # Run PHPStan (Level 10)
composer cs:check      # Check code style (PSR-12)
```

Or run all quality checks at once:

```bash
composer preflight
```

## Development Workflow

### 1. Create a branch

```bash
git checkout -b feature/your-feature-name
```

### 2. Write code

- Source code goes in `src/`
- Tests go in `tests/Unit/` mirroring the `src/` directory structure
- Test fixtures go in `tests/Fixtures/`

### 3. Run quality checks

Before submitting, make sure everything passes:

```bash
composer preflight
```

This runs:
- **PHPUnit** -All tests must pass
- **PHPStan Level 10** -Strictest static analysis
- **PHP-CS-Fixer** -PSR-12 code style
- **PHPMD** -Mess detector (design, codesize, unusedcode)
- **Cyclomatic complexity** -Max 20 per method
- **Coverage** -Minimum 80% line coverage (when run with `--coverage-min=80`)

### 4. Submit a PR

- Write a clear title and description
- Reference any related issues
- Include test evidence (commands run, output)

## Code Style

This project follows **PSR-12** enforced by PHP-CS-Fixer. Key rules:

- `declare(strict_types=1);` in every PHP file
- 4-space indentation (no tabs)
- Short array syntax (`[]` not `array()`)
- Single quotes for strings (unless interpolation is needed)
- Ordered imports (classes, then functions, then constants)

To auto-fix style issues:

```bash
composer cs:fix
```

## Testing

### Running tests

```bash
# All tests
composer test

# Single test file
vendor/bin/phpunit tests/Unit/StrandsClientTest.php

# With coverage report
composer test:coverage
# Coverage HTML report: coverage-html/index.html
# Coverage XML: coverage.xml
```

### Writing tests

- All tests use mocked HTTP responses -no network, no Docker, no API keys
- Use `$this->createMock()` for `HttpTransport` and `StrandsClient`
- Use fixture files in `tests/Fixtures/` for realistic test data
- Test names should be descriptive: `testInvokeRetriesOnTransientError`

Example test structure:

```php
<?php

declare(strict_types=1);

namespace Strands\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MyNewFeatureTest extends TestCase
{
    public function testFeatureWorksCorrectly(): void
    {
        // Arrange
        $transport = $this->createMock(HttpTransport::class);
        // ...

        // Act
        $result = $client->invoke(message: 'Test');

        // Assert
        $this->assertSame('expected', $result->text);
    }
}
```

## Static Analysis

PHPStan runs at **Level 10** (the strictest level):

```bash
composer analyse
```

If you add new code, make sure PHPStan passes. Common things to watch for:
- Missing type declarations on parameters, return types, and properties
- Unsafe array access (use `is_array()`, `is_string()` checks)
- PHPDoc `@param` and `@return` types matching actual code

## Project Structure

```
src/
├── Auth/                          # Authentication strategies
│   ├── AuthStrategy.php           # Interface
│   ├── NullAuth.php               # No-op (local dev)
│   └── ApiKeyAuth.php             # API key / Bearer token
├── Config/
│   └── StrandsConfig.php          # Client configuration
├── Context/
│   └── AgentContext.php            # Immutable context builder
├── Exceptions/
│   ├── StrandsException.php       # Base exception
│   ├── AgentErrorException.php    # HTTP 4xx/5xx from agent
│   └── StreamInterruptedException.php  # Stream dropped
├── Http/
│   ├── HttpTransport.php          # Transport interface
│   ├── SymfonyHttpTransport.php   # Symfony HTTP client (invoke + stream)
│   └── PsrHttpTransport.php       # PSR-18 client (invoke only)
├── Integration/
│   ├── StrandsClientFactory.php   # Shared factory (Laravel + Symfony)
│   ├── Laravel/                   # Laravel service provider
│   │   ├── StrandsServiceProvider.php
│   │   ├── Facades/
│   │   │   └── Strands.php
│   │   └── config/
│   │       └── strands.php
│   └── Symfony/                   # Symfony bundle
│       ├── StrandsBundle.php
│       └── DependencyInjection/
│           ├── Configuration.php      # YAML schema definition
│           ├── StrandsExtension.php   # Service registration
│           └── StrandsClientFactory.php  # Extends shared factory
├── Response/
│   ├── AgentResponse.php          # invoke() return type
│   └── Usage.php                  # Token usage stats
├── Streaming/
│   ├── StreamEvent.php            # Single SSE event
│   ├── StreamEventType.php        # Event type enum
│   ├── StreamParser.php           # SSE chunk parser
│   └── StreamResult.php           # stream() return type
└── StrandsClient.php              # Main client class

tests/
├── Unit/                          # PHPUnit tests (mirrors src/)
├── Fixtures/                      # JSON/SSE test data
├── Support/                       # Test helpers
└── bootstrap.php
```

## Commit Messages

Use concise imperative subjects:

- `Add ApiKeyAuth strategy for API gateway auth`
- `Fix StreamEvent text property naming`
- `Refactor retry logic into StrandsClient`
- `Update usage guide for StreamResult`

Keep commits scoped to one change. Explain non-obvious decisions in the commit body.

## Questions?

Open an issue on GitHub if you have questions or need help getting started.
