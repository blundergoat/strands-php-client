---
category: observability
last_reviewed: 2026-08-08
---

## Footgun: RequestMiddleware Cannot Observe Parsed Results

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** high

**Symptoms:** Telemetry changes try to attach token usage, stop reasons, stream event counts, or parsed tool names inside `RequestMiddleware::afterResponse()`.

**Why it happens:** `RequestMiddleware` only sees URL, headers/body before the request and URL/status/duration/error after the transport call. Parsed `AgentResponse`, `StreamResult`, and custom SSE summaries are created later in `StrandsClient`.

**Evidence:** `src/Http/RequestMiddleware.php` (search: `afterResponse(string $url, int $statusCode`) has no result argument. `src/Http/ResponseObserver.php` (search: `afterInvoke`) is the additive terminal-result surface. `src/Http/ResponseObserverNotifier.php` (search: `notifyResponseObservers`) fans results out to observers; `StrandsClient` calls it after parsing and before closing request middleware spans.

**Prevention:** Keep `RequestMiddleware` for request headers/body mutation and basic request lifecycle. Use `ResponseObserver` for parsed response/stream attributes. Do not widen `RequestMiddleware` signatures; external consumers implement that public interface.

## Footgun: Cross-interface auto-configuration double-fires observers

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** high

**Symptoms:** A `ResponseObserver` registered via Symfony DI fires `afterInvoke` / `afterStream` / `afterResponse` twice per request. Metrics counts inflate, audit logs duplicate, OTel attributes get set twice on the same span.

**Why it happens:** `src/Integration/Symfony/DependencyInjection/StrandsExtension.php` (search: `registerForAutoconfiguration`) tags every `RequestMiddleware` with `strands.middleware` AND every `ResponseObserver` with `strands.response_observer`. A class that implements both (for example `OtelTracingMiddleware`) is auto-tagged for both lists and reaches `StrandsClient` once as middleware and once as an explicit observer. Without dedup in `ResponseObserverNotifier::normaliseResponseObservers()`, both copies receive every hook.

**Evidence:** `src/Http/ResponseObserverNotifier.php` (search: `normaliseResponseObservers`) dedupes by `spl_object_id` for exactly this reason; regression coverage lives in `tests/Unit/ResponseObserverTest.php` (search: `testObserverRegisteredAsMiddlewareAndObserverIsNotifiedOnce`). `src/Http/Middleware/OtelTracingMiddleware.php` (search: `implements RequestMiddleware, ResponseObserver`) is the canonical class that exercises the path. The Laravel provider (`src/Integration/Laravel/StrandsServiceProvider.php`, search: `strands.middleware`) requires manual tagging so the trap is dormant unless the user tags both — Symfony triggers it automatically.

**Prevention:** Any code that fans observer-derived middleware back into the observer list MUST dedupe by object identity. Adding a new observer interface beside `ResponseObserver` would re-create this trap — give it the same dedup or document the merge contract.

## Resolved Entries

## Footgun: afterResponse skipped when buildRequest throws

**Status:** resolved | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**Decision changed:** Keep every request-construction path behind the cleanup wrappers so late setup failures close middleware state.
**Trigger phase:** ACT

**Resolution:** Request preparation now tracks whether middleware started and catches later setup failures around both standard and custom requests.

**Evidence:** `src/StrandsClient.php` (search: `notifyAfterRequestSetupFailure`) closes middleware after `prepareAgentRequest()` or `prepareJsonRequest()` catches a setup exception. `src/Http/Middleware/OtelTracingMiddleware.php` (search: `$this->spanStack->pop()`) then detaches and ends the active span.

**Prevention:** Keep standard requests routed through `prepareAgentRequest()` and custom requests through `prepareJsonRequest()`. Any new request builder must preserve the same cleanup wrapper.
