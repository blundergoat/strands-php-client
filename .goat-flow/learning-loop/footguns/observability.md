---
category: observability
last_reviewed: 2026-05-24
---

## Footgun: RequestMiddleware Cannot Observe Parsed Results

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** high

**Symptoms:** Telemetry changes try to attach token usage, stop reasons, stream event counts, or parsed tool names inside `RequestMiddleware::afterResponse()`.

**Why it happens:** `RequestMiddleware` only sees URL, headers/body before the request and URL/status/duration/error after the transport call. Parsed `AgentResponse`, `StreamResult`, and custom SSE summaries are created later in `StrandsClient`.

**Evidence:** `src/Http/RequestMiddleware.php` (search: `afterResponse(string $url, int $statusCode`) has no result argument. `src/Http/ResponseObserver.php` (search: `afterInvoke`) is the additive terminal-result surface. `src/StrandsClient.php` (search: `notifyResponseObservers`) calls observers after parsing and before closing request middleware spans.

**Prevention:** Keep `RequestMiddleware` for request headers/body mutation and basic request lifecycle. Use `ResponseObserver` for parsed response/stream attributes. Do not widen `RequestMiddleware` signatures; external consumers implement that public interface.

## Footgun: Cross-interface auto-configuration double-fires observers

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** high

**Symptoms:** A `ResponseObserver` registered via Symfony DI fires `afterInvoke` / `afterStream` / `afterResponse` twice per request. Metrics counts inflate, audit logs duplicate, OTel attributes get set twice on the same span.

**Why it happens:** `src/Integration/Symfony/DependencyInjection/StrandsExtension.php` (search: `registerForAutoconfiguration`) tags every `RequestMiddleware` with `strands.middleware` AND every `ResponseObserver` with `strands.response_observer`. A class that implements both (for example `OtelTracingMiddleware`) is auto-tagged for both lists and reaches `StrandsClient` once as middleware and once as an explicit observer. Without dedup in `normaliseResponseObservers()`, both copies receive every hook.

**Evidence:** `src/StrandsClient.php` (search: `normaliseResponseObservers`) dedupes by `spl_object_id` for exactly this reason. `src/Http/Middleware/OtelTracingMiddleware.php` (search: `implements RequestMiddleware, ResponseObserver`) is the canonical class that exercises the path. The Laravel provider (`src/Integration/Laravel/StrandsServiceProvider.php`, search: `strands.middleware`) requires manual tagging so the trap is dormant unless the user tags both — Symfony triggers it automatically.

**Prevention:** Any code that fans observer-derived middleware back into the observer list MUST dedupe by object identity. Adding a new observer interface beside `ResponseObserver` would re-create this trap — give it the same dedup or document the merge contract.

## Footgun: afterResponse skipped when buildRequest throws

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** medium

**Symptoms:** A `RequestMiddleware` whose `beforeRequest()` acquired some state (active OTel scope, lock, counter increment) never sees `afterResponse()` when auth or a sibling middleware later throws. The state leaks into the next request — wrong parent/child traces, stuck counters, or "active" instrumentation that no longer corresponds to an in-flight call.

**Why it happens:** `src/StrandsClient.php` (search: `private function buildRequest`) invokes the middleware chain inside `buildRequest()` BEFORE the `try { postWithRetry(...) } catch` block. Exceptions thrown by middleware after the first one, or by `AuthStrategy::authenticate()` (which runs last inside `buildRequest`), escape the try and `notifyAfterResponse` is never called. The same shape exists in `stream()`, `postJson()`, and `streamSse()`.

**Evidence:** `src/StrandsClient.php` (search: `notifyAfterResponse`) is called inside the try/catch but `buildRequest()` callsites in `invoke()`, `stream()`, `postJson()`, and `streamSse()` happen outside it — grep for `= $this->buildRequest(` to see each callsite. `src/Http/Middleware/OtelTracingMiddleware.php` (search: `endOrphanedSpans`) is the reference implementation — it self-heals by draining stale spans at the start of every `beforeRequest()`.

**Prevention:** A middleware that pairs before/after state MUST be self-recovering. Either drain orphaned state at the next `beforeRequest()` call (cheapest), or wrap the activation step in a try/catch that cleans up locally if a partial failure happens inside `beforeRequest()` itself. Do not assume `afterResponse()` is guaranteed to run.
