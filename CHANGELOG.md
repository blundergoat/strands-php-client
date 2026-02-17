# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-02-17

### Added

- Laravel service provider integration -config-driven agent registration, DI container bindings, and `Strands` facade.
- `StrandsServiceProvider` -Registers `StrandsClientFactory`, default `StrandsClient` binding, and named `strands.client.<name>` bindings.
- `Strands` facade -Proxies to the default `StrandsClient` with `@method` PHPDoc for IDE completion.
- Publishable `config/strands.php` with `default` agent key, `agents` array, and `env()` helpers.
- Auto-discovery via `extra.laravel` in `composer.json` -no manual provider registration needed.
- `docs/laravel-config.md` -Full configuration reference for Laravel.

### Changed

- Extracted `StrandsClientFactory` to `StrandsPhpClient\Integration\StrandsClientFactory` as a shared base class used by both Laravel and Symfony integrations.
- `StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsClientFactory` now extends the shared base class.

## [1.0.0] - 2026-02-16

### Added

- `StrandsClient` with `invoke()` (blocking) and `stream()` (SSE) methods.
- `SymfonyHttpTransport` -Full support for invoke + SSE streaming via `symfony/http-client`.
- `PsrHttpTransport` -Invoke-only support via any PSR-18 HTTP client.
- `AgentResponse` -Typed response object with text, agent name, session ID, usage stats, tools used.
- `StreamResult` -`stream()` returns accumulated text, session ID, usage stats, and event counts.
- `StreamEvent` -Typed event object for SSE streaming (Text, ToolUse, ToolResult, Thinking, Complete, Error).
- `StreamParser` -Incremental SSE parser that handles chunked delivery, CRLF/LF line endings, and malformed JSON recovery. Includes `getSkippedEvents()` for post-stream diagnostics.
- `AgentContext` -Immutable builder for system prompts, metadata, permissions, documents, structured data.
- `NullAuth` -No-op auth strategy for local development.
- `ApiKeyAuth` -Authentication strategy for API key / Bearer token auth. Configurable header name and value prefix.
- `AuthStrategy` interface -Strategy pattern for pluggable authentication.
- Retry with exponential backoff and jitter -`maxRetries` and `retryDelayMs` on `StrandsConfig`. Retries on transient HTTP errors (429, 502, 503, 504). Permanent errors (400, 401, 403) fail immediately.
- Connect timeout -`connectTimeout` option (default 10s) separate from read `timeout` (default 120s).
- PSR-3 logging -Optional `LoggerInterface` on `StrandsClient`. Logs requests at `debug`, retries at `warning`.
- Symfony bundle integration -YAML config, named agent services, autowiring, auto-detected transport, automatic logger injection, `api_key` auth driver.
- `StrandsException`, `AgentErrorException`, `StreamInterruptedException` -Exception hierarchy.
- PHPStan Level 10, PHP-CS-Fixer (PSR-12), PHPMD, cyclomatic complexity checks.
- CI matrix: PHP 8.2/8.3/8.4, Symfony 6.4/7.0.
- 100+ unit tests with fixture-based mocks (no network calls).

[Unreleased]: https://github.com/blundergoat/strands-php-client/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/blundergoat/strands-php-client/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/blundergoat/strands-php-client/releases/tag/v1.0.0
