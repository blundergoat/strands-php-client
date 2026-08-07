---
category: transport
last_reviewed: 2026-08-08
---

## Footgun: HttpTransport is an interface, not an abstract class

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`HttpTransport` (search: `interface HttpTransport`) is a PHP interface. Adding a new method to it is a breaking change — every implementation (`SymfonyHttpTransport`, `PsrHttpTransport`, and any consumer implementations) must be updated simultaneously. Cannot use default method bodies as a migration path.

## Footgun: PsrHttpTransport.stream() always throws

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`PsrHttpTransport::stream()` (search: `'SSE streaming is not supported by PsrHttpTransport'`) unconditionally throws `StrandsException`. PSR-18 has no chunked transfer API. Callers using `PsrHttpTransport` can only use `invoke()`, `postJson()` — never `stream()` or `streamSse()`. The error message tells the user to install `symfony/http-client`.

## Footgun: Auth runs AFTER middleware by design

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

Both `StrandsClient::buildRequest()` (search: `private function buildRequest`) and `StrandsClient::buildJsonRequest()` (search: `private function buildJsonRequest`) run `RequestMiddleware::beforeRequest()` before `AuthStrategy::authenticate()`. This ordering lets SigV4 cover the final headers and body after middleware mutations. Reversing it invalidates signatures.

## Footgun: StreamParser 10 MB buffer limit

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`StreamParser::feed()` (search: `strlen($this->buffer) + strlen($chunk)`) checks the combined buffered byte count before parsing the new chunk for event delimiters. A chunk that takes the combined size over 10 MB throws `StreamInterruptedException`, including a chunk containing delimiters later in that same chunk. Keep individual chunks and events below the cap; do not assume delimiters inside an oversized incoming chunk are parsed first.

## Footgun: PsrHttpTransport silently ignores timeout parameters

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`PsrHttpTransport::post()` (search: `'PsrHttpTransport does not support timeout parameters'`) accepts `$timeout` and `$connectTimeout` parameters but ignores them — logging a notice once. Timeouts must be configured on the underlying PSR-18 client instance directly. Per-request timeout overrides in `postJson()` have no effect with this transport.
