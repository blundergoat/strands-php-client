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
    message: str | dict[str, Any]
    session_id: str | None = None
    context: dict[str, Any] = Field(default_factory=dict)


class CustomAnalysisRequest(BaseModel):
    text: str
    metadata: dict[str, Any] = Field(default_factory=dict)


app = FastAPI(title="Strands Reference Gateway")
app.add_middleware(TraceContextMiddleware)


@app.get("/health")
async def health() -> dict[str, Any]:
    return discovery_response()


@app.post("/invoke")
async def invoke(request: InvokeRequest) -> dict[str, Any]:
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
    prompt = _message_to_text(request.message)

    async def body() -> AsyncIterator[str]:
        for event in fake_stream_events(f"Echo: {prompt}", session_id=request.session_id):
            yield sse_frame(map_sdk_event(event))

    return StreamingResponse(body(), media_type="text/event-stream")


@app.post("/custom/analyse")
async def custom_analyse(request: CustomAnalysisRequest) -> dict[str, Any]:
    """Blueprint for app-owned custom routes used with StrandsClient::postJson()."""
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
    if isinstance(message, str):
        return message

    content = message.get("content", [])
    if isinstance(content, list):
        parts = [
            str(block.get("text", ""))
            for block in content
            if isinstance(block, Mapping) and block.get("type") == "text"
        ]

        return " ".join(part for part in parts if part).strip()

    return ""


def _safe_metadata_summary(metadata: Mapping[str, Any]) -> dict[str, Any]:
    return {
        "keys": sorted(str(key) for key in metadata.keys()),
        "count": len(metadata),
    }
