# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-02-19

### Added

- `StrandsClient::postJson(string $path, array $payload): array` — send JSON to custom agent endpoints with arbitrary payloads while reusing auth, retry, timeout, and config infrastructure.
- `StrandsClient::streamSse(string $path, array $payload, callable $onEvent): void` — stream SSE events from custom agent endpoints, delivering raw decoded arrays that preserve all domain-specific fields.
- `StopReason` enum with 7 values matching the Python SDK: `EndTurn`, `ToolUse`, `MaxTokens`, `StopSequence`, `ContentFiltered`, `GuardrailIntervened`, `Interrupt`.
- `AgentResponse::$stopReason` and `StreamResult::$stopReason` — why the agent stopped generating output, hydrated from the `stop_reason` field in API responses and complete stream events.
- `AgentResponse::$structuredOutput` — schema-validated structured output from agents that return JSON conforming to a schema.
- `Usage::$cacheReadInputTokens`, `Usage::$cacheWriteInputTokens`, `Usage::$latencyMs`, `Usage::$timeToFirstByteMs` — enriched token and performance metrics matching the Python SDK's `EventLoopMetrics`.
- `StreamEventType::Citation`, `StreamEventType::ReasoningSignature`, `StreamEventType::ReasoningRedacted` — new stream event types matching the Python SDK.
- `StreamEvent::$citation`, `StreamEvent::$reasoningSignature`, `StreamEvent::$stopReason` — new fields for the corresponding event types.
- `AgentResponse::$hasObjective` and `StreamEvent::$hasObjective`, hydrated from API `has_objective` when strictly `true`.
- 33 new unit tests (214 total, 578 assertions).

### Changed

- Expanded `composer.json` keywords for discoverability (`php`, `sdk`, `psr-18`, `laravel`, `symfony`).
- `scripts/preflight-checks.sh` now runs PHP-CS-Fixer in sequential mode and falls back to single-process PHPStan analysis when worker socket binding fails in constrained environments.
- PHPMD `ExcessiveParameterList` threshold raised from 13 to 16 to accommodate `StreamEvent` DTO constructor.
- Documentation/comment formatting cleanup for hyphen-as-dash spacing consistency.

## [1.1.0] - 2026-02-17

### Added

- Laravel service provider integration -config-driven agent registration, DI container bindings, and `Strands` facade.
- `StrandsServiceProvider` -Registers `StrandsClientFactory`, default `StrandsClient` binding, and named `strands.client.<name>` bindings.
- `Strands` facade -Proxies to the default `StrandsClient` with `@method` PHPDoc for IDE completion.
- Publishable `config/strands.php` with `default` agent key, `agents` array, and `env()` helpers.
- Auto-discovery via `extra.laravel` in `composer.json` - no manual provider registration needed.
- `docs/laravel-config.md` - Full configuration reference for Laravel.

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

[Unreleased]: https://github.com/blundergoat/strands-php-client/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/blundergoat/strands-php-client/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/blundergoat/strands-php-client/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/blundergoat/strands-php-client/releases/tag/v1.0.0
