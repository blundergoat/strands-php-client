# Wire Contract Audit

This audit compares the active wrapper services that use `strands-php-client` against the Strands HTTP Wire Contract v1.

The result: the PHP client is a custom-wrapper client. The active services expose Python/FastAPI wrapper contracts, not raw sdk-python `TypedDict` shapes.

## Audited Projects

| Project | Contract usage | Evidence |
| --- | --- | --- |
| `the-summit-chatroom` | Uses standard `/invoke` and `/stream` endpoints with `message`, `session_id`, and `context`. Emits canonical SSE event types such as `text`, `thinking`, `tool_use`, `tool_result`, `complete`, and `error`. | `/home/devgoat/projects/the-summit-chatroom/strands_agents/api/server.py` (search: `InvokeRequest`, `stream_response`, `event_data`) |
| `ambient-scribe` | Uses custom endpoints through `postJson()` and wrapper-specific Pydantic schemas. Project docs explicitly treat PHP/Python request models as coupled contracts. | `/home/devgoat/projects/ambient-scribe/docs/workflow.md` (search: `contracts are tightly coupled`), `/home/devgoat/projects/ambient-scribe/src/Controller/ScribeController.php` (search: `postJson`) |
| `halaxy-agents-lab` | Uses custom file metadata endpoints and stream endpoints. Shared schemas emit snake_case `AgentUsage`; helpers normalize sdk-python usage counters before PHP receives them. | `/home/devgoat/projects/halaxy-agents-lab/strands_agents/api/schemas/shared.py` (search: `AgentUsage`), `/home/devgoat/projects/halaxy-agents-lab/strands_agents/api/helpers.py` (search: `_extract_usage`) |
| `healthkit` | Uses several custom endpoints for chat, intent, metadata, summarise, and suggested actions. Shared wrapper helpers normalize usage and stream `complete` payloads before PHP consumption. | `/home/devgoat/projects/healthkit/strands_agents/api/helpers.py` (search: `_extract_usage`), `/home/devgoat/projects/healthkit/src/App/ExternalProvider/AI/StrandsAgents/FileSummariserStreamOrchestrator.php` (search: `streamSse`) |

## Shared Signals

- The active services use wrapper-owned Pydantic models and route handlers.
- Usage is normalized into snake_case before PHP receives it.
- Standard `/invoke` requests use the PHP envelope of `message`, optional `session_id`, and optional `context`.
- Standard streams use JSON SSE frames with a `type` field.
- Domain workflows use `postJson()` and `streamSse()` for raw custom schemas instead of forcing every response through `AgentResponse`.
- URL media helpers are a wrapper concern. They are not evidence of raw sdk-python media parity.

## Current Gaps

- Wrapper helper logic is duplicated across services. `healthkit` and `halaxy-agents-lab` both normalize sdk-python usage into snake_case. This should either be extracted or covered with shared contract fixtures.
- Not every service emits every optional field in the v1 contract. That is acceptable; PHP DTO fields should remain nullable/optional.
- There is no shared Python package yet that enforces the contract. Until one exists, wrapper repos should test against the fixtures in `tests/Fixtures/wire-contract/`.

## Active Endpoint Shapes

| Project | PHP method | Endpoint examples | Payload/response notes |
| --- | --- | --- | --- |
| `ambient-scribe` | `postJson()` | `/session/{id}/history`, `/session/{id}/roles` | Empty request arrays with timeout overrides; responses are app-owned history and role-inference arrays. |
| `the-summit-chatroom` | `stream()` | standard `/stream` | Uses `message`, `session_id`, and `AgentContext` metadata; consumes typed stream events and `has_objective`. |
| `halaxy-agents-lab` | `postJson()` | `/file-metadata`, `/summary-verifier`, `/multi-file-summary-verifier`, `/suggested-actions/analyse`, `/suggested-actions/execute`, `/chat` | App-owned file, action, and booking payloads; responses may include `usage`, `metrics`, verification data, and domain IDs. |
| `halaxy-agents-lab` | `streamSse()` | `/file-summarise-stream`, `/multi-file-summarise-stream`, `/suggested-actions/stream`, `/respond-stream` | Raw SSE arrays are forwarded by app code and must preserve unknown fields. |
| `healthkit` | `postJson()` | `/chat`, `/intent`, `/file-metadata`, booking chat endpoints | App-owned patient/practice/page context; PHP must not force these into typed `AgentResponse`. |
| `healthkit` | `streamSse()` | file summariser and booking responder streams | Long-running raw SSE streams; callbacks consume domain-specific event fields. |

Executable compatibility coverage now lives in `tests/Unit/Contract/ConsumerCompatibilityTest.php`, backed by consumer-shaped fixtures in `tests/Fixtures/wire-contract/`.

## Recommendation

Keep `strands-php-client` aligned to `docs/wire-contract.md`.

Before adding PHP support for a newly observed sdk-python feature, first add or update the wrapper contract and fixtures. After the active wrappers pass those fixtures, add the PHP DTO/builder/accessor work.
