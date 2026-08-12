# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

### Changed

### Fixed

## [1.5.0] - 2026-08-12

### Compatibility

- **BREAKING:** top-level `metadata` moved from `AgentResponse::$metadata` to `$wrapperMetadata` - migrate `$response->metadata['metadata']` reads.
- **BREAKING:** `Usage::$latencyMs`/`$timeToFirstByteMs` widened from `int` to `int|float`; strict `int` consumers must round or accept floats.
- PHP requirement narrowed from `>=8.2` to `>=8.2 <9.0`, capping installs at the majors this release is tested on.

### Added

- **OpenTelemetry tracing middleware** - `OtelTracingMiddleware` emits a `KIND_CLIENT` span per `invoke()`, `stream()`, `postJson()`, `streamSse()`.
- `OtelTracingMiddleware` injects W3C `traceparent`/`tracestate` headers and records safe `strands-otel-v1` span attributes.
- OTEL packages stay in `require-dev`/`suggest`, so an app that never configures the middleware pays no runtime cost.
- **Response-aware observability** - `ResponseObserver` and `StreamSseSummary` add terminal hooks without changing `RequestMiddleware`.
- Observers receive parsed `AgentResponse`, accumulated `StreamResult`, raw `postJson()` responses, and sanitized `streamSse()` summaries.
- **Symfony and Laravel observer wiring** - both frameworks resolve the `strands.response_observer` tag; Symfony autoconfigures it.
- Middleware that also implements `ResponseObserver` keeps working from the existing `strands.middleware` stack.
- **Strands HTTP Wire Contract v1** - contract docs, ADR, audit notes, compatibility matrix, and canonical fixtures.
- The wire contract defines the wrapper-owned JSON/SSE shapes exchanged between PHP apps and Python sdk-python gateways.
- **Reference Python gateway template** - `examples/python-gateway/` ships a FastAPI blueprint for `/invoke`, `/stream`, `/health`, custom endpoints.
- The gateway template includes usage/event normalization, trace-context continuation, URL-media guards, and dependency-free smoke checks.
- **Wire-contract and consumer compatibility tests** - fixture smoke tests parse the canonical JSON/SSE fixtures.
- Consumer-shaped custom endpoint fixtures cover `ambient-scribe`, `the-summit-chatroom`, `halaxy-agents-lab`, and `healthkit`.
- **Typed citation DTOs** - `Citation`, `CitationLocation`, `CitationSourceContent`, and `CitationGeneratedContent` under `Response\Citation\`.
- Read citations via `AgentResponse::getCitationObjects()` and `StreamEvent::getCitationObject()`; raw citation arrays remain available.
- Flat citation `source`/`title`/`text` stay first-class and rebuild into location/source-content data when a wrapper sends only the flat shape.
- Citation location indices accept int, float, and numeric-string values.
- **Typed guardrail assessment DTO** - `GuardrailAssessment` plus `GuardrailTrace::getAssessmentObjects()`.
- Assessments expose normalized `name`/`result`/`confidence` alongside the Bedrock policy blocks; raw assessment arrays remain available.
- **Richer agent exceptions** - `ThrottledException`, `ContextOverflowException`, and `MaxTokensException` extend `AgentErrorException`.
- `AgentErrorException::fromHttpResponse()` returns the specific exception subtype where the response identifies one.
- **StopReason additions** - `Error`, `Cancelled`, and `Checkpoint` cover the contract's terminal states and future Python SDK stop reasons.
- **AgentInput media and cache coverage** - `withImageFromS3()`, `withVideo()`, and `withCachePoint()`.
- URL media sources: `withImageFromUrl()`, `withDocumentFromUrl()`, `withVideoFromUrl()`.
- Document blocks also accept `context` and `citations` options.
- **Structured output hydration** - `AgentResponse::structuredOutputAs(string $class)` hydrates a DTO via `fromArray()` or named arguments.
- **Nested message metadata support** - typed `Message` and `MessageMetadata` DTOs preserve `message.role` and `message.content`.
- `MessageMetadata` preserves `message.metadata.usage`, `message.metadata.metrics`, and `message.metadata.custom`.
- **Wrapper metadata and context visibility** - `AgentResponse::$wrapperMetadata`, `::$contextSize`, and `::$projectedContextSize`.
- Stream complete events and stream results carry matching context-size fields.
- **Raw stop-reason preservation** - `AgentResponse::$rawStopReason` keeps unknown future `stop_reason` strings the enum parser cannot hydrate.
- **Stream terminal metadata** - `StreamResult::$terminalType`, `::$errorCode`, and `::$errorMessage`.
- Terminal metadata records whether a stream ended on `complete` or `error` and why; `StreamSseSummary` carries the same for raw SSE streams.
- **Stream callback handler** - `StreamCallbackHandler` dispatches typed events to `on*()` methods; a hook returning `false` cancels the stream.
- `PrintingCallbackHandler` is a stdout/stderr reference implementation with injectable output and error writers.
- **Project workflow scaffolding** - GOAT Flow workspace: architecture and code-map docs, learning-loop directories, agent skill bundles, and hooks.
- The workspace supports contributors; `.gitattributes` `export-ignore`s it, so Composer distribution archives ship without it.
- **Dependency and version scripts** - `scripts/dependencies-install.sh`, `scripts/dependencies-update.sh`, and `scripts/bump-version.sh`.
- The scripts cover Composer and npm install/update, plus changelog-driven version bumping.
- **npm-based goat-flow tooling** - `package.json` and `package-lock.json` add `@blundergoat/goat-flow` as the project workflow dev dependency.

### Changed

- The README and usage docs now describe this package as a PHP bridge to sdk-python via a standard Python wrapper, not a raw sdk-python type mirror.
- Rich-input docs now cover wrapper-owned URL media, cache points, document context/citation controls, and the Python gateway translation boundary.
- `docs/wire-contract.md` now enumerates the full v1 surface: request envelopes, rich content blocks, response and usage fields, and stop reasons.
- The contract doc also covers flattened citation/guardrail shapes, stream SSE events, discovery responses, and observability constraints.
- `AgentResponse` now routes top-level `metadata` to `$wrapperMetadata`; `$metadata` keeps only unknown forward-compatible fields (see Compatibility).
- `AgentResponse::parseToolsUsed()` and `StreamEvent` complete events keep safe `tools_used[].input` and `.result` summaries when wrappers emit them.
- Streamed `tools_used` now carries the same detail as `invoke()`.
- `Usage::fromArray()` now accepts canonical snake_case and tolerant camelCase fields, supports `total_tokens`, and accepts numeric strings.
- `StreamEvent` and `StreamResult` now expose `contextSize` and `projectedContextSize` when a stream `complete` event includes them.
- `OtelTracingMiddleware` now names spans by operation (`invoke`, `stream`, `stream_sse`, `post_json`) and collapses custom routes to `/{custom}`.
- OTel spans record safe error attributes/events plus response and stream usage attributes from the new observer surface.
- `StrandsClient` now auto-detects `ResponseObserver` instances in the middleware stack and accepts explicitly configured response observers.
- Composer metadata now includes OTEL development dependencies, OTEL suggestion text, PSR discovery plugin allowance, and a `1.5.x-dev` branch alias.
- Development dependency management moved to npm lockfile tooling for `@blundergoat/goat-flow`.
- Symfony development and suggest constraints now allow Symfony 8 (`^6.4 || ^7.0 || ^8.0`) for the bundle integration packages.
- Response-observer fan-out moved from `StrandsClient` into an internal `ResponseObserverNotifier` collaborator.
- Observer auto-detection and dedup behavior are unchanged and now covered by dedicated tests.
- Documentation pass: UI-focused PHPDoc across `src/` and tests, plus new usage-guide Troubleshooting and wrapper-migration sections.
- Development tooling: `blundergoat/gruff-php` static analysis added to `require-dev`.

### Fixed

- Fixed silent zeroing of float `latency_ms` and `time_to_first_byte_ms` values: `Usage::$latencyMs` and `$timeToFirstByteMs` are now `int|float`.
- Fractional milliseconds now survive; token counts still coerce int, float, and numeric-string inputs.
- Fixed casing drift risk for usage counters by accepting both snake_case wrapper fields and camelCase upstream-style fields.
- Fixed nested `message.metadata` loss by preserving the raw message and hydrating typed message metadata.
- Fixed top-level `metadata` ambiguity with the dedicated `$wrapperMetadata` accessor; unknown top-level fields still land in the `$metadata` bucket.
- Fixed telemetry leakage risk: span attributes forbid prompt/response text, filenames, raw context metadata, and raw document content.
- Span attributes also bar citation source text, raw tool payloads, credentials, session ID values, and unsanitized exception messages.
- Fixed custom endpoint observability gaps by summarizing `postJson()` and `streamSse()` outcomes without inspecting app-owned payload content.
- Fixed PHPMD/preflight regressions from the observer and stream context surfaces; `HttpTransport` and `RequestMiddleware` stay backward compatible.
- Fixed `AgentErrorException::fromHttpResponse()` ignoring the contract's `message` field; it now leads, with the `detail`/`error` fallback unchanged.
- Fixed URL document/video sources omitting `media_type`: `withDocumentFromUrl()` and `withVideoFromUrl()` now include it like `withImageFromUrl()`.
- Fixed named Symfony client services unreachable at runtime: `strands.client.<name>` is now public, so `$container->get()` survives compilation.
- Fixed OTel tracing accuracy gaps: spans now close when request setup fails after middleware starts.
- Stream and stream_sse spans are marked as errors on terminal `error` events; `Accept` header detection is case-insensitive.
- `invoke`/`stream` operations classify correctly behind base paths such as API Gateway stage prefixes.
- Fixed unbalanced middleware teardown on request-setup failures: `afterResponse()` fires for exactly the middleware whose `beforeRequest()` ran.
- Teardown includes a middleware that threw from `beforeRequest()`, and never middleware the operation did not reach.
- Fixed OTel middleware tests being silently skipped: `phpunit.xml` now registers the `tests/Http` suite plus `failOnDeprecation`/`beStrictAbout*`.
- Fixed `deny-dangerous` hook bypasses: bare `&` chaining and no-space lockfile redirects (e.g. `>composer.lock`) are blocked, with self-test cases.

## [1.4.0] - 2026-03-08

### Added

- `StrandsClient::stream()` now logs at info level when the parser skips unknown event types - a hint the PHP client may need updating.
- `StreamEvent::tryFromArray(array $data): ?self` - returns `null` on unknown event types to skip them silently; `fromArray()` still throws.
- `Usage::totalTokens(): int` - convenience method returning `inputTokens + outputTokens`.
- `AgentResponse::$metadata` - `array` (default `[]`) capturing unrecognised top-level fields such as future `trace_id`/`model_id` additions.
- `StreamResult::$timeToFirstTextTokenMs` - client-side milliseconds until the first `Text` event; `null` when no text events arrived.
- The client-measured first-token time is distinct from the server-provided `Usage::$timeToFirstByteMs`.
- Interrupt awareness - `InterruptDetail` value object (`toolName`, `toolInput`, `toolUseId`, `interruptId`, `reason`).
- `AgentResponse::$interrupts` and `StreamResult::$interrupts` (both `list<InterruptDetail>`, default `[]`).
- `AgentResponse::isInterrupted()` and `StreamResult::isInterrupted()` convenience methods.
- `InterruptDetail::toResumeInput(mixed $response): AgentInput` - resumes after an interrupt via `interruptId`, falling back to `toolUseId`.
- `toResumeInput()` throws `LogicException` when neither identifier exists, preventing confusing server errors.
- `AgentInput::interruptResponse(string $interruptId, mixed $response): self` - static factory for creating interrupt response inputs.
- Guardrail trace - `GuardrailTrace` value object (`action`, `assessments`, `modelOutput`).
- `AgentResponse::$guardrailTrace` and `StreamResult::$guardrailTrace` (nullable, default `null`).
- Guardrail traces parse from top-level `guardrail_trace` or nested `trace.guardrail` in responses and Complete stream events.
- Citation content block extraction - `AgentResponse::$citations` from `citationsContent`/`citation` blocks in `message.content[]` (default `[]`).
- `StreamResult::$citations` accumulates from Citation stream events during streaming.
- Rich input support - `AgentInput` builder with clone-and-mutate pattern; `invoke()` and `stream()` accept `string|AgentInput $message`.
- `AgentInput::text()`, `->withImage()`, `->withDocument()`, `->withDocumentFromS3()`, `->withVideoFromS3()`, `->withStructuredOutputPrompt()`.
- AWS SigV4 auth strategy - `SigV4Auth` implements `AuthStrategy` for API Gateway IAM-protected agents; standalone, no `aws/aws-sdk-php` dependency.
- `SigV4Auth::fromEnvironment()` reads `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`.
- `SigV4Auth::__debugInfo()` masks credentials in `var_dump`/`print_r`; registered in Symfony (`driver: sigv4`) and Laravel DI config.
- `AgentErrorException::$errorCode` - machine-readable code from the body's `code`/`error_code`, for programmatic handling like rate-limit backoff.
- Empty message validation - `invoke()`/`stream()` throw `InvalidArgumentException` on empty strings; `AgentInput` with content blocks still passes.
- New test fixtures: `invoke-interrupt-response.json`, `invoke-guardrail-response.json`, `invoke-response-with-citations.json`.
- More fixtures: `invoke-response-with-metadata.json`, `sse-interrupt-complete.txt`, `sse-guardrail-complete.txt`, `sse-with-unknown-event.txt`.
- `setup-initial.sh` script for one-command dev environment setup (detects OS, installs pcov for coverage).
- Branch alias staleness check in `scripts/preflight-checks.sh` - flags a `composer.json` alias that doesn't match the latest CHANGELOG version.
- 477 tests, 1377 assertions.

### Changed

- `StrandsClient::stream()` refactored - extracted `buildStreamResult()`/`logSkippedEvents()`; complexity 24 to 17, length 166 to 114 lines.
- Middleware now runs before auth in `buildRequest()` and `buildJsonRequest()` - body-modifying middleware no longer invalidates SigV4 signatures.
- `StreamParser::feed()` CRLF normalization only processes the new chunk - O(chunk) not O(buffer) - and handles `\r\n` split across chunks.
- `StrandsClient::streamSse()` receives the same CRLF normalization optimization.
- Error messages improved - `AgentErrorException` formats as `"Agent returned HTTP {code}: {detail}"`.
- Transport errors include the URL (`"Expected JSON object from {url}, got {type}"`); stream interruptions include the URL.
- `StrandsClientFactory::createSigV4Auth()` throws `InvalidArgumentException` on partial credentials instead of falling through to env vars.
- `AgentInput::formatToMimeType()` maps `txt`, `csv`, `html`, `md`, `xml`, `json`, `yaml`/`yml`, `rtf`, `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`.
- MIME mapping uses registered types, e.g. OOXML for `docx`/`xlsx`/`pptx` and `application/msword` for `doc`.
- Minimum mutation testing MSI raised from 80% to 90%.
- PHPMD `ExcessiveParameterList` threshold raised from 16 to 18 to accommodate `StreamEvent` DTO constructor with interrupt and guardrail fields.
- `StreamEvent::fromArray()` refactored - internal logic extracted to `buildFromArray()` shared by both `fromArray()` and `tryFromArray()`.
- Symfony `Configuration` auth driver enum now includes `'sigv4'` alongside `'null'` and `'api_key'`.
- `StrandsClientFactory::resolveAuth()` supports `'sigv4'` with `region`, `service`, `access_key_id`, `secret_access_key`, `session_token`.
- Laravel `config/strands.php` now includes SigV4 auth options.
- Improved docstrings across `StrandsClient`, `AgentResponse`, `StreamResult`, `StreamEvent`, `AgentErrorException`, `Usage`, `StrandsClientFactory`.
- Symfony `Configuration::authNode()` docstring updated from "two drivers" to "three drivers" to reflect SigV4 support.
- Laravel `StrandsServiceProvider` `@var` type hint now includes SigV4 credential fields.
- `docs/usage-guide.md` authentication section expanded with SigV4Auth examples (explicit creds, `fromEnvironment()`, STS temp creds).

### Fixed

- `AgentInput::formatToMimeType('docx')` returns `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, not `application/docx`.
- SigV4Auth port handling - non-standard ports (e.g. 8443) join the canonical `Host` header as `hostname:port`; default ports 443/80 are omitted.

