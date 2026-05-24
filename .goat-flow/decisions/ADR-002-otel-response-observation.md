# ADR-002: OTEL Response Observation Surface

**Status:** Accepted
**Date:** 2026-05-24

## Decision

`strands-php-client` will keep `RequestMiddleware` unchanged and add a separate response-observation surface for parsed terminal operation data.

The response-observation surface is additive. Existing middleware continues to implement `RequestMiddleware` and continues to receive `beforeRequest()` and `afterResponse()` calls. Observers receive only terminal summaries after the client has parsed the operation:

- parsed `AgentResponse` for `invoke()`;
- parsed `StreamResult` for typed `stream()`;
- raw `postJson()` response arrays, with OTEL implementations restricted to known-safe summary fields only;
- sanitized `streamSse()` summaries, not raw event payloads.

`OtelTracingMiddleware` may implement both `RequestMiddleware` and the new observer interface so existing applications that already register it as middleware gain richer span attributes without changing their call sites.

Framework integrations must keep existing `strands.middleware` wiring working. If a separate observer tag is added, it must be additive and must not require existing middleware services to change signatures.

## Context

M05 requires usage, stop reason, stream event counts, cancellation, and custom endpoint summary attributes on OpenTelemetry spans. The current `RequestMiddleware` interface can see only the URL, headers, body, status code, duration, and exception. It cannot see `AgentResponse`, `StreamResult`, or terminal SSE summaries.

Durable constraints:

- `src/Http/RequestMiddleware.php` (search: `interface RequestMiddleware`) is a public interface implemented by user code and active consumers.
- `.goat-flow/footguns/transport.md` (search: `HttpTransport is an interface`) records the interface-expansion hazard.
- `/home/devgoat/projects/halaxy-agents-lab` has user-authored request middleware, so widening `RequestMiddleware::afterResponse()` would be a consumer break.
- M05 forbids request/response body capture and raw tool or context payload capture in spans.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Widen `RequestMiddleware::afterResponse()` | Breaks existing implementations and forces unrelated middleware to accept parsed response objects. | Rejected. This is a public interface break. |
| Add response data to `HttpTransport` | Breaks the transport abstraction and cannot represent parsed client-level DTOs cleanly. | Rejected. `HttpTransport` remains an interface for raw HTTP only. |
| Let `StrandsClient` set OTEL attributes directly | Couples the client core to OTEL and creates runtime cost/knowledge for non-OTEL users. | Rejected. OTEL stays opt-in middleware/observer behavior. |
| Add an additive response observer interface | Preserves existing middleware and transport contracts while making parsed terminal data observable. | Accepted. |

## Consequences

- `RequestMiddleware` signatures remain stable.
- `OtelTracingMiddleware` can enrich spans after parsed responses exist.
- Raw custom endpoint payloads remain app-owned; OTEL code may summarize only safe fields such as route, status, counts, terminal state, and normalized usage.
- Symfony/Laravel integrations can support observer autowiring additively, but existing `strands.middleware` services must keep working unchanged.

## Reversibility

This is a two-way door. If a future v2 client introduces a broader hook system, the observer interface can become a compatibility adapter into that system.

Reversal must preserve `RequestMiddleware` compatibility for the 1.x line or introduce a documented major-version migration.
