# Glossary — strands-php-client

Domain and project-specific terms a new contributor needs.

## Strands / Agents

- **Strands agent** — A Python-side autonomous agent exposed over HTTP, built with the [strands-agents/sdk-python](https://github.com/strands-agents/strands-agents) framework. This PHP library only **consumes** them; it does not run the agentic loop.
- **Agentic loop** — Server-side reasoning loop where the agent plans, calls tools, and iterates until it returns a terminal response. Lives in Python. The PHP client is intentionally loop-free.
- **Strands HTTP Wire Contract v1** — The stable JSON / SSE contract between PHP and the Python wrapper service. Defined in `docs/wire-contract.md` and `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md`. PHP-facing fields are `snake_case`; not the raw sdk-python `TypedDict` shapes.
- **Session** — Server-managed conversation history keyed by `sessionId`. The client passes the ID through; it does not persist history locally.
- **Interrupt / human-in-the-loop** — The agent pauses and asks for caller approval before continuing (e.g., before executing a sensitive tool). Surfaced as `InterruptDetail[]`; resumed via `AgentInput::interruptResponse($id, $payload)`.
- **Guardrail trace** — Content-safety intervention metadata (e.g., AWS Bedrock Guardrails). Returned on the `AgentResponse` as `GuardrailTrace`; `action` is typically `INTERVENED` or `NONE`.

## Transport / Streaming

- **`HttpTransport`** — Project's transport interface (`src/Http/HttpTransport.php`). It is a **PHP `interface`** — it cannot ship default method bodies. New transports implement both `post()` and `stream()`.
- **`SymfonyHttpTransport`** vs **`PsrHttpTransport`** — The two shipped transports. Symfony supports real streaming; PSR-18 does not (spec limitation, not a project bug). Auto-detected when no transport is passed.
- **SSE (Server-Sent Events)** — The streaming protocol used by Strands agent `/stream` endpoints. Events are delimited by double newlines (`\n\n`) with `data:` prefixed JSON payloads.
- **`StreamParser`** — Incremental SSE parser. Buffers raw HTTP chunks up to a 10 MB cap; emits typed `StreamEvent` objects; tolerates unknown event types via `tryFromArray()`; counts (does not throw on) malformed JSON.
- **`StreamEvent`** — One typed event. Types: `Text`, `Thinking`, `ToolUse`, `ToolResult`, `Citation`, `Complete`, `Error`, `ReasoningSignature`. Terminal: `Complete` and `Error`. `::fromArray()` throws on unknown types; `::tryFromArray()` returns `null`.
- **`StreamResult`** — Accumulated streaming session result: text, sessionId, usage, tools, TTFT, interrupts, guardrail trace, citations.
- **TTFT** — Time to First Text token. Client-measured in `StreamResult.timeToFirstTextTokenMs` for latency observability.

## Auth / Middleware

- **`AuthStrategy`** — Interface for pluggable authentication (`src/Auth/AuthStrategy.php`). Implementations: `NullAuth`, `ApiKeyAuth`, `SigV4Auth`.
- **`SigV4Auth`** — Standalone AWS Signature V4 implementation for IAM-authenticated agent endpoints. Does **not** depend on `aws/aws-sdk-php`. `::fromEnvironment(region:)` reads `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`.
- **`RequestMiddleware`** — Pre-/post-request hook interface. `beforeRequest()` runs in registration order **before** auth (so SigV4 signatures cover post-middleware bodies). `afterResponse()` runs after completion; exceptions are swallowed by design (observers must not break the call).

## DTOs / Builders

- **`AgentResponse`** — Typed DTO returned by `invoke()`. Hydrated from JSON via the defensive `::fromArray()` factory.
- **`AgentInput`** — Immutable builder for rich request payloads: text, images (base64/URL), documents (base64/S3), interrupt-response. Clone-and-mutate.
- **`AgentContext`** — Immutable builder for request context: system prompts, metadata, permissions, documents, structured data. Clone-and-mutate.
- **`Usage`** — Token usage stats. `::fromArray()` is the **single canonical hydrator** — `AgentResponse::parseUsage()` and `StrandsClient::usageFromArray()` delegate to it.
- **`StopReason`** — Backed enum for why an agent stopped: `EndTurn`, `ToolUse`, `MaxTokens`, `StopSequence`, `GuardrailIntervened`, `Interrupted`.

## Quality / Tooling

- **PHPStan Level 10** — Strictest available level; no implicit `mixed`, no untyped arrays without `array<K, V>` annotations. Non-negotiable for this project.
- **MSI** — Mutation Score Indicator (Infection). Target ≥ 90% per `infection.json5`.
- **Preflight** — `composer preflight` runs every quality gate (`test`, `analyse`, `cs:check`, `analyse:messdetector`, `analyse:complexity`). The pre-commit gate.
- **Cyclomatic complexity ≤ 20** — Hard limit per method, enforced by `scripts/check-cyclomatic-complexity.php`.

## Frameworks

- **`StrandsClientFactory`** — Shared factory (`src/Integration/StrandsClientFactory.php`) used by both Laravel and Symfony integrations. Symfony subclass adds DI-specific resolution.
- **Service ID `strands.client.<name>`** — Symfony DI naming convention for named agents (e.g., `strands.client.analyst`).
- **`Strands` facade** — Laravel facade pointing at the default `StrandsClient` binding. Backed by `StrandsServiceProvider`.