## [1.3.0] - 2026-02-21

### Added

- `RequestMiddleware` interface - operation-scoped middleware observing and modifying HTTP requests to Strands agents.
- `beforeRequest()` runs once before the first attempt (including retries); `afterResponse()` runs once after the final outcome.
- Middleware use cases: OpenTelemetry/Datadog tracing, custom header injection, request logging, metrics collection.
- Middleware pipeline in `StrandsClient` - `list<RequestMiddleware>` via constructor; applies to `invoke()`, `stream()`, `postJson()`, `streamSse()`.
- Middleware exceptions in `afterResponse()` are caught and logged, never propagated.
- Symfony bundle middleware support - `RequestMiddleware` autoconfigures via the `strands.middleware` tag and injects as a `TaggedIteratorArgument`.
- Laravel middleware support - resolved via `$app->tagged('strands.middleware')` and passed to `StrandsClientFactory`.
- Per-request `?int $timeoutSeconds` on `invoke()` and `stream()` - overrides config timeout per call; values < 1 throw `InvalidArgumentException`.
- Per-request timeout override on `postJson()` and `streamSse()` - optional `?int $timeout` parameter (in seconds) with the same semantics.
- Buffer overflow protection in `StreamParser` - throws `StreamInterruptedException` past 10 MB with no event delimiter (broken proxies, non-SSE).
- `retryableStatusCodes` validation in `StrandsConfig` - codes must be in the 400-599 range; out-of-range values throw `InvalidArgumentException`.
- `retryable_status_codes` option in Symfony bundle `Configuration` - array of integers, defaults to `[429, 502, 503, 504]`.
- `AgentErrorException::$responseBody` - full decoded JSON from 4xx/5xx responses for structured inspection; `null` when the body wasn't valid JSON.
- `AgentErrorException::fromHttpResponse()` - builds the exception from status, body, and decoded JSON, replacing duplicated transport logic.
- Stream cancellation support - `stream()` and `streamSse()` callbacks can return `false` to cancel.
- Cancellation is a transport-level abort: `SymfonyHttpTransport` calls `$response->cancel()`, breaks the chunk loop, closes the connection.
- `StreamResult::$cancelled` - whether the `onEvent` callback stopped the stream; distinguishes cancellation from interruption or completion.
- `PsrHttpTransport` accepts an optional `LoggerInterface`, logging a `notice` on first use when timeout parameters cannot be honoured.
- `Usage::fromArray(array $data)` - canonical factory for raw API arrays, replacing duplicated helpers in `AgentResponse` and `StrandsClient`.
- `HttpTransport::stream()` callback now supports returning `false` to signal cancellation to the transport layer.
- Mutation testing CI job - runs Infection on pull requests to `main` with `--min-msi=80 --min-covered-msi=80` (PHP 8.3, `continue-on-error: true`).
- 68 new unit tests (282 total, 843 assertions).

