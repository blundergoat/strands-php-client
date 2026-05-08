# Glossary

- **Strands Agent** — A remote AI agent hosted behind an HTTP endpoint, built with the [strands-agents/sdk-python](https://github.com/strands-agents/strands-agents) framework. This client library consumes agents; it does not create them.
- **SSE (Server-Sent Events)** — The streaming protocol used by Strands agent `/stream` endpoints. Events are delimited by double newlines (`\n\n`) with `data:` prefixed JSON payloads.
- **HttpTransport** — The PHP interface (`src/Http/HttpTransport.php`) abstracting HTTP communication. Two implementations: `SymfonyHttpTransport` (full) and `PsrHttpTransport` (invoke only).
- **StreamParser** — Incremental SSE parser that buffers raw HTTP chunks and emits typed `StreamEvent` objects. Has a 10 MB buffer limit to prevent unbounded memory growth.
- **StreamEvent** — A single typed event from an SSE stream. Types: Text, Thinking, ToolUse, ToolResult, Citation, Complete, Error, ReasoningSignature. Terminal events: Complete and Error.
- **StreamResult** — Accumulated result from a streaming session: full text, session ID, usage, tools used, TTFT (time to first text token), interrupts, guardrail trace, citations.
- **AgentResponse** — Typed DTO returned by `invoke()`. Hydrated from JSON via `fromArray()` factory.
- **AgentInput** — Immutable builder for rich request payloads: text, images (base64/URL), documents (base64/S3), S3 video references. Uses clone-and-mutate pattern.
- **AgentContext** — Immutable builder for request context: system prompts, metadata, permissions, documents, structured data.
- **AuthStrategy** — Interface for pluggable authentication. Implementations: `NullAuth`, `ApiKeyAuth`, `SigV4Auth`.
- **SigV4Auth** — Standalone AWS Signature V4 implementation for IAM-authenticated agent endpoints. Does not depend on `aws/aws-sdk-php`.
- **RequestMiddleware** — Interface for pre-/post-request hooks. `beforeRequest()` runs before auth; `afterResponse()` runs after completion (exceptions swallowed).
- **StrandsClientFactory** — Shared factory used by both Laravel and Symfony integrations to construct `StrandsClient` instances from framework config.
- **preflight** — The `composer preflight` command that runs all quality gates: PHPUnit, PHPStan, PHP-CS-Fixer, PHPMD, cyclomatic complexity, and optionally Infection.
- **StopReason** — Backed enum for why an agent stopped: `EndTurn`, `ToolUse`, `MaxTokens`, `StopSequence`, `GuardrailIntervened`, `Interrupted`.
- **TTFT** — Time to First Token. Measured client-side in `StreamResult` as the elapsed time from request start to the first Text event.
