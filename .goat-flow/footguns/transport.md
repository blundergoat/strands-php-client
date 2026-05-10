---
category: transport
last_reviewed: 2026-05-08
---

## Footgun: HttpTransport is an interface, not an abstract class

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`HttpTransport` (search: `interface HttpTransport`) is a PHP interface. Adding a new method to it is a breaking change — every implementation (`SymfonyHttpTransport`, `PsrHttpTransport`, and any consumer implementations) must be updated simultaneously. Cannot use default method bodies as a migration path.

## Footgun: PsrHttpTransport.stream() always throws

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`PsrHttpTransport::stream()` (search: `'SSE streaming is not supported by PsrHttpTransport'`) unconditionally throws `StrandsException`. PSR-18 has no chunked transfer API. Callers using `PsrHttpTransport` can only use `invoke()`, `postJson()` — never `stream()` or `streamSse()`. The error message tells the user to install `symfony/http-client`.

## Footgun: Auth runs AFTER middleware by design

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

In `StrandsClient::buildJsonRequest()` (search: `buildJsonRequest`), middleware `beforeRequest()` runs first, then `AuthStrategy::authenticate()`. This ordering is intentional so that SigV4 signatures cover the final request body after middleware mutations. Reversing this order breaks SigV4 auth silently (signatures won't match).

## Footgun: StreamParser 10 MB buffer limit

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`StreamParser` (search: `MAX_BUFFER_SIZE`) throws `StreamInterruptedException` if the buffer exceeds 10 MB without encountering a complete SSE event delimiter (`\n\n`). This protects against unbounded memory growth from broken proxies, but can trigger on legitimate large payloads from agents that produce very large single events.

## Footgun: PsrHttpTransport silently ignores timeout parameters

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`PsrHttpTransport::post()` (search: `'PsrHttpTransport does not support timeout parameters'`) accepts `$timeout` and `$connectTimeout` parameters but ignores them — logging a notice once. Timeouts must be configured on the underlying PSR-18 client instance directly. Per-request timeout overrides in `postJson()` have no effect with this transport.