### Changed

- Retry delay capped at 30 seconds - `min(retryDelayMs * 2^attempt, 30_000) * random(0.5, 1.0)` - preventing absurd sleeps at high retry counts.
- `StreamEvent::fromArray()` uses `tryFrom()` with a descriptive `InvalidArgumentException` for unknown types; `from()` threw an opaque `ValueError`.
- Cancelled streams report status 0 to middleware `afterResponse()` - 200 previously misrepresented incomplete responses as successful.
- `StrandsConfig` endpoint validation uses `parse_url()` scheme+host, not `FILTER_VALIDATE_URL`; TLD-less hosts like `http://agent:8080` pass.
- `PsrHttpTransport` and `SymfonyHttpTransport` error mapping delegates to `AgentErrorException::fromHttpResponse()`, removing three duplicate blocks.
- Removed `StrandsClient::usageFromArray()` - one-line passthrough replaced by direct `Usage::fromArray()` call.
- `AgentResponse::parseUsage()` now delegates to `Usage::fromArray()`, eliminating code duplication.
- `StrandsClient::stream()` and `streamSse()` internal closures now return `bool` to propagate cancellation to the transport.

### Fixed

- `postJson()` and `streamSse()` now call `afterResponse()` on middleware - only `beforeRequest()` ran before, leaving observers blind to outcomes.
- Laravel `StrandsServiceProvider` now wires `RequestMiddleware` via `strands.middleware` tags - the factory previously got no middleware.
- `StreamParser` uses a `use` import for `StreamInterruptedException` instead of an inline FQCN - consistent with project style.
- `Strands` facade missing `@method` annotations for `postJson()` and `streamSse()` - IDE completion and static analysis now see all public methods.
- Laravel `StrandsServiceProvider` now injects the app's PSR-3 logger into `StrandsClientFactory` - logging was silently discarded before.
- Branch alias in `composer.json` updated from `1.1.x-dev` to `1.3.x-dev`.

