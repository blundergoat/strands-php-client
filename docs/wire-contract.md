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
| `image` | `format`, `source` | Source may be `base64`, `s3`, or wrapper-supported `url`. |
| `document` | `name`, `format`, `source` | Source may be `base64`, `s3`, or wrapper-supported `url`. |
| `video` | `format`, `source` | Source may be `base64`, `s3`, or wrapper-supported `url`. |

Source objects:

```json
{
    "type": "s3",
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

## Invoke Response

Successful `/invoke` responses use an agent envelope.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `text` | `string` | yes | Final response text. |
| `agent` | `string` | no | Agent name or identifier. |
| `session_id` | `string` | no | Session identifier. |
| `usage` | `object` | no | Token/timing usage in snake_case. |
| `tools_used` | `array<object>` | no | Tool call summaries. |
| `has_objective` | `bool` | no | Wrapper-specific objective flag. |
| `stop_reason` | `string` | no | One of the stop reasons recognized by `StopReason`. |
| `message` | `object` | no | Wrapper-normalized raw message content, used for citations and future nested metadata. |
| `guardrail_trace` | `object` | no | Wrapper-normalized guardrail trace. |
| `interrupts` | `array<object>` | no | Interrupt requests requiring caller input. |

Usage fields:

| Field | Type | Notes |
| --- | --- | --- |
| `input_tokens` | `int` | Prompt/input tokens. |
| `output_tokens` | `int` | Generated/output tokens. |
| `cache_read_input_tokens` | `int` | Cached input tokens read. |
| `cache_write_input_tokens` | `int` | Cached input tokens written. |
| `latency_ms` | `float` | Total response latency, when emitted by the wrapper. |
| `time_to_first_byte_ms` | `float` | First-token or first-byte latency, when emitted by the wrapper. |

The wrapper should normalize sdk-python `inputTokens`/`outputTokens` style counters into these snake_case fields before returning JSON to PHP.

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
| `complete` | `text` | Final stream response. May include `usage`, `tools_used`, `stop_reason`, `guardrail_trace`, and `interrupts`. |
| `error` | `message` | Stream error. May include `code`. |

Example:

```text
data: {"type":"text","content":"Hello"}

data: {"type":"text","content":" world"}

data: {"type":"complete","text":"Hello world","usage":{"input_tokens":12,"output_tokens":2}}

```

Unknown event types may be ignored by older PHP clients.

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
