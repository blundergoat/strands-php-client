# Strands HTTP Wire Contract

This package targets the Strands HTTP Wire Contract v1: a small JSON/SSE contract emitted by HTTP wrapper services built on top of `strands-agents/sdk-python`.

It does not target raw sdk-python `TypedDict` shapes directly. The Python wrapper is responsible for translating sdk-python requests, results, and stream events into the stable PHP-facing wire shape described here.

Canonical examples live in [tests/Fixtures/wire-contract](../tests/Fixtures/wire-contract). When this contract changes, update those fixtures and the architecture decision that owns the contract.

## Contract Principles

- Standard agent endpoints are `POST /invoke` and `POST /stream`.
- Custom endpoints are app-owned and use `postJson()` or `streamSse()` directly.
- JSON field names in the PHP-facing contract use `snake_case`.
- The wrapper may accept PHP convenience blocks, such as URL media sources, even when sdk-python does not expose the same raw content-block shape.
- Unknown top-level response fields are forward-compatible metadata and should not break clients.
- sdk-python remains the implementation substrate, but the wrapper contract is the compatibility boundary.

## What Is Not In The Contract

- Raw sdk-python camelCase payloads are not PHP-facing v1 contract shapes.
- Provider-specific cache block variants are translated by the wrapper into the canonical `cache_point` block below.
- Prompt text, response text, raw document content, raw tool input/output, and raw context metadata are not valid telemetry attributes.
- Checkpoint, snapshot, and stream resume fields are not part of v1 until a wrapper adopts explicit shapes for them.
- A2A task lifecycle messages are not part of the standard `/invoke` and `/stream` contract.

## Fixture Layout

Canonical v1 fixtures live under `tests/Fixtures/wire-contract/`. Older fixtures under `tests/Fixtures/` are legacy compatibility fixtures and should remain covered when they represent deployed wrapper shapes that differ from the canonical examples.

## Request Envelope

Standard `/invoke` and `/stream` endpoints receive the same envelope.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `message` | `string` or `object` | yes | Plain prompt text, or an `AgentInput` rich message object. |
| `session_id` | `string` | no | Conversation/session identifier. |
| `context` | `object` | no | Application context passed through to the wrapper. |

Plain text request:

```json
{
    "message": "Summarise the latest note.",
    "session_id": "session-001",
    "context": {
        "metadata": {
            "persona": "analyst",
            "correlation_id": "corr-001"
        }
    }
}
```

Rich request:

```json
{
    "message": {
        "content": [
            {
                "type": "text",
                "text": "Summarise this document."
            },
            {
                "type": "document",
                "name": "referral.pdf",
                "format": "pdf",
                "source": {
                    "type": "base64",
                    "media_type": "application/pdf",
                    "data": "JVBERi0xLjQK..."
                }
            }
        ],
        "structured_output_prompt": "Return JSON with summary and risks."
    },
    "session_id": "session-002"
}
```

## Rich Content Blocks

The PHP `AgentInput` builder emits wrapper-contract content blocks. The wrapper then translates them into whatever sdk-python, model provider, or retrieval layer needs.

| Block | Required fields | Notes |
| --- | --- | --- |
| `text` | `text` | Plain text content. |
| `image` | `format`, `source` | Source may be `base64`, `s3_location`, or wrapper-supported `url`. |
| `document` | `name`, `format`, `source` | Source may be `base64`, `s3_location`, or wrapper-supported `url`. |
| `video` | `format`, `source` | Source may be `base64`, `s3_location`, or wrapper-supported `url`. |
| `cache_point` | `cache_type` | Optional `ttl`, for example `5m` or `1h`. The wrapper translates this into provider/sdk-python cache controls. |

Source objects:

```json
{
    "type": "s3_location",
    "uri": "s3://bucket/key.pdf"
}
```

```json
{
    "type": "url",
    "url": "https://example.com/file.pdf",
    "media_type": "application/pdf"
}
```

URL sources are a wrapper extension. A wrapper that accepts URL blocks must fetch, validate, and translate the resource before invoking sdk-python or the model provider.

Cache point block:

```json
{
    "type": "cache_point",
    "cache_type": "default",
    "ttl": "5m"
}
```

Document blocks may include optional wrapper-owned context and citation controls:

