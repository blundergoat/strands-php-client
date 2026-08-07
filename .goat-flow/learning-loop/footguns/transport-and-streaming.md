---
category: transport-and-streaming
last_reviewed: 2026-08-08
---

# Footguns — Transport & Streaming

Architectural traps that exist because of how the client is structured. Read before touching `src/Http/`, `src/Streaming/`, or stream-cancellation paths.

## Footgun: `HttpTransport` is a PHP `interface`, not an abstract class

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED

`src/Http/HttpTransport.php` (search: `interface HttpTransport`) declares both `post()` and `stream()` with no default bodies — PHP `interface` types cannot ship implementations. Adding a new transport (`PsrHttpTransport`, `SymfonyHttpTransport`, or third-party) means implementing both methods, even when one is unsupported. `PsrHttpTransport::stream()` deliberately throws because PSR-18 has no streaming. Do not "helpfully" promote the interface to an abstract class to share code — every existing implementer breaks and consumers who type-hint `HttpTransport` lose the contract guarantee.

`hallucination-risk: high` — easy to misread the interface as an abstract class from the file name alone.

## Footgun: `StreamParser` skips unknown events; `StreamEvent::fromArray()` throws on them

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED

`src/Streaming/StreamParser.php` (search: `skippedEvents++`) silently increments `skippedEvents` for unrecognised SSE types, while `src/Streaming/StreamEvent.php` (search: `throw new \InvalidArgumentException`) throws for the same unknown type. The asymmetry is **deliberate** — `StreamParser` calls `tryFromArray()` (search: `tryFromArray() returns null for unknown types`) which returns `null` instead of throwing, giving forward-compatibility on the stream path. Direct callers of `StreamEvent::fromArray()` get strict validation. Do not "unify" the behaviour: callers who hydrate events from cached payloads need the throw to catch schema drift, and live streams need to tolerate new event names from a newer server.

`hallucination-risk: high` — names suggest these should behave the same.

## Footgun: Stream cancellation requires literal `false` from the callback

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED

`src/Http/SymfonyHttpTransport.php` (search: `if ($onChunk($content) === false)`) calls `$response->cancel()` only when the user callback returns the boolean `false`. Returning `null`, `void`, `0`, or `""` continues the stream — the strict `=== false` check preserves backward compatibility with pre-cancellation callbacks that returned `void`. Refactoring this to `if (!$onChunk(...))` silently introduces cancellation on every callback that forgets to return, breaking every consumer that streams to a non-returning sink (loggers, echo, etc.). Cancellation also closes the TCP connection — it is not just a Boolean flag; it is an irreversible transport-level abort.

## Footgun: `PsrHttpTransport::stream()` is intentionally unimplemented

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED

`src/Http/PsrHttpTransport.php` only supports `post()`. PSR-18 has no streaming contract, so attempting to add a polling/chunking fake here breaks the "real streaming" guarantee that `SymfonyHttpTransport` users rely on (TTFT metrics, mid-stream cancellation, server-driven flushes). Document the limitation, do not paper over it. If a non-Symfony streaming transport is needed, add a new class (e.g., a Guzzle async transport) that explicitly opts into the streaming contract — do not retrofit it into the PSR-18 one.

## Footgun: `Usage::fromArray()` is the single canonical hydrator

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED

`src/Response/Usage.php` (search: `intField`) owns the defensive type-checking for token counts. `AgentResponse::parseUsage()` and `StrandsClient::usageFromArray()` both delegate to it. Adding a parallel hydrator (e.g., per-transport "fast path") forks the defensive logic — historically the source of silent zero-token bugs when the API returned strings instead of ints. New consumers of `Usage` must go through `Usage::fromArray()`.

## Footgun: `src/StrandsClient.php` is a coordination hot-spot

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED
**Source:** git history through `a4e30ff`

As of `a4e30ff`, repository history contains 18 commits touching `src/StrandsClient.php`, 11 touching `src/Streaming/StreamParser.php`, and 9 touching `src/Http/PsrHttpTransport.php`. `StrandsClient` coordinates retry-with-backoff, middleware ordering, message validation, and stream-cancellation propagation, so it is the hotter change surface. Before editing, decide whether the behavior belongs in one transport, cross-cutting middleware, or client orchestration; the wrong layer can change retry or middleware semantics.
