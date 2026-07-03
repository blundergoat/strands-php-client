---
category: observability
last_reviewed: 2026-05-24
---

## Pattern: Additive Terminal Observation Surface

**Created:** 2026-05-24

**Context:** Middleware needs safe metadata from parsed invoke responses, accumulated stream results, or raw custom endpoint summaries, but existing `RequestMiddleware` implementers must remain compatible.

**Approach:** Add a sibling observer interface instead of widening the existing middleware contract. Keep request/transport lifecycle in `RequestMiddleware`; put parsed terminal metadata in `ResponseObserver`. When a middleware implements both, the client should call the observer while the request span is still open, then close the request middleware span with `afterResponse()`.

**Evidence:** `src/Http/ResponseObserver.php` (search: `afterStreamSse`) defines terminal hooks. `src/Http/Middleware/OtelTracingMiddleware.php` (search: `implements RequestMiddleware, ResponseObserver`) consumes both surfaces. `src/StrandsClient.php` (search: `observerMiddleware`) auto-detects middleware that also implements the observer interface.