```json
{
    "type": "document",
    "name": "referral.pdf",
    "format": "pdf",
    "context": "Referral letter uploaded for this patient summary.",
    "citations": {
        "enabled": true
    },
    "source": {
        "type": "base64",
        "media_type": "application/pdf",
        "data": "JVBERi0xLjQK..."
    }
}
```

The wrapper decides how `context` and `citations` map into sdk-python/model-provider inputs.

## Invoke Response

Successful `/invoke` responses use an agent envelope.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `text` | `string` | yes | Final response text. |
| `agent` | `string` | no | Agent name or identifier. |
| `session_id` | `string` | no | Session identifier. |
| `model` | `string` | no | Model identifier, when the wrapper exposes it. |
| `usage` | `object` | no | Token/timing usage in snake_case. |
| `tools_used` | `array<object>` | no | Tool call summaries. Each item is `{name: string, duration_ms?: int, input?: object, result?: object}`. `input` and `result` must be safe summaries, not raw tool payloads. |
| `has_objective` | `bool` | no | Wrapper-specific objective flag. |
| `stop_reason` | `string` | no | Common values are `end_turn`, `tool_use`, `max_tokens`, `stop_sequence`, `content_filtered`, `interrupt`, `error`, `guardrail_intervened`, `cancelled`, and `checkpoint`. Wrappers may forward additive sdk-python values such as `limit_output_tokens`, `limit_total_tokens`, or `limit_turns`. |
| `structured_output` | `object` | no | Schema-validated structured result for invoke responses. |
| `message` | `object` | no | Wrapper-normalized raw message content, used for citations and future nested metadata. |
| `guardrail_trace` | `object` | no | Wrapper-normalized guardrail trace. |
| `interrupts` | `array<object>` | no | Interrupt requests requiring caller input. |

Usage fields:

| Field | Type | Notes |
| --- | --- | --- |
| `input_tokens` | `int` | Prompt/input tokens. |
| `output_tokens` | `int` | Generated/output tokens. |
| `total_tokens` | `int` | Total tokens, when emitted by the wrapper. PHP can also compute this from input + output. |
| `cache_read_input_tokens` | `int` | Cached input tokens read. |
| `cache_write_input_tokens` | `int` | Cached input tokens written. |
| `latency_ms` | `number` | Total response latency, when emitted by the wrapper. The PHP 1.x DTO rounds fractional values to an integer millisecond. |
| `time_to_first_byte_ms` | `number` | First-token or first-byte latency, when emitted by the wrapper. The PHP 1.x DTO rounds fractional values to an integer millisecond. |

The wrapper should normalize sdk-python `inputTokens`/`outputTokens` style counters into these snake_case fields before returning JSON to PHP.

## Optional Response Fields

These fields are additive in v1 of the wrapper contract. Older PHP clients may ignore them; newer clients may expose typed accessors.

| Field | Type | Location | Notes |
| --- | --- | --- | --- |
| `message.metadata.usage` | `object` | `message.metadata` | Per-message usage normalized to the same snake_case shape as top-level `usage`. |
| `message.metadata.metrics` | `object` | `message.metadata` | Wrapper-normalized metrics from sdk-python or provider integrations. |
| `message.metadata.custom` | `object` | `message.metadata` | Application-specific message metadata. |
| `context_size` | `int` | top-level response or stream `complete` | Current context size in tokens, when known. Invoke responses expose `$contextSize` canonically and retain deprecated `$metadata['context_size']` throughout 1.x. |
| `projected_context_size` | `int` | top-level response or stream `complete` | Projected next-turn context size in tokens, when known. Invoke responses expose `$projectedContextSize` canonically and retain deprecated `$metadata['projected_context_size']` throughout 1.x. |
| `metadata` | `object` | top-level response | Wrapper-owned metadata. PHP exposes it canonically as `$wrapperMetadata`; deprecated `$metadata['metadata']` remains throughout 1.x. |

Checkpoint, snapshot, and stream resume fields are intentionally not part of this section until a wrapper adopts explicit shapes for them.

## Observability

Wrappers should accept W3C `traceparent` and `tracestate` headers and continue the trace into Python wrapper and sdk-python spans when tracing is enabled.

