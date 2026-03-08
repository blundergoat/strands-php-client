# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-03-08

### Added

- `StrandsClient::stream()` now logs an info-level message when the stream parser encounters unknown event types (skipped events), with a hint that the PHP client may need updating. Aids debugging when the upstream Python agent ships new event types.
- `StreamEvent::tryFromArray(array $data): ?self` — forward-compatible alternative to `fromArray()` that returns `null` on unknown event types instead of throwing. `fromArray()` is unchanged (still throws). Use `tryFromArray()` when you want to silently skip unrecognised events.
- `Usage::totalTokens(): int` — convenience method returning `inputTokens + outputTokens`.
- `AgentResponse::$metadata` — new `array` property (default `[]`) capturing unrecognised top-level response fields. Future server-side additions (e.g. `trace_id`, `model_id`) are now preserved instead of silently dropped.
- `StreamResult::$timeToFirstTextTokenMs` — client-side measurement of how long until the first `Text` event arrives, in milliseconds. `null` when no text events were received (e.g. tool-only responses). Distinct from the server-provided `Usage::$timeToFirstByteMs`.
- Interrupt awareness — `InterruptDetail` value object (`toolName`, `toolInput`, `toolUseId`, `interruptId`, `reason`). `AgentResponse::$interrupts` and `StreamResult::$interrupts` (both `list<InterruptDetail>`, default `[]`). `AgentResponse::isInterrupted()` and `StreamResult::isInterrupted()` convenience methods.
- `InterruptDetail::toResumeInput(mixed $response): AgentInput` — convenience method to build an `AgentInput` for resuming after an interrupt. Uses `interruptId`, falls back to `toolUseId`.
- `AgentInput::interruptResponse(string $interruptId, mixed $response): self` — static factory for creating interrupt response inputs.
- Guardrail trace — `GuardrailTrace` value object (`action`, `assessments`, `modelOutput`). `AgentResponse::$guardrailTrace` and `StreamResult::$guardrailTrace` (nullable, default `null`). Parsed from `guardrail_trace` (top-level) or `trace.guardrail` (nested) in responses and Complete stream events.
- Citation content block extraction — `AgentResponse::$citations` extracted from `citationsContent`/`citation` blocks in `message.content[]` (default `[]`). `StreamResult::$citations` accumulated from Citation stream events during streaming.
- Rich input support — `AgentInput` builder with clone-and-mutate pattern: `AgentInput::text()`, `->withImage()`, `->withDocument()`, `->withDocumentFromS3()`, `->withVideoFromS3()`, `->withStructuredOutputPrompt()`. `invoke()` and `stream()` now accept `string|AgentInput $message`.
- AWS SigV4 auth strategy — `SigV4Auth` implementing `AuthStrategy` for agents behind API Gateway with IAM auth. Standalone implementation (~260 lines, no `aws/aws-sdk-php` dependency). `SigV4Auth::fromEnvironment()` for credential resolution from `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`. `SigV4Auth::__debugInfo()` masks credentials in `var_dump`/`print_r` output. Registered in both Symfony (`driver: sigv4`) and Laravel DI config.
- `AgentErrorException::$errorCode` — machine-readable error code populated from `code` or `error_code` in the response body, enabling programmatic error handling (e.g. rate-limit backoff).
- Empty message validation — `invoke()` and `stream()` reject empty strings with a clear `InvalidArgumentException`. `AgentInput` with content blocks (e.g. interrupt responses) is allowed even when text is empty.
- New test fixtures: `invoke-interrupt-response.json`, `invoke-guardrail-response.json`, `invoke-response-with-citations.json`, `invoke-response-with-metadata.json`, `sse-interrupt-complete.txt`, `sse-guardrail-complete.txt`, `sse-with-unknown-event.txt`.
- `setup-initial.sh` script for one-command dev environment setup (detects OS, installs pcov for coverage).
- 453 tests, 1327 assertions.

### Changed

