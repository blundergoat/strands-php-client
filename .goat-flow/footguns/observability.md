---
category: observability
last_reviewed: 2026-05-24
---

## Footgun: RequestMiddleware Cannot Observe Parsed Results

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** high

**Symptoms:** Telemetry changes try to attach token usage, stop reasons, stream event counts, or parsed tool names inside `RequestMiddleware::afterResponse()`.

**Why it happens:** `RequestMiddleware` only sees URL, headers/body before the request and URL/status/duration/error after the transport call. Parsed `AgentResponse`, `StreamResult`, and custom SSE summaries are created later in `StrandsClient`.

**Evidence:** `src/Http/RequestMiddleware.php` (search: `afterResponse(string $url, int $statusCode`) has no result argument. `src/Http/ResponseObserver.php` (search: `afterInvoke`) is the additive terminal-result surface. `src/StrandsClient.php` (search: `notifyInvokeObservers`) calls observers after parsing and before closing request middleware spans.

**Prevention:** Keep `RequestMiddleware` for request headers/body mutation and basic request lifecycle. Use `ResponseObserver` for parsed response/stream attributes. Do not widen `RequestMiddleware` signatures; external consumers implement that public interface.