## [1.2.0] - 2026-02-19

### Added

- `StrandsClient::postJson(string $path, array $payload): array` - JSON to custom agent endpoints, reusing auth, retry, timeout, and config.
- `StrandsClient::streamSse(string $path, array $payload, callable $onEvent): void` - SSE from custom endpoints as raw arrays keeping domain fields.
- `StopReason` with 7 Python SDK values: `EndTurn`, `ToolUse`, `MaxTokens`, `StopSequence`, `ContentFiltered`, `GuardrailIntervened`, `Interrupt`.
- `AgentResponse::$stopReason` and `StreamResult::$stopReason` - why the agent stopped, from `stop_reason` in responses and complete events.
- `AgentResponse::$structuredOutput` - schema-validated structured output from agents that return JSON conforming to a schema.
- `Usage::$cacheReadInputTokens`, `$cacheWriteInputTokens`, `$latencyMs`, `$timeToFirstByteMs` - metrics matching the Python SDK's `EventLoopMetrics`.
- `StreamEventType::Citation`, `::ReasoningSignature`, `::ReasoningRedacted` - new stream event types matching the Python SDK.
- `StreamEvent::$citation`, `StreamEvent::$reasoningSignature`, `StreamEvent::$stopReason` - new fields for the corresponding event types.
- `AgentResponse::$hasObjective` and `StreamEvent::$hasObjective`, hydrated from API `has_objective` when strictly `true`.
- 33 new unit tests (214 total, 578 assertions).