- `StrandsClient::stream()` refactored — extracted `buildStreamResult()` and `logSkippedEvents()` private methods to reduce cyclomatic complexity (24 to 17) and method length (166 to 114 lines).
- Middleware now runs before auth in `buildRequest()` and `buildJsonRequest()` — body-modifying middleware (e.g. payload enrichment) no longer invalidates SigV4 signatures.
- `StreamParser::feed()` CRLF normalization optimized — only the new chunk is processed instead of re-normalizing the entire buffer on every call (O(chunk) vs O(buffer)). Handles `\r\n` split across chunk boundaries.
- `StrandsClient::streamSse()` receives the same CRLF normalization optimization.
- Error messages improved — `AgentErrorException` now formats as `"Agent returned HTTP {code}: {detail}"`. Transport errors include the URL: `"Expected JSON object from {url}, got {type}"`. Stream interruptions include the URL.
- `StrandsClientFactory::createSigV4Auth()` rejects partial credentials — providing only one of `access_key_id`/`secret_access_key` throws `InvalidArgumentException` instead of silently falling through to environment variables.
- `AgentInput::formatToMimeType()` handles common document formats: `txt`→`text/plain`, `csv`→`text/csv`, `html`→`text/html`, `md`→`text/markdown`, `xml`→`application/xml`.
- Minimum mutation testing MSI raised from 80% to 90%.
- PHPMD `ExcessiveParameterList` threshold raised from 16 to 18 to accommodate `StreamEvent` DTO constructor with interrupt and guardrail fields.
- `StreamEvent::fromArray()` refactored — internal logic extracted to `buildFromArray()` shared by both `fromArray()` and `tryFromArray()`.
- Symfony `Configuration` auth driver enum now includes `'sigv4'` alongside `'null'` and `'api_key'`.
- `StrandsClientFactory::resolveAuth()` now supports `'sigv4'` driver with region, service, access_key_id, secret_access_key, and session_token options.
- Laravel `config/strands.php` now includes SigV4 auth options.
- Improved class-level docstrings across `StrandsClient`, `AgentResponse`, `StreamResult`, `StreamEvent`, `AgentErrorException`, `Usage`, and `StrandsClientFactory`.

### Fixed

- SigV4Auth port handling — non-standard ports (e.g. 8443) are now correctly included in the canonical `Host` header as `hostname:port`. Default ports (443 for HTTPS, 80 for HTTP) are correctly omitted.

## [1.3.0] - 2026-02-21

### Added

- `RequestMiddleware` interface — operation-scoped middleware for observing and modifying HTTP requests to Strands agents. `beforeRequest()` runs once before the first attempt (including retries), `afterResponse()` runs once after the final outcome. Use cases: OpenTelemetry/Datadog tracing, custom header injection, request logging, metrics collection.
- Middleware pipeline in `StrandsClient` — accepts `list<RequestMiddleware>` via constructor. Middleware is applied to all four public methods: `invoke()`, `stream()`, `postJson()`, and `streamSse()`. Middleware exceptions in `afterResponse()` are caught and logged, never propagated.
- Middleware support in Symfony bundle — `RequestMiddleware` implementations are autoconfigured via the `strands.middleware` tag and injected into the factory as a `TaggedIteratorArgument`.
- Middleware support in Laravel service provider — resolved via `$app->tagged('strands.middleware')` and passed to `StrandsClientFactory`.
- Per-request `?int $timeoutSeconds` on `invoke()` and `stream()` — overrides the global config timeout for individual calls. Values < 1 throw `InvalidArgumentException`.
- Per-request timeout override on `postJson()` and `streamSse()` — optional `?int $timeout` parameter (in seconds) with the same semantics.
- Buffer overflow protection in `StreamParser` — throws `StreamInterruptedException` if the internal buffer exceeds 10 MB without a complete event delimiter, guarding against unbounded memory growth from broken proxies or non-SSE responses.
- `retryableStatusCodes` validation in `StrandsConfig` — codes must be in the 400-599 range; out-of-range values throw `InvalidArgumentException`.
- `retryable_status_codes` option in Symfony bundle `Configuration` — array of integers, defaults to `[429, 502, 503, 504]`.
- `AgentErrorException::$responseBody` — the full decoded JSON response body from error responses (4xx/5xx), enabling structured error inspection for debugging. `null` when the response wasn't valid JSON.
- `AgentErrorException::fromHttpResponse()` — static factory that builds an `AgentErrorException` from a raw HTTP status code, body string, and decoded JSON. Replaces the duplicated error-extraction logic that was in both `PsrHttpTransport::post()` and `SymfonyHttpTransport::post()`/`stream()`.
- Stream cancellation support — `stream()` and `streamSse()` callbacks can return `false` to cancel the stream. This is a true transport-level abort: `SymfonyHttpTransport` calls `$response->cancel()` and breaks out of the chunk loop, closing the HTTP connection immediately.
- `StreamResult::$cancelled` — boolean flag indicating whether the stream was stopped by the `onEvent` callback returning `false`. Allows consumers to distinguish user-initiated cancellation from an interrupted/completed stream.
- `PsrHttpTransport` now accepts an optional `LoggerInterface` and logs a `notice` on first use when timeout parameters cannot be honoured — prevents silent debugging headaches for users who set `timeout` in config but use a PSR-18 client.
- `Usage::fromArray(array $data)` static factory method — canonical way to create `Usage` from raw API arrays, replacing duplicated helpers in `AgentResponse` and `StrandsClient`.
- `HttpTransport::stream()` callback now supports returning `false` to signal cancellation to the transport layer.
- Mutation testing CI job — runs Infection on pull requests to `main` with `--min-msi=80 --min-covered-msi=80` (PHP 8.3, `continue-on-error: true`).
- 68 new unit tests (282 total, 843 assertions).

