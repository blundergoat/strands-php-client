# Wire Contract Consumer Matrix

This matrix records how active projects use `strands-php-client`. It informs Strands HTTP Wire Contract v1 compatibility; it does not ask consumers to
migrate to raw sdk-python shapes.

## Summary

| Project | Standard `invoke()` | Standard `stream()` | `postJson()` custom endpoints | `streamSse()` custom endpoints | Compatibility status |
| --- | --- | --- | --- | --- | --- |
| `ambient-scribe` | no | no | yes | configured/documented, not active in PHP call-site grep | Custom endpoint user. Preserve raw array behavior and sanitized dynamic path telemetry. |
| `the-summit-chatroom` | no | yes | no | no | Canonical stream user. Preserve typed `StreamEvent` and `StreamResult` behavior. |
| `halaxy-agents-lab` | no | no | yes | yes | Heavy custom endpoint user. Preserve raw custom JSON/SSE fields and cancellation behavior. |
| `healthkit` | no | no | yes | yes | Heavy custom endpoint user. Preserve raw custom JSON/SSE fields and long-running stream behavior. |

## Project Details

Evidence paths belong to consumer repositories. Each path starts with its repository name and is otherwise relative to that repository's root.

### `ambient-scribe`

Evidence:

- `ambient-scribe/src/Controller/ScribeController.php` (search: `postJson`) calls `postJson("/session/{$sessionId}/history", [], timeout: 10)`.
- `ambient-scribe/src/Service/RoleInferenceService.php` (search: `postJson`) calls
  `postJson("/session/{$sessionId}/roles", [], timeout: self::ROLE_SNAPSHOT_TIMEOUT)`.
- `ambient-scribe/config/packages/strands.yaml` (search: `streamSse`) documents future role streaming through `streamSse`.

Compatibility notes:

- Dynamic session IDs appear in custom endpoint paths. Telemetry must sanitize route values and expose only a session-present boolean, never the ID.
- Responses are raw arrays owned by the app. Typed `AgentResponse` changes do not cover this project unless it adopts `/invoke`.

### `the-summit-chatroom`

Evidence:

- `the-summit-chatroom/src/Service/SummitStreamOrchestrator.php` (search: `stream(`) calls standard `stream()` with `AgentContext` metadata.
- The stream callback reads typed `StreamEvent` values including `Text`, `ToolUse`, `ToolResult`, `Thinking`, and `Complete`.

Compatibility notes:

- New stream fields must be additive. Unknown or future events should remain forward-compatible.
- `AgentContext` metadata keys such as `correlation_id`, `persona`, and `active_personas` are app-owned context, not contract-wide names.

### `halaxy-agents-lab`

Evidence:

- `halaxy-agents-lab/src/Service/FileSummariserStreamOrchestrator.php` (search: `streamSse('/file-summarise-stream'`) streams events, then calls
  `postJson('/file-metadata', ...)`.
- `halaxy-agents-lab/src/Service/SuggestedActionsStreamOrchestrator.php` (search: `postJson('/suggested-actions/analyse'`) calls custom JSON analysis
  endpoints.
- `halaxy-agents-lab/src/Service/OnlineBookingStreamOrchestrator.php` (search: `streamSse`) calls custom booking responder streams.

Compatibility notes:

- Raw stream events are forwarded to Mercure and may contain app-specific fields. `streamSse()` must keep preserving unknown fields for callbacks.
- OTEL should observe only summaries for raw custom streams: route, status, counts, terminal state, cancellation, and normalized usage when present.

### `healthkit`

Evidence:

- `healthkit/src/App/ExternalProvider/AI/StrandsAgents/FileSummariserStreamOrchestrator.php` (search: `streamSse`) streams file summariser events and
  forwards sanitized app payloads.
- `healthkit/src/App/ExternalProvider/AI/ChatAssistant/ChatAssistantOrchestrator.php` (search: `postJson('/chat'`) calls custom `/chat` and `/intent`
  endpoints.
- `healthkit/src/App/ExternalProvider/AI/ChatOnlineBooking/OnlineBookingResponsePublisher.php` (search: `streamSse('/respond-stream'`) calls the
  custom booking responder stream.

Compatibility notes:

- File and chat workflows use app-owned payloads with user/practice context. Contract changes must not force those fields through typed agent DTOs.
- Telemetry must not record filenames, raw page context, practice context, patient context, or message text.

## Test Coverage Status

| Surface | Current coverage | Keep true when changing the contract |
| --- | --- | --- |
| Canonical JSON fixtures | `tests/Unit/Contract/WireContractFixtureTest.php` smoke-loads request, response, error, and discovery JSON. | Add a fixture and an assertion for each new response accessor. |
| Canonical SSE fixtures | `tests/Unit/Contract/WireContractFixtureTest.php` parses stream fixtures through `StreamParser`. | Add a consumer-shaped fixture whenever a new stream event family appears. |
| `postJson()` custom raw arrays | `tests/Unit/Contract/ConsumerCompatibilityTest.php` covers Ambient, Halaxy, and Healthkit consumer-shaped fixtures. | Keep fixture names aligned with the endpoints those projects actually call. |
| `streamSse()` custom raw arrays | `tests/Unit/Contract/ConsumerCompatibilityTest.php` covers Halaxy and Healthkit raw SSE callback profiles. | Unknown fields must keep reaching the callback untouched. |
| OTEL observer summaries | Response observer tests plus `tests/Http/Middleware/OtelTracingPhiSafetyTest.php` cover sanitized summaries and forbidden content. | Wrapper trace continuation stays a manual check against `examples/python-gateway/tracing.py`. |
