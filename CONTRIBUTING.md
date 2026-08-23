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

This runs, in order:
- **Composer validate** - `composer.json` is well-formed and strict-valid
- **Security audit** - no known advisories against the locked dependencies
- **Branch alias** - the `dev-main` alias in `composer.json` matches the newest `CHANGELOG.md` version
- **Shellcheck** - every `.sh` file under `scripts/` and `.goat-flow/hooks/`
- **PHP-CS-Fixer** - PSR-12 code style, dry-run only
- **Cyclomatic complexity** - max 20 per method
- **PHPMD** - mess detector (codesize, design, unusedcode)
- **PHPStan Level 10** - strictest static analysis
- **PHPUnit** - all tests must pass
- **Coverage** - minimum 80% line coverage; pass `--coverage-min=N` to change the threshold

Mutation testing is skipped by default. Run it with `./scripts/preflight-checks.sh --mutate` or `composer preflight:mutate`; `infection.json5` sets both `minMsi` and `minCoveredMsi` to 90. It needs a coverage driver (Xdebug or PCOV) and is slow.

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

### What CI covers

CI runs the suite against PHP 8.2, 8.3, and 8.4, crossed with Symfony `^6.4` and `^7.0`, plus a separate Laravel job across `^10.0`, `^11.0`, and `^12.0`.

`composer.json` also allows Symfony `^8.0`, and that allowance is correct for consumers — nothing in the library blocks it. CI cannot cover it yet: `phpmd/phpmd` depends on `pdepend/pdepend`, whose newest release still constrains `symfony/config` to `^7.0`, so Symfony 8 will not resolve alongside this project's dev tooling. Adding an `^8.0` matrix leg fails during dependency installation with a PDepend conflict, not a real incompatibility. Leave the matrix alone until PDepend supports Symfony 8.

### Writing tests

- All tests use mocked HTTP responses - no network, no Docker, no API keys
- Use `$this->createMock()` for `HttpTransport` and `StrandsClient`
- Use fixture files in `tests/Fixtures/` for realistic test data
- Test names should be descriptive: `testInvokeRetriesOnTransientError`

Example test structure:

```php
<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

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
│   ├── ApiKeyAuth.php             # API key / Bearer token
│   └── SigV4Auth.php              # AWS Signature V4 (IAM auth)
├── Config/
│   └── StrandsConfig.php          # Client configuration
├── Context/
│   ├── AgentContext.php            # Immutable context builder
│   └── AgentInput.php             # Rich input builder (text, images, docs)
├── Exceptions/
│   ├── StrandsException.php       # Base exception
│   ├── AgentErrorException.php    # HTTP 4xx/5xx from agent
│   └── StreamInterruptedException.php  # Stream dropped
├── Http/
│   ├── HttpTransport.php          # Transport interface
│   ├── RequestMiddleware.php      # Middleware interface
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
│   ├── GuardrailTrace.php         # Guardrail intervention data
│   ├── InterruptDetail.php        # Human-in-the-loop interrupt
│   ├── StopReason.php             # Why the agent stopped (enum)
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
