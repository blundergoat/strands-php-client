# Architecture — strands-php-client

PHP 8.2+ library that acts as a thin client for a remote Strands agent (typically a Python FastAPI service). It is intentionally **stateless and loop-free**: every method either marshals one HTTP POST to the agent or streams an SSE response — the agentic reasoning loop runs on the server side. The wire boundary is the Strands HTTP Wire Contract v1 — see `docs/wire-contract.md` and `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md`.

## System Overview

Six cooperating components, separated so a host application can swap any of them without touching the others:

- **`StrandsClient`** (`src/StrandsClient.php`) — the public surface. Four entry points: `invoke()` (sync), `stream()` (typed SSE), `postJson()` and `streamSse()` (custom-endpoint passthrough). Owns retry-with-backoff, middleware ordering, message validation, and stream-cancellation propagation.
- **`HttpTransport`** (`src/Http/HttpTransport.php`) — interface. Two implementations: `SymfonyHttpTransport` (full: POST + streaming, requires `symfony/http-client`) and `PsrHttpTransport` (POST only, any PSR-18 client). PSR-18 does not support real streaming — that's a spec limitation, not a project bug.
- **`AuthStrategy`** (`src/Auth/AuthStrategy.php`) — interface. Three implementations: `NullAuth` (Null-Object, local dev), `ApiKeyAuth` (bearer/custom header), `SigV4Auth` (AWS IAM, standalone — no aws-sdk-php dependency). Applied to outgoing headers before the request leaves the transport.
- **`Context` / `Input` builders** (`src/Context/`) — `AgentContext` (immutable clone-and-mutate for system prompts, metadata, permissions, documents), `AgentInput` (multi-modal text/image/document/interrupt-response payload). Both serialize defensively.
- **Response/Streaming DTOs** (`src/Response/`, `src/Streaming/`) — `AgentResponse`, `Message`, `MessageMetadata`, `Usage`, `StopReason`, `GuardrailTrace`, `GuardrailAssessment`, `InterruptDetail`, citation DTOs, `StreamEvent`, `StreamEventType`, `StreamResult`, `StreamSseSummary`. All readonly, all hydrated via static `fromArray()` factories with defensive type checks.
- **Observation surface** (`src/Http/ResponseObserver.php`, `src/Http/ResponseObserverNotifier.php`, `src/Http/Middleware/OtelTracingMiddleware.php`) — additive parsed-response hooks for observability. `ResponseObserverNotifier` normalises parsed-response observers and deduplicates middleware observers. `RequestMiddleware` stays source-compatible for request lifecycle work; `ResponseObserver` receives parsed `AgentResponse`, `StreamResult`, raw JSON summaries, and sanitized raw-SSE summaries after client parsing.

Boundary rationale: transport and auth are interfaces so the library never depends on a specific HTTP client; DTOs are readonly so caller code cannot corrupt accumulated state; the agent loop is server-side so PHP processes stay short-lived and request-scoped.

## Request Flow

A representative `invoke()` call:

1. Caller invokes `StrandsClient::invoke($message, $context, $sessionId, $timeoutSeconds)`.
2. `validateMessage()` rejects empty input. `$timeoutSeconds` validated `>= 1`.
3. Request body assembled from `AgentContext` / `AgentInput`.
4. `RequestMiddleware` (`src/Http/RequestMiddleware.php`) `beforeRequest()` runs in registration order to mutate headers/body.
5. **Auth runs AFTER middleware** so SigV4 signatures cover the final body (this ordering is load-bearing for SigV4 correctness — do not reorder).
6. `postWithRetry()` — exponential backoff with jitter on retryable status codes; non-retryable errors surface as `AgentErrorException` carrying `responseBody`.
7. Transport returns decoded JSON; `AgentResponse::fromArray()` hydrates the DTO defensively (unknown fields go to `metadata` for forward-compat).
8. Parsed-response observers (`ResponseObserver`) receive parsed terminal operation data. Middleware that also implements `ResponseObserver` is deduplicated by object identity to avoid double-firing when framework autoconfiguration tags it twice.
9. `RequestMiddleware[*]::afterResponse()` observes completion; exceptions in `afterResponse` are swallowed by design (observers must not break the call).
10. Interrupts are surfaced as `InterruptDetail[]`; the caller can resume with `AgentInput::interruptResponse(...)`.

