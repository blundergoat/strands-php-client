# Wire Contract Consumer Matrix

This matrix records how active projects use `strands-php-client` today. It is a compatibility input for the Strands HTTP Wire Contract v1, not a request to migrate projects onto raw sdk-python shapes.

## Summary

| Project | Standard `invoke()` | Standard `stream()` | `postJson()` custom endpoints | `streamSse()` custom endpoints | Compatibility status |
| --- | --- | --- | --- | --- | --- |
| `ambient-scribe` | no | no | yes | configured/documented, not active in PHP call-site grep | Custom endpoint user. Preserve raw array behavior and sanitized dynamic path telemetry. |
| `the-summit-chatroom` | no | yes | no | no | Canonical stream user. Preserve typed `StreamEvent` and `StreamResult` behavior. |
| `halaxy-agents-lab` | no | no | yes | yes | Heavy custom endpoint user. Preserve raw custom JSON/SSE fields and cancellation behavior. |
| `healthkit` | no | no | yes | yes | Heavy custom endpoint user. Preserve raw custom JSON/SSE fields and long-running stream behavior. |

## Project Details

### `ambient-scribe`

Evidence:

- `/home/devgoat/projects/ambient-scribe/src/Controller/ScribeController.php` (search: `postJson`) calls `postJson("/session/{$sessionId}/history", [], timeout: 10)`.
- `/home/devgoat/projects/ambient-scribe/src/Service/RoleInferenceService.php` (search: `postJson`) calls `postJson("/session/{$sessionId}/roles", [], timeout: self::ROLE_SNAPSHOT_TIMEOUT)`.
- `/home/devgoat/projects/ambient-scribe/config/packages/strands.yaml` (search: `streamSse`) documents future role streaming through `streamSse`.

Compatibility notes:

- Dynamic session IDs appear in custom endpoint paths. Telemetry must sanitize route values and expose only a session-present boolean, never the ID value.
- Responses are raw arrays owned by the app. Typed `AgentResponse` changes do not cover this project unless it adopts `/invoke`.

### `the-summit-chatroom`

Evidence:

- `/home/devgoat/projects/the-summit-chatroom/src/Service/SummitStreamOrchestrator.php` (search: `stream(`) calls standard `stream()` with `AgentContext` metadata.
- The stream callback reads typed `StreamEvent` values including `Text`, `ToolUse`, `ToolResult`, `Thinking`, and `Complete`.

Compatibility notes:

- New stream fields must be additive. Unknown or future events should remain forward-compatible.
- `AgentContext` metadata keys such as `correlation_id`, `persona`, and `active_personas` are app-owned context, not contract-wide names.

### `halaxy-agents-lab`

Evidence:

- `/home/devgoat/projects/halaxy-agents-lab/src/Service/FileSummariserStreamOrchestrator.php` (search: `streamSse('/file-summarise-stream'`) streams raw file summariser events and later calls `postJson('/file-metadata', ...)`.
- `/home/devgoat/projects/halaxy-agents-lab/src/Service/SuggestedActionsStreamOrchestrator.php` (search: `postJson('/suggested-actions/analyse'`) calls custom JSON analysis endpoints.
- `/home/devgoat/projects/halaxy-agents-lab/src/Service/OnlineBookingStreamOrchestrator.php` (search: `streamSse`) calls custom booking responder streams.

Compatibility notes:

- Raw stream events are forwarded to Mercure and may contain app-specific fields. `streamSse()` must keep preserving unknown fields for callbacks.
- OTEL should observe only summaries for raw custom streams: route, status, counts, terminal state, cancellation, and normalized usage when present.

### `healthkit`

Evidence:

- `/home/devgoat/projects/healthkit/src/App/ExternalProvider/AI/StrandsAgents/FileSummariserStreamOrchestrator.php` (search: `streamSse`) streams file summariser events and forwards sanitized app payloads.
- `/home/devgoat/projects/healthkit/src/App/ExternalProvider/AI/ChatAssistant/ChatAssistantOrchestrator.php` (search: `postJson('/chat'`) calls custom `/chat` and `/intent` endpoints.
- `/home/devgoat/projects/healthkit/src/App/ExternalProvider/AI/ChatOnlineBooking/OnlineBookingChatOrchestrator.php` (search: `streamSse`) calls custom booking responder streams.

Compatibility notes:

- File and chat workflows use app-owned payloads with user/practice context. Contract changes must not force those fields through typed agent DTOs.
- Telemetry must not record filenames, raw page context, practice context, patient context, or message text.

## Test Coverage Status

| Surface | Current coverage | Follow-up owner |
| --- | --- | --- |
| Canonical JSON fixtures | `tests/Unit/Contract/WireContractFixtureTest.php` smoke-loads request/response/error/discovery JSON. | M09 expands assertions for new M08 accessors. |
| Canonical SSE fixtures | `tests/Unit/Contract/WireContractFixtureTest.php` parses stream fixtures through `StreamParser`. | M09 adds consumer-shaped stream fixtures. |
| `postJson()` custom raw arrays | `tests/Unit/Contract/ConsumerCompatibilityTest.php` covers Ambient, Halaxy, and Healthkit consumer-shaped fixtures. | Keep fixture names aligned with active endpoint examples. |
| `streamSse()` custom raw arrays | `tests/Unit/Contract/ConsumerCompatibilityTest.php` covers Halaxy and Healthkit raw SSE callback profiles. | Add more app fixtures when new custom stream event families appear. |
| OTEL observer summaries | M05 response observer tests and `OtelTracingPhiSafetyTest` cover sanitized summaries and forbidden content. | Manual wrapper trace continuation remains an M11 gate. |