### Changed

- Retry delay now capped at 30 seconds — prevents absurd sleep times at high retry counts. Formula: `min(retryDelayMs * 2^attempt, 30_000) * random(0.5, 1.0)`.
- `StreamEvent::fromArray()` now uses `StreamEventType::tryFrom()` with a descriptive `InvalidArgumentException` for unknown types — previously used `from()` which threw an opaque `ValueError`.
- Cancelled streams report status 0 to middleware `afterResponse()` — previously reported 200, which misrepresented incomplete HTTP responses as successful.
- `StrandsConfig` endpoint validation now uses `parse_url()` with scheme+host check instead of `FILTER_VALIDATE_URL`. This accepts Docker/Kubernetes hostnames without a TLD (e.g. `http://agent:8080`) that were previously rejected.
- `PsrHttpTransport`, `SymfonyHttpTransport` error-to-exception mapping now delegates to `AgentErrorException::fromHttpResponse()`, eliminating three near-identical code blocks.
- Removed `StrandsClient::usageFromArray()` — one-line passthrough replaced by direct `Usage::fromArray()` call.
- `AgentResponse::parseUsage()` now delegates to `Usage::fromArray()`, eliminating code duplication.
- `StrandsClient::stream()` and `streamSse()` internal closures now return `bool` to propagate cancellation to the transport.

### Fixed

- `postJson()` and `streamSse()` now call `afterResponse()` on middleware — previously only `beforeRequest()` was applied, leaving observability middleware blind to these methods' outcomes.
- Laravel `StrandsServiceProvider` now wires `RequestMiddleware` implementations via `strands.middleware` tagged services — previously the factory was constructed without middleware, making it impossible for Laravel users to use the middleware pipeline.
- `StreamParser` uses `use` import for `StreamInterruptedException` instead of inline FQCN — consistent with project style.
- `Strands` facade missing `@method` annotations for `postJson()` and `streamSse()` — IDE autocompletion and static analysis now see all public methods.
- Laravel `StrandsServiceProvider` now injects the application's PSR-3 logger into `StrandsClientFactory` — debug/warning logging was silently discarded through the Laravel integration.
- Branch alias in `composer.json` updated from `1.1.x-dev` to `1.3.x-dev`.

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

[Unreleased]: https://github.com/blundergoat/strands-php-client/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/blundergoat/strands-php-client/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/blundergoat/strands-php-client/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/blundergoat/strands-php-client/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/blundergoat/strands-php-client/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/blundergoat/strands-php-client/releases/tag/v1.0.0
