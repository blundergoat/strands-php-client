"""Provide a small FastAPI gateway that PHP applications can copy and adapt.

The routes demonstrate the Wire Contract v1 boundary around a fake agent, including invoke, streaming, discovery, and one custom endpoint.
Replace the fake response source with your Strands agent while keeping the request and response shapes stable for the PHP caller.
"""

from __future__ import annotations

from collections.abc import AsyncIterator, Mapping
from typing import Any

from fastapi import FastAPI, Request
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field

from contract import (
    build_invoke_response,
    discovery_response,
    fake_stream_events,
    map_sdk_event,
    sse_frame,
)
from tracing import TraceContextMiddleware


class InvokeRequest(BaseModel):
    """Validate the standard message sent by `invoke()` or `stream()`.

    A missing session ID starts a new conversation, while an empty context means the user supplied no extra instructions or app metadata.
    Rich message dictionaries carry attachments or structured-output controls assembled by `AgentInput`.
    """

    message: str | dict[str, Any]
    session_id: str | None = None
    context: dict[str, Any] = Field(default_factory=dict)


class CustomAnalysisRequest(BaseModel):
    """Validate the app-owned payload used by the custom analysis example.

    The user's text is summarized, while metadata is reduced to safe key/count information rather than echoed into logs or telemetry.
    Copy this model when a screen needs a route whose schema does not match the standard agent invoke contract.
    """

    text: str
    metadata: dict[str, Any] = Field(default_factory=dict)


app = FastAPI(title="Strands Reference Gateway")
app.add_middleware(TraceContextMiddleware)


@app.get("/health")
async def health() -> dict[str, Any]:
    """Tell a PHP client which Wire Contract version and optional features this gateway supports.
    Use this discovery route before enabling optional controls in an app screen."""
    return discovery_response()


@app.post("/invoke")
async def invoke(request: InvokeRequest) -> dict[str, Any]:
    """Return one complete answer for a blocking PHP invoke() call, preserving any conversation ID.
    Use this route when the app waits for a final result instead of rendering live updates."""
    prompt = _message_to_text(request.message)
    text = f"Echo: {prompt}"

    return build_invoke_response(
        text=text,
        session_id=request.session_id,
        usage={"inputTokens": 12, "outputTokens": 4, "totalTokens": 16},
        model="fake-model",
        message={
            "role": "assistant",
            "content": [{"type": "text", "text": text}],
            "metadata": {
                "usage": {"input_tokens": 12, "output_tokens": 4},
                "metrics": {"latency_ms": 1.0},
                "custom": {"gateway": "reference"},
            },
        },
        context_size=128,
        projected_context_size=160,
    )


@app.post("/stream")
async def stream(request: InvokeRequest) -> StreamingResponse:
    """Stream normalized SSE updates for a PHP stream() call and finish with a trusted terminal event.
    Use this route for live answer screens that append text or activity as it arrives."""
    prompt = _message_to_text(request.message)

    async def stream_frames() -> AsyncIterator[str]:
        """Yield framed events as the fake or real agent produces user-visible updates.
        Use this generator as the FastAPI response body; no yielded frame means the UI receives no update."""
        # Each callback represents one thinking, text, or terminal update that the PHP stream can surface to the user.
        for sdk_event in fake_stream_events(f"Echo: {prompt}", session_id=request.session_id):
            normalized_event = map_sdk_event(sdk_event, fallback_session_id=request.session_id)
            # Lifecycle callbacks intentionally map to None, so only meaningful UI updates become SSE frames.
            if normalized_event is not None:
                yield sse_frame(normalized_event)

    return StreamingResponse(stream_frames(), media_type="text/event-stream")


@app.post("/custom/analyse")
async def custom_analyse(request: CustomAnalysisRequest) -> dict[str, Any]:
    """Return the custom JSON shape consumed by an app-owned postJson() screen.
    Use this route when the UI schema deliberately differs from the standard agent response."""
    return {
        "analysis_id": "analysis-001",
        "summary": request.text[:120],
        "metadata": _safe_metadata_summary(request.metadata),
        "usage": {
            "input_tokens": max(1, len(request.text.split())),
            "output_tokens": 12,
        },
    }


def _message_to_text(message: str | Mapping[str, Any]) -> str:
    """Extract displayable prompt text from a plain chat message or rich content blocks.
    Use it before calling the example agent; an empty result means the rich input contained no non-empty text block."""
    # A plain chat box sends its text directly, so no attachment traversal is needed.
    if isinstance(message, str):
        return message

    content = message.get("content", [])
    # Rich input may include images or documents; concatenate only text blocks into the fake agent's visible prompt.
    if isinstance(content, list):
        prompt_text_parts = []
        # Each content block represents text or an attachment the user added to the turn.
        for content_block in content:
            # The fake agent can echo only non-empty text; real agents would also receive the attachment blocks.
            if isinstance(content_block, Mapping) and content_block.get("type") == "text":
                prompt_text = str(content_block.get("text", ""))
                # A blank text block adds nothing to the prompt shown in this example UI.
                if prompt_text != "":
                    prompt_text_parts.append(prompt_text)

        return " ".join(prompt_text_parts).strip()

    return ""


def _safe_metadata_summary(metadata: Mapping[str, Any]) -> dict[str, Any]:
    """Return only metadata names and a count, never the user-supplied values.
    Use it for confirmation screens or telemetry; an empty mapping produces an empty key list and a zero count."""
    # Each key tells the app what arrived without copying any potentially sensitive value.
    return {
        "keys": sorted(str(key) for key in metadata.keys()),
        "count": len(metadata),
    }
