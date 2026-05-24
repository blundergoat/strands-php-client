from __future__ import annotations

from collections.abc import Mapping
from typing import Any

try:
    from opentelemetry import propagate, trace
    from opentelemetry.trace import SpanKind
except ImportError:  # pragma: no cover - optional example dependency
    propagate = None
    trace = None
    SpanKind = None


def extract_trace_context(headers: Mapping[str, str]) -> Any | None:
    """Extract W3C trace context from FastAPI/ASGI headers."""
    if propagate is None:
        return None

    carrier = {key.lower(): value for key, value in headers.items()}

    return propagate.extract(carrier)


def parse_traceparent(header: str) -> dict[str, str] | None:
    """Parse W3C traceparent enough for dependency-free smoke tests."""
    parts = header.split("-")
    if len(parts) != 4:
        return None

    version, trace_id, span_id, trace_flags = parts
    if (
        len(version) != 2
        or len(trace_id) != 32
        or len(span_id) != 16
        or len(trace_flags) != 2
        or not all(_is_lower_hex(part) for part in parts)
        or trace_id == "0" * 32
        or span_id == "0" * 16
    ):
        return None

    return {
        "version": version,
        "trace_id": trace_id,
        "span_id": span_id,
        "trace_flags": trace_flags,
    }


class TraceContextMiddleware:
    """Small ASGI middleware that continues PHP traceparent/tracestate."""

    def __init__(self, app: Any, tracer_name: str = "strands-python-gateway") -> None:
        self.app = app
        self.tracer_name = tracer_name

    async def __call__(self, scope: dict[str, Any], receive: Any, send: Any) -> None:
        if scope.get("type") != "http" or trace is None or SpanKind is None:
            await self.app(scope, receive, send)

            return

        headers = {
            key.decode("latin-1").lower(): value.decode("latin-1")
            for key, value in scope.get("headers", [])
        }
        parent_context = extract_trace_context(headers)
        tracer = trace.get_tracer(self.tracer_name)

        with tracer.start_as_current_span(
            "strands.wrapper.http",
            context=parent_context,
            kind=SpanKind.SERVER,
        ) as span:
            span.set_attribute("gen_ai.system", "strands")
            span.set_attribute("strands.wire.version", "1")
            span.set_attribute("http.request.method", str(scope.get("method", "")))
            span.set_attribute("url.path", str(scope.get("path", "")))
            await self.app(scope, receive, send)


def _is_lower_hex(value: str) -> bool:
    return all(char in "0123456789abcdef" for char in value)
