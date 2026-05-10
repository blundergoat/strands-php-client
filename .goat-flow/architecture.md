# Architecture

## System Overview

Strands PHP Client is a layered HTTP client SDK for communicating with Strands AI agents. It supports synchronous invocations (`invoke()`) and real-time Server-Sent Events streaming (`stream()`), with pluggable authentication, middleware, retry logic, and two HTTP transport implementations. The library also provides `postJson()` and `streamSse()` for custom agent endpoints with arbitrary payloads.

The layers are separated to allow framework-agnostic usage (via PSR-18) while providing full streaming support through Symfony HttpClient. Laravel and Symfony integrations wrap the core client with framework-specific DI, configuration, and named agent bindings.

## Request Flow

```
User Code
  └─ StrandsClient.invoke(message, context, sessionId)
      ├─ buildJsonRequest() → JSON-encode payload + set headers
      │   ├─ RequestMiddleware[*].beforeRequest() — may modify headers/body
      │   └─ AuthStrategy.authenticate() — adds auth headers (runs AFTER middleware)
      ├─ postWithRetry() — retry loop with exponential backoff + jitter
      │   └─ HttpTransport.post() — sends HTTP POST, returns decoded JSON
      │       ├─ SymfonyHttpTransport — full support (invoke + stream)
      │       └─ PsrHttpTransport — invoke only (stream() throws)
      ├─ AgentResponse::fromArray() — hydrates typed DTO from response
      └─ RequestMiddleware[*].afterResponse() — observe completion (exceptions swallowed)
```

For streaming, the transport calls `onChunk()` with raw SSE data. `StreamParser.feed()` buffers chunks and emits typed `StreamEvent` objects. The client accumulates events into a `StreamResult`.

## Auth / Trust Boundaries

Authentication is pluggable via the `AuthStrategy` interface (single method: `authenticate()`). Three implementations ship: `NullAuth` (local dev), `ApiKeyAuth` (Bearer token or custom header), and `SigV4Auth` (AWS IAM, standalone — no aws-sdk-php dependency).

Auth runs **after** middleware so that SigV4 signatures cover the final request body after middleware mutations. This ordering is intentional and must be preserved.

## Data Flow

All state is transient — no persistent storage. The client sends JSON payloads to a remote Strands agent endpoint and receives either a JSON response (invoke) or an SSE stream. Session continuity is managed server-side via `sessionId` passed through the client.

`StreamParser` maintains an internal buffer (max 10 MB) that accumulates raw SSE chunks until complete events (delimited by `\n\n`) are detected. Malformed JSON in events is skipped and counted, never thrown.

## Deployment / Operations

This is a library, not a service. Consumers install via Composer (`composer require blundergoat/strands-php-client`). CI runs via GitHub Actions (`.github/workflows/ci.yml`) with quality gates: PHPUnit, PHPStan Level 10, PHP-CS-Fixer, PHPMD, cyclomatic complexity check, and Infection mutation testing. The `composer preflight` script runs all gates.

PHP 8.2+ is required. No Docker, no database, no infrastructure.