### Changed

- Expanded `composer.json` keywords for discoverability (`php`, `sdk`, `psr-18`, `laravel`, `symfony`).
- `scripts/preflight-checks.sh` runs PHP-CS-Fixer sequentially and falls back to single-process PHPStan when worker socket binding fails.
- PHPMD `ExcessiveParameterList` threshold raised from 13 to 16 to accommodate `StreamEvent` DTO constructor.
- Documentation/comment formatting cleanup for hyphen-as-dash spacing consistency.

## [1.1.0] - 2026-02-17

### Added

- Laravel service provider integration - config-driven agent registration, DI container bindings, and `Strands` facade.
- `StrandsServiceProvider` - registers `StrandsClientFactory`, default `StrandsClient` binding, and named `strands.client.<name>` bindings.
- `Strands` facade - proxies to the default `StrandsClient` with `@method` PHPDoc for IDE completion.
- Publishable `config/strands.php` with `default` agent key, `agents` array, and `env()` helpers.
- Auto-discovery via `extra.laravel` in `composer.json` - no manual provider registration needed.
- `docs/laravel-config.md` - full configuration reference for Laravel.

### Changed

- Extracted `StrandsClientFactory` to `StrandsPhpClient\Integration\StrandsClientFactory` - a shared base for the Laravel and Symfony integrations.
- `StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsClientFactory` now extends the shared base class.

