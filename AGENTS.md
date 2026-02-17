# AGENTS.md

This document provides context, patterns, and guidelines for AI coding assistants working in this repository. For human contributors, see [CONTRIBUTING.md](./CONTRIBUTING.md).

## Product Overview

Strands PHP Client is a PHP library for consuming [Strands Agents](https://github.com/strands-agents/strands-agents) AI agents over HTTP. It provides synchronous invocation via `invoke()` and real-time SSE streaming via `stream()`, with pluggable authentication, retry logic, and optional Laravel and Symfony framework integrations.

**Core Features:**
- Synchronous Invocation: Send a message, get a typed `AgentResponse`
- SSE Streaming: Real-time token-by-token streaming with typed `StreamEvent` objects
- Transport Abstraction: Symfony HttpClient (full support) or any PSR-18 client (invoke only)
- Authentication Strategies: Null Object pattern for local dev, API key/Bearer token for production, extensible interface for custom auth
- Immutable Context Builder: System prompts, metadata, permissions, documents, structured data
- Retry with Exponential Backoff: Configurable retries with jitter on transient HTTP errors
- Laravel Service Provider: PHP config, named agent bindings, facade, auto-discovery
- Symfony Bundle: YAML config, named agent services, autowiring, automatic logger injection

## Directory Structure

```
strands-php-client/
│
├── src/                                    # Production code
│   ├── StrandsClient.php                   # Main client (invoke, stream, retry logic)
│   ├── Auth/                               # Authentication strategies
│   │   ├── AuthStrategy.php                # Interface
│   │   ├── NullAuth.php                    # No-op (Null Object pattern)
│   │   └── ApiKeyAuth.php                  # Bearer token / custom header
│   ├── Config/
│   │   └── StrandsConfig.php               # Configuration holder (endpoint, timeouts, retries)
│   ├── Context/
│   │   └── AgentContext.php                # Immutable context builder (clone-and-mutate)
│   ├── Http/                               # Transport abstraction
│   │   ├── HttpTransport.php               # Interface
│   │   ├── SymfonyHttpTransport.php        # Symfony HttpClient (invoke + streaming)
│   │   └── PsrHttpTransport.php            # PSR-18 (invoke only)
│   ├── Response/
│   │   ├── AgentResponse.php               # Invoke response DTO
│   │   └── Usage.php                       # Token usage stats
│   ├── Streaming/                          # SSE support
│   │   ├── StreamEvent.php                 # Single parsed event
│   │   ├── StreamEventType.php             # Backed enum (Text, ToolUse, ToolResult, etc.)
│   │   ├── StreamParser.php                # Incremental SSE chunk parser
│   │   └── StreamResult.php                # Accumulated stream result
│   ├── Exceptions/
│   │   ├── StrandsException.php            # Base exception
│   │   ├── AgentErrorException.php         # HTTP error from agent
│   │   └── StreamInterruptedException.php  # Stream ended without terminal event
│   └── Integration/                        # Framework integrations
│       ├── StrandsClientFactory.php        # Shared factory (used by both Laravel and Symfony)
│       ├── Laravel/                        # Laravel service provider
│       │   ├── StrandsServiceProvider.php  # Service provider (register + boot)
│       │   ├── Facades/
│       │   │   └── Strands.php            # Facade for default StrandsClient
│       │   └── config/
│       │       └── strands.php            # Publishable config file
│       └── Symfony/                        # Symfony bundle
│           ├── StrandsBundle.php           # Bundle registration
│           └── DependencyInjection/
│               ├── Configuration.php       # YAML config schema
│               ├── StrandsExtension.php    # DI container extension
│               └── StrandsClientFactory.php # Subclass of shared factory
│
├── tests/                                  # Unit tests
│   ├── Unit/                               # Test classes (mirrors src/)
│   │   ├── StrandsClientTest.php
│   │   ├── StrandsClientStreamTest.php
│   │   ├── SymfonyHttpTransportTest.php
│   │   ├── PsrHttpTransportTest.php
│   │   ├── StreamParserTest.php
│   │   ├── AgentContextTest.php
│   │   ├── AgentResponseTest.php
│   │   ├── NullAuthTest.php
│   │   ├── ApiKeyAuthTest.php
│   │   └── Integration/                    # Framework integration tests
│   │       ├── StrandsClientFactoryTest.php # Shared factory tests
│   │       ├── Laravel/                    # Laravel tests
│   │       └── Symfony/                    # Symfony bundle DI tests
│   ├── Fixtures/                           # Test data (JSON, SSE text)
│   ├── Support/                            # Test helpers
│   └── bootstrap.php                       # Test setup
│
├── docs/                                   # Documentation
│   ├── usage-guide.md                      # Real-world patterns and examples
│   ├── auth.md                             # Authentication strategies
│   ├── laravel-config.md                   # Full PHP config reference (Laravel)
│   └── symfony-config.md                   # Full YAML config reference (Symfony)
│
├── scripts/
│   ├── preflight-checks.sh                 # Pre-commit quality gates
│   └── check-cyclomatic-complexity.php     # CC checker (max 20)
│
├── composer.json                           # Dependencies and scripts
├── phpunit.xml                             # Test runner config
├── phpstan.neon                            # Static analysis (Level 10)
├── phpmd.xml                               # Mess detector rules
├── infection.json5                         # Mutation testing (80% MSI)
├── .php-cs-fixer.php                       # Code formatting (PSR-12)
├── .github/workflows/ci.yml               # CI/CD pipeline
├── AGENTS.md                               # This file
├── CONTRIBUTING.md                         # Human contributor guidelines
├── CHANGELOG.md                            # Version history
└── README.md                               # Project documentation
```

**IMPORTANT**: After making changes that affect the directory structure (adding new directories, moving files, or adding significant new files), you MUST update this directory structure section to reflect the current state of the repository.

## Development Workflow

### 1. Environment Setup

```bash
composer install
```

### 2. Making Changes

1. Create feature branch
2. Implement changes following the patterns below
3. Run quality checks: `composer preflight`
4. Commit with concise imperative subjects ("Add", "Fix", "Refactor", "Update")
5. Push and open PR

### 3. Quality Gates

All checks must pass before merge:

```bash
composer test                    # PHPUnit - all tests pass
composer cs:check                # PHP-CS-Fixer - PSR-12 compliance
composer analyse                 # PHPStan Level 10
composer analyse:messdetector    # PHPMD
composer analyse:complexity      # Cyclomatic complexity max 20
composer mutate                  # Infection - 80% MSI minimum
```

Run everything at once:

```bash
composer preflight               # All quality checks
```

## Coding Patterns and Best Practices

### Strict Types

Every PHP file must declare strict types:

```php
<?php

declare(strict_types=1);
```

### PHPDoc Style

Class-level docblocks are a single concise sentence. Method docblocks follow this format:

```php
/**
 * Send a message to the agent and return the full response.
 *
 * @param string            $message   The user's message.
 * @param AgentContext|null  $context   Optional application context.
 * @param string|null        $sessionId Optional session ID for multi-turn.
 *
 * @return AgentResponse
 *
 * @throws AgentErrorException If the agent returns an HTTP error.
 */
```

Use fully qualified array types: `array<string, string>`, `list<array{name: string, duration_ms?: int}>`. No bare `array` or `mixed` without good reason.

### Readonly DTOs

Use `readonly` promoted constructor properties. Hydrate from API responses via static `fromArray()` factory methods with defensive type checking:

```php
public function __construct(
    public readonly string $text,
    public readonly ?string $sessionId = null,
    public readonly Usage $usage = new Usage(),
    public readonly array $toolsUsed = [],
) {
}

public static function fromArray(array $data): self
{
    $inputTokens = $data['usage']['input_tokens'] ?? 0;
    $inputTokens = is_int($inputTokens) ? $inputTokens : 0;
    // ...
}
```

### Immutable Builders

`AgentContext` uses clone-and-mutate. Every `with*()` returns a new instance:

```php
public function withSystemPrompt(string $prompt): self
{
    $clone = clone $this;
    $clone->systemPrompt = $prompt;
    return $clone;
}
```

### Logging Style

Use PSR-3 with context arrays. Keys are snake_case:

```php
$this->logger->debug('Strands invoke request', [
    'url' => $url,
    'session_id' => $sessionId,
]);

$this->logger->warning('Strands request failed, retrying', [
    'attempt' => $attempt,
    'max_retries' => $maxRetries,
    'delay_ms' => $delayMs,
    'error' => $e->getMessage(),
]);
```

- `debug` level for request/response details, token counts
- `warning` level for retry attempts

### Import Organization

Imports are ordered alphabetically. Group by:
1. PHP built-in classes
2. Third-party (PSR, Symfony)
3. Internal (`Blundergoat\StrandsPhpClient\...`)

### Code Style (PHP-CS-Fixer)

- PSR-12 base standard
- Short array syntax: `[]`
- Single quotes for strings
- Trailing commas in multiline arrays/arguments/parameters
- Blank lines before `return` statements
- No unused imports

Run `composer cs:fix` to auto-format before committing.

### Error Handling

- Use the custom exception hierarchy: `StrandsException` (base), `AgentErrorException` (HTTP errors), `StreamInterruptedException` (incomplete streams)
- Document exceptions in PHPDoc `@throws` annotations
- Defensive parsing of API responses with type checks and safe defaults

## Testing Patterns

### Unit Tests (`tests/Unit/`)

- Mirror the `src/` structure
- All tests use mocked HTTP responses - no network, no Docker, no API keys
- Test fixtures in `tests/Fixtures/` (JSON responses, SSE text files)

### Test Naming

Method names describe the feature and scenario:

```php
public function testInvokeReturnsHydratedResponse(): void
public function testInvokeWithoutSessionId(): void
public function testInvokeRetriesOnRetryableStatusCode(): void
public function testConfigRejectsZeroTimeout(): void
```

### Test Structure

Arrange-Act-Assert with private helpers for setup:

```php
public function testInvokeReturnsHydratedResponse(): void
{
    // Arrange
    $fixture = $this->loadFixture('invoke-analyst-response.json');
    $transport = $this->createMockTransport($fixture);
    $client = new StrandsClient($this->config, $transport);

    // Act
    $response = $client->invoke('test message');

    // Assert
    $this->assertSame('expected text', $response->text);
}
```

### Mocking

Use PHPUnit's `createMock()` with method expectations and callbacks:

```php
$transport = $this->createMock(HttpTransport::class);
$transport->expects($this->once())
    ->method('post')
    ->with(
        $this->anything(),
        $this->callback(fn (array $h) => $h['Content-Type'] === 'application/json'),
        $this->callback(function (string $body) {
            $decoded = json_decode($body, true);
            $this->assertSame('test', $decoded['message']);
            return true;
        }),
    )
    ->willReturn($fixture);
```

### Running Tests

```bash
composer test                                           # All tests
vendor/bin/phpunit tests/Unit/StrandsClientTest.php     # Single file
vendor/bin/phpunit --filter testInvokeReturnsResponse   # Single method
composer test:coverage                                  # With coverage report
```

## Things to Do

- Use `declare(strict_types=1);` in every PHP file
- Use `readonly` on DTO properties
- Write fully qualified PHPDoc types (`array<string, string>`, not `array`)
- Use defensive type checks when parsing API responses
- Add `@throws` annotations for exceptions
- Use PSR-3 logging with context arrays
- Write tests for all new functionality
- Run `composer preflight` before committing
- Keep cyclomatic complexity under 20 per method

## Things NOT to Do

- Don't use bare `array` or `mixed` types without good reason
- Don't skip `declare(strict_types=1);`
- Don't make network calls in tests - mock everything
- Don't mutate objects that should be immutable (use clone-and-mutate)
- Don't use f-string style concatenation in log messages - use context arrays
- Don't commit without running `composer cs:fix`
- Don't add dependencies without updating `composer.json` suggest/require appropriately

## Agent-Specific Notes

### Writing Code

- Make the smallest reasonable changes to achieve the desired outcome
- Prefer simple, clean, maintainable solutions over clever ones
- Match the style and formatting of surrounding code
- Fix broken things immediately when you find them

### Code Comments

- Comments should explain WHAT the code does or WHY it exists
- Never add comments about what used to be there or how something changed
- Never refer to temporal context ("recently refactored", "moved")
- Keep comments concise and evergreen

## Additional Resources

- [README.md](./README.md) - Project overview, quick start, architecture
- [CONTRIBUTING.md](./CONTRIBUTING.md) - Human contributor guidelines
- [docs/usage-guide.md](./docs/usage-guide.md) - Real-world usage patterns
- [docs/auth.md](./docs/auth.md) - Authentication strategies
- [docs/laravel-config.md](./docs/laravel-config.md) - Laravel PHP config reference
- [docs/symfony-config.md](./docs/symfony-config.md) - Symfony YAML config reference
