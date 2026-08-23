---
category: transport-and-streaming
last_reviewed: 2026-08-23
---

# Footguns — Transport & Streaming

Architectural traps that exist because of how the client is structured. Read before touching `src/Http/`, `src/Streaming/`, auth ordering, or stream-cancellation paths. This is the single bucket for transport and streaming traps — the former `transport.md` bucket was merged here so one retrieval reaches one entry per root cause.

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

## Footgun: Constructor-only parser state can break legacy subclasses at first use

**Status:** active | **Created:** 2026-08-22 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Give new private parser state a safe declaration default or initialize it lazily before first use.
**Trigger phase:** ACT

`src/Streaming/StreamParser.php` (search: `private ?SseFrameDecoder $frameDecoder = null`) creates its decoder lazily because the 1.4 class had
no constructor. A consumer subclass with its own constructor therefore had no parent constructor to call. Adding constructor-only initialized
state still lets that subclass load, but its first `feed()` call fails on the uninitialized property. The compatibility fixture (search:
`class V1StreamParserExtension`) preserves this runtime check because signature reflection alone reports a new constructor as additive.

## Footgun: `PsrHttpTransport::stream()` is intentionally unimplemented

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED

`src/Http/PsrHttpTransport.php` only supports `post()`; `stream()` (search: `SSE streaming is not supported by PsrHttpTransport`) throws `StrandsException` unconditionally, and the message tells the caller to install `symfony/http-client`. Callers on this transport get `invoke()` and `postJson()` only — never `stream()` or `streamSse()`. PSR-18 has no streaming contract, so attempting to add a polling/chunking fake here breaks the "real streaming" guarantee that `SymfonyHttpTransport` users rely on (TTFT metrics, mid-stream cancellation, server-driven flushes). Document the limitation, do not paper over it. If a non-Symfony streaming transport is needed, add a new class (e.g., a Guzzle async transport) that explicitly opts into the streaming contract — do not retrofit it into the PSR-18 one.

## Footgun: `PsrHttpTransport` silently ignores timeout parameters

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`PsrHttpTransport::post()` (search: `does not support timeout parameters`) accepts `$timeout` and `$connectTimeout` and ignores both, logging a notice once. Timeouts must be configured on the underlying PSR-18 client instance. Per-request timeout overrides passed to `invoke()` or `postJson()` have no effect on this transport, so a caller tuning timeouts against a PSR-18 client is tuning nothing.

## Footgun: Auth runs AFTER middleware by design

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`StrandsClient::buildRequest()` (search: `private function buildRequest`) and `StrandsClient::buildJsonRequest()` (search: `private function buildJsonRequest`) both run `RequestMiddleware::beforeRequest()` before `AuthStrategy::authenticate()`. The ordering is load-bearing: it lets `SigV4Auth` sign the final headers and body after every middleware mutation. Reversing it — or inserting auth into the middleware chain — invalidates every signature the moment any middleware touches the body.

## Footgun: SSE line endings must be normalized across chunk boundaries

**Status:** active | **Created:** 2026-08-22 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Route typed and raw SSE chunks through `SseFrameDecoder`; never normalize each transport chunk independently.
**Trigger phase:** ACT

`src/Streaming/SseFrameDecoder.php` (search: `isPreviousChunkEndingWithCarriageReturn`) remembers when one chunk ends in `\r` and consumes a
leading `\n` from the next chunk as the second half of the same CRLF. A chunk-local `str_replace(["\r\n", "\r"], "\n", $chunk)` turns that split
pair into two newlines, falsely terminates the SSE frame, and can silently skip valid multi-line JSON. Verify framing at every split position and
keep `StreamParser::feed()` and `StrandsClient::streamSse()` on the shared decoder so the typed and raw paths cannot drift.

## Footgun: sdk-python `AgentResult` adds one framework newline to text

**Status:** active | **Created:** 2026-08-22 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Remove at most one final newline when mapping `AgentResult`; never use `rstrip()` on the user's completed text.
**Trigger phase:** ACT

`examples/python-gateway/contract.py` (search: `removesuffix("\n")`) removes the one framework separator observed on sdk-python text results.
Using `rstrip("\n")` also erases trailing newlines that belong to the generated answer. The gateway smoke test (search: `mapped_with_user_newline`)
measures both outcomes without model credentials and is the durable guard when the scratch SDK reference is refreshed or removed.

## Footgun: `Usage::fromArray()` is the single canonical hydrator

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED

`src/Response/Usage.php` (search: `intField`) owns the defensive type-checking for token counts. Every consumer routes through it: `AgentResponse::parseUsage()` (search: `private static function parseUsage`), `MessageMetadata::fromArray()`, `OtelTracingMiddleware` (search: `setUsageAttributes($span, Usage::fromArray`), and `StrandsClient` at both the stream-complete and raw-usage paths (search: `Usage::fromArray($completeEvent->usage)`). The old `StrandsClient::usageFromArray()` passthrough was removed — do not reintroduce a wrapper. Adding a parallel hydrator (e.g., a per-transport "fast path") forks the defensive logic, historically the source of silent zero-token bugs when the API returned numeric strings instead of ints.

The same rule covers the *optional* numbers beside a counter: `src/Response/WireNumber.php` (search: `optionalWholeNumber`) is canonical for every nullable wire number, and `AgentResponse`, `StreamEvent`, `CitationLocation`, and `GuardrailAssessment` all read through it. These two hydrators answer different questions and are not a fork: `Usage` returns a required counter that defaults to `0`, while `WireNumber` returns `null` so the caller can omit an unusable value. Both were forked once before: until the 1.5 line, three private `optionalIntegerField()` copies existed and only the `AgentResponse` copy had the range guard, so a wrapper sending `context_size: "9223372036854775807"` hydrated as `PHP_INT_MAX` on invoke and `PHP_INT_MIN` on the stream and citation paths. `tests/Unit/Response/WireNumberConsistencyTest.php` (search: `testEveryHydratorAgreesOnOneWireNumber`) now fails if the paths ever disagree again.

## Footgun: `src/StrandsClient.php` is a coordination hot-spot

**Status:** active | **Created:** 2026-05-24 | **Evidence:** ACTUAL_MEASURED
**Source:** git history through `a4e30ff`

As of `a4e30ff`, repository history contains 18 commits touching `src/StrandsClient.php`, 11 touching `src/Streaming/StreamParser.php`, and 9 touching `src/Http/PsrHttpTransport.php`. `StrandsClient` coordinates retry-with-backoff, middleware ordering, message validation, and stream-cancellation propagation, so it is the hotter change surface. Before editing, decide whether the behavior belongs in one transport, cross-cutting middleware, or client orchestration; the wrong layer can change retry or middleware semantics.

## Resolved Entries

## Footgun: `StreamParser` 10 MB buffer limit rejected oversized chunks whole

**Status:** resolved | **Created:** 2026-05-08 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Limit each unfinished SSE frame, draining every delimiter before measuring the next frame in the same transport chunk.
**Trigger phase:** ACT

Before 1.5.0, `StreamParser::feed()` checked the combined buffer and incoming chunk before scanning for delimiters, so two valid 6 MB frames
delivered together exceeded the 10 MB cap and interrupted typed and raw streams. `SseFrameDecoder::extractFrames()` (search: `private function
extractFrames`) now drains complete frames one at a time, while `appendFrameBytes()` applies the guard only to the current unfinished frame.
`StreamParserTest::testLargeChunkWithBoundedFramesDoesNotTriggerBufferLimit()` reproduces the former user-visible failure.
