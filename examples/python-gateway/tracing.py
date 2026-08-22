"""Continue PHP trace context through the optional Python gateway.

Use this module when a copied ASGI wrapper exports OpenTelemetry spans for an app request.
It keeps trace labels stable without recording prompts, answers, or concrete route values.
"""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

try:
    from opentelemetry import propagate, trace
    from opentelemetry.trace import SpanKind
except ImportError:  # pragma: no cover - optional example dependency
    # For example, a developer may copy the gateway without OpenTelemetry extras; requests still work, but tracing stays disabled.
    propagate = None
    trace = None
    SpanKind = None


def extract_trace_context(headers: Mapping[str, str]) -> Any | None:
    """Continue the app's W3C trace when OpenTelemetry is installed.
    Use it before the wrapper span starts; None means tracing is unavailable, so the request starts without a parent."""
    # A gateway without the optional tracing package must still serve the user's request.
    if propagate is None:
        return None

    carrier = {key.lower(): value for key, value in headers.items()}

    return propagate.extract(carrier)


def parse_traceparent(header: str) -> dict[str, str] | None:
    """Parse enough W3C traceparent data for dependency-free gateway checks.
    Use it in smoke tests; None means the app sent an empty or malformed trace header that cannot be continued."""
    parts = header.split("-")
    # A valid traceparent has version, trace ID, span ID, and flags; any missing part means there is no safe parent trace.
    if len(parts) != 4:
        return None

    version, trace_id, span_id, trace_flags = parts
    # Reject malformed or all-zero identifiers so the wrapper never links the user's request to an invalid trace.
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
    """Continue PHP traceparent and tracestate across one ASGI request.

    Add this middleware when an app needs one trace spanning the PHP client and Python wrapper.
    Non-HTTP traffic or missing tracing packages passes through without changing the user response.
    """

    def __init__(self, app: Any, tracer_name: str = "strands-python-gateway") -> None:
        """Store the wrapped ASGI app and its telemetry name.
        Use the default name unless the copied gateway has its own stable service identity."""
        self.app = app
        self.tracer_name = tracer_name

    async def __call__(self, scope: dict[str, Any], receive: Any, send: Any) -> None:
        """Trace one HTTP request while preserving the wrapped app's response.
        Non-HTTP scopes or unavailable tracing pass through without creating a span."""
        # WebSocket/lifespan calls and installations without OpenTelemetry must reach the app unchanged.
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
            try:
                await self.app(scope, receive, send)
            finally:
                route_template = _route_template(scope)
                # A code-owned template is safe and stable; a missing route means there is no low-cardinality label to export.
                if route_template is not None:
                    span.set_attribute("http.route", route_template)


def _route_template(scope: Mapping[str, Any]) -> str | None:
    """Read the code-owned ASGI route template without exposing the concrete URL path."""
    route = scope.get("route")
    path = getattr(route, "path", None)

    return path if isinstance(path, str) and path.startswith("/") else None


def _is_lower_hex(value: str) -> bool:
    """Check whether a trace identifier uses lowercase hexadecimal characters.
    Empty input returns True mathematically, but callers separately enforce every required trace field's exact length."""
    return all(char in "0123456789abcdef" for char in value)