## [1.0.0] - 2026-02-16

### Added

- `StrandsClient` with `invoke()` (blocking) and `stream()` (SSE) methods.
- `SymfonyHttpTransport` - full support for invoke + SSE streaming via `symfony/http-client`.
- `PsrHttpTransport` - invoke-only support via any PSR-18 HTTP client.
- `AgentResponse` - typed response object with text, agent name, session ID, usage stats, tools used.
- `StreamResult` - `stream()` returns accumulated text, session ID, usage stats, and event counts.
- `StreamEvent` - typed event object for SSE streaming (Text, ToolUse, ToolResult, Thinking, Complete, Error).
- `StreamParser` - incremental SSE parser: chunked delivery, CRLF/LF endings, malformed JSON recovery, `getSkippedEvents()` diagnostics.
- `AgentContext` - immutable builder for system prompts, metadata, permissions, documents, structured data.
- `NullAuth` - no-op auth strategy for local development.
- `ApiKeyAuth` - authentication strategy for API key / Bearer token auth. Configurable header name and value prefix.
- `AuthStrategy` interface - strategy pattern for pluggable authentication.
- Retry with exponential backoff and jitter - `maxRetries`/`retryDelayMs`; retries 429/502/503/504, fails fast on 400/401/403.
- Connect timeout - `connectTimeout` option (default 10s) separate from read `timeout` (default 120s).
- PSR-3 logging - optional `LoggerInterface` on `StrandsClient`. Logs requests at `debug`, retries at `warning`.
- Symfony bundle integration - YAML config, named agent services, autowiring, auto-detected transport, logger injection, `api_key` auth driver.
- `StrandsException`, `AgentErrorException`, `StreamInterruptedException` - exception hierarchy.
- PHPStan Level 10, PHP-CS-Fixer (PSR-12), PHPMD, cyclomatic complexity checks.
- CI matrix: PHP 8.2/8.3/8.4, Symfony 6.4/7.0.
- 100+ unit tests with fixture-based mocks (no network calls).

[Unreleased]: https://github.com/blundergoat/strands-php-client/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/blundergoat/strands-php-client/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/blundergoat/strands-php-client/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/blundergoat/strands-php-client/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/blundergoat/strands-php-client/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/blundergoat/strands-php-client/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/blundergoat/strands-php-client/releases/tag/v1.0.0