Span attributes must not contain prompt text, response text, raw document content, filenames, raw context metadata, raw tool input/output, citation source text, credentials, or session ID values. Use booleans, counts, safe names, sanitized error type/code/status, and token usage values instead.

## Discovery

Wrappers may expose `/health` or `/discover` style endpoints for templates and tooling. A discovery response is optional and must not be required for existing `invoke()`, `stream()`, `postJson()`, or `streamSse()` calls.

```json
{
    "name": "strands-wrapper",
    "wire_version": "1",
    "endpoints": {
        "invoke": "/invoke",
        "stream": "/stream",
        "health": "/health"
    },
    "features": {
        "rich_input": true,
        "streaming": true,
        "message_metadata": true,
        "context_size": true,
        "cache_points": true,
        "document_context": true,
        "document_citations": true,
        "stream_resume": false,
        "a2a": false
    }
}
```

## Citations

Citation data is currently a flattened wrapper contract, not the raw sdk-python citation tagged union. Existing PHP DTOs model this flattened shape.

```json
{
    "type": "citationsContent",
    "location": {
        "type": "DOCUMENT",
        "start_character_index": 0,
        "end_character_index": 25,
        "start_page_index": 1,
        "end_page_index": 1
    },
    "source_content": {
        "type": "TEXT",
        "text": "Paris is the capital city of France.",
        "document_name": "geography.pdf"
    },
    "generated_content": {
        "type": "TEXT",
        "text": "Paris"
    }
}
```

## Guardrails

Guardrail traces are also wrapper-normalized. They are not raw sdk-python guardrail trace objects.

```json
{
    "action": "INTERVENED",
    "assessments": [
        {
            "name": "safety",
            "result": "blocked",
            "confidence": 0.98
        }
    ],
    "model_output": "Blocked by safety policy."
}
```

## Interrupts

Interrupt responses indicate that the agent needs caller input before continuing.

```json
{
    "stop_reason": "interrupt",
    "interrupts": [
        {
            "interrupt_id": "interrupt-001",
            "tool_use_id": "tool-001",
            "tool_name": "book_appointment",
            "tool_input": {
                "date": "2026-05-24"
            },
            "reason": "approval_required"
        }
    ]
}
```

## Stream SSE

`/stream` uses server-sent events where each event payload is a JSON object in a `data:` frame. The PHP parser uses the JSON `type` field.

| Event type | Required fields | Notes |
| --- | --- | --- |
| `text` | `content` | Incremental text chunk. |
| `thinking` | `content` | Model reasoning/thinking chunk when emitted. |
| `tool_use` | `tool_name`, `tool_input` | Tool call start or summary. |
| `tool_result` | `result` | Tool result payload. |
| `citation` | `citation` | Wrapper-normalized flattened citation object. |
| `reasoning_signature` | `signature` | Reasoning verification signature. |
| `reasoning_redacted` | none | Indicates that reasoning content was redacted. |
| `complete` | `text` | Final stream response. May include `usage`, `tools_used`, `stop_reason`, `guardrail_trace`, `interrupts`, and the optional response fields listed above. |
| `error` | `message` | Stream error. May include `code`. |

Example:

```text
data: {"type":"text","content":"Hello"}

data: {"type":"text","content":" world"}

data: {"type":"complete","text":"Hello world","usage":{"input_tokens":12,"output_tokens":2}}

```

Unknown event types may be ignored by older PHP clients.

The seven 1.x `StopReason` cases (`end_turn`, `tool_use`, `max_tokens`, `stop_sequence`, `content_filtered`, `guardrail_intervened`, and `interrupt`)
hydrate the PHP enum. Invoke and stream results also preserve the exact string in `rawStopReason`, so `error`, `cancelled`, `checkpoint`, and additive
sdk-python values remain observable without expanding an enum consumers may match exhaustively. The enum can expand in 2.0.

## Error Responses

HTTP error responses should prefer a JSON body with a human-readable message plus optional machine-readable code and details.

```json
{
    "message": "Validation failed.",
    "code": "validation_error",
    "detail": {
        "field": "message"
    }
}
```

Wrappers should preserve the HTTP status code and include structured error details when available. PHP exceptions keep the decoded response body for callers that need wrapper-specific diagnostics.