`stream()` mirrors this but pipes raw chunks through `StreamParser` (incremental SSE — tolerates unknown event types, throws on malformed terminal frames) and dispatches typed `StreamEvent` objects to the caller's `onEvent` callback. `StreamParser` keeps an internal buffer capped at 10 MB to defend against unbounded server output. Returning `false` from the callback propagates a cancellation through `StrandsClient` → `SymfonyHttpTransport::stream()` → `$response->cancel()`, closing the TCP connection immediately.

## Auth / Trust Boundaries

- The client is one trust hop: caller code → `StrandsClient` → middleware → `AuthStrategy::authenticate()` → outbound HTTPS to the agent.
- Auth runs after middleware (see Request Flow step 5). This is non-negotiable for `SigV4Auth` — signatures cover the final post-middleware body.
- `NullAuth` is intentionally permitted — local Docker Compose dev uses it. Production callers must explicitly choose `ApiKeyAuth` or `SigV4Auth`.
- `SigV4Auth::fromEnvironment()` reads `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` — handy for IAM-role hosts; document explicitly when used so secrets-source is auditable.
- No tokens are persisted or logged. PSR-3 log context arrays must never include the `Authorization` header.

## Data Flow

The client holds **no durable state**. Session continuity is the agent's responsibility — callers pass `sessionId` through, the server manages history. Caches: none. Queues: none. Third-party APIs: the agent endpoint plus AWS STS (only when `SigV4Auth` resolves credentials). Outbound: one POST per `invoke()` / `postJson()`; one POST + one SSE stream per `stream()` / `streamSse()`. `StreamParser` discards malformed event JSON silently (counted, never thrown) and tolerates unknown event types via `tryFromArray()`.

## Integration Surfaces

- **Symfony bundle** (`src/Integration/Symfony/`) — `StrandsBundle` + `StrandsExtension` build a service per named agent (`strands.client.<name>`). YAML schema in `Configuration.php`. Monolog auto-injected.
- **Laravel service provider** (`src/Integration/Laravel/`) — `StrandsServiceProvider` registers default + named agents from `src/Integration/Laravel/config/strands.php` (publishable). `Strands` facade exposes the default client. Auto-discovery via `composer.json` `extra.laravel.providers`.
- Both integrations share `StrandsClientFactory` (`src/Integration/StrandsClientFactory.php`) so transport detection, auth-driver resolution, middleware wiring, and response-observer wiring stay identical across frameworks.
- Response observers use the `strands.response_observer` tag in framework integrations. `OtelTracingMiddleware` may be registered as normal request middleware and still receive parsed response callbacks because `StrandsClient` auto-detects observer middleware and deduplicates it.

## Local Data and Evidence Budget

Plans, scratchpad entries, session notes, and generated quality, event, critique, review, and security reports are checkout-local evidence. Their directories keep only control anchors such as READMEs and ignore rules in version control; run artifacts are gitignored and are not durable project truth. Use them to resume or orient work, not to prove current behavior or authorize an external action.

Promote only a verified conclusion into the appropriate learning-loop bucket. Redact durable text before writing it. goat-flow does not purge local artifacts automatically; the user controls retention.

## Deployment / Operations

This is a library — there is no runtime to deploy. Quality gates run locally via `composer preflight` and as explicit steps in `.github/workflows/ci.yml` on every push:
- PHPUnit (`composer test`) — must be green.
- PHPStan Level 10 (`composer analyse`) — strictest setting; no `mixed` leaks tolerated.
- PHP-CS-Fixer (`composer cs:check`) — PSR-12.
- Shellcheck — shell scripts under `scripts/` and `.goat-flow/hooks/`.
- PHPMD (`composer analyse:messdetector`).
- Cyclomatic complexity ≤ 20 per method (`scripts/check-cyclomatic-complexity.php`).
- Infection mutation testing (`composer mutate`) — slow, optional locally, but ≥ 90% MSI target per `infection.json5`.

goat-flow shared skill-doc playbooks live under `.goat-flow/skill-docs/playbooks/`: `browser-use.md`, `changelog.md`, `code-comments.md`, `gruff-code-quality.md`, `hook-policy-testing.md`, `observability.md`, `page-capture.md`, `release-notes.md`, `skill-playbook-authoring-sync.md`, and `writing-style.md`.

Releases tag from `main`. Branch alias `dev-main` → `1.5.x-dev` in `composer.json`. Packagist publishes on tag.
