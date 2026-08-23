"""Run dependency-free smoke checks for the copyable Python gateway contract helpers.

The scenarios mirror what a PHP application receives for invoke, streaming, usage, discovery, tracing, and URL-media validation.
Execute this file directly before copying or shipping gateway changes; no model credentials or live server are required.
"""

from __future__ import annotations

import asyncio
import sys
from pathlib import Path
from types import SimpleNamespace


ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = ROOT.parents[1]
sys.path.insert(0, str(ROOT))

from contract import (  # noqa: E402
    assert_safe_url_source,
    build_invoke_response,
    discovery_response,
    extract_usage,
    fake_stream_events,
    map_sdk_event,
    sse_frame,
)
import tracing as tracing_module  # noqa: E402
from tracing import TraceContextMiddleware, parse_traceparent  # noqa: E402


def test_usage_mapping() -> None:
    """Verify common SDK counter spellings become finite snake_case values a PHP app displays.
    Run this case when usage aliases or number parsing changes."""
    usage = extract_usage({"inputTokens": "12", "outputTokens": 3, "latency_ms": 10.5})
    assert usage["input_tokens"] == 12
    assert usage["output_tokens"] == 3
    assert usage["latency_ms"] == 10.5


def test_usage_mapping_rejects_non_finite_numbers() -> None:
    """Protect PHP usage displays from NaN and infinity while retaining exponent-form numeric strings.
    Run this case when strict JSON or numeric normalization changes."""
    usage = extract_usage({
        "inputTokens": "1e3",
        "latency_ms": float("nan"),
        "timeToFirstByteMs": float("inf"),
    })

    assert usage == {"input_tokens": 1000.0}


def test_invoke_response() -> None:
    """Verify a blocking answer carries text, normalized usage, and context size into AgentResponse.
    Run this case when the invoke response builder or PHP hydration fields change."""
    response = build_invoke_response(
        text="Echo",
        session_id="session-001",
        usage={"inputTokens": 1, "outputTokens": 2},
        context_size=128,
        projected_context_size=160,
    )
    assert response["text"] == "Echo"
    assert response["usage"]["input_tokens"] == 1
    assert response["context_size"] == 128
    assert response["stop_reason"] == "end_turn"


def test_invoke_response_omits_an_unknown_stop_reason() -> None:
    """Verify a wrapper that cannot name why the agent stopped leaves the field out instead of sending null.
    Run this case when the invoke response builder changes, because the contract types stop_reason as a string when present."""
    response = build_invoke_response(text="Echo", stop_reason=None)

    assert "stop_reason" not in response
    assert response["text"] == "Echo"


def test_sse_mapping() -> None:
    """Verify fake live updates are valid SSE frames and finish with a complete event for the UI.
    Run this case when framing or the copyable demo sequence changes."""
    frames = [sse_frame(map_sdk_event(event)) for event in fake_stream_events("Echo")]
    assert frames[0].startswith("data: ")
    assert '"type":"complete"' in frames[-1]

    complete_event = map_sdk_event(
        {"type": "complete", "text": "Echo", "session_id": None},
        fallback_session_id="session-001",
    )
    assert complete_event is not None
    assert complete_event["session_id"] == "session-001"


def test_sse_frame_rejects_non_finite_numbers() -> None:
    """Verify invalid nested numbers fail at the gateway instead of becoming frames PHP silently drops.
    Run this case when event passthrough fields or strict JSON serialization changes."""
    # Every non-finite float is invalid JSON and would otherwise make the PHP stream skip the complete event.
    for non_finite_duration in (float("nan"), float("inf"), float("-inf")):
        try:
            sse_frame({
                "type": "complete",
                "text": "Final answer",
                "usage": {},
                "tools_used": [{"name": "lookup", "duration_ms": non_finite_duration}],
                "stop_reason": "end_turn",
            })
        except ValueError:
            # For example, a broken tool metric must stop at the wrapper rather than disappear from the user's completed PHP stream.
            pass
        else:
            raise AssertionError("non-finite SSE values must fail strict JSON serialization")


def test_sdk_python_stream_event_mapping() -> None:
    """Verify SDK text, reasoning, redaction, and citation callbacks become distinct PHP events.
    Run this case when sdk-python callback shapes or event precedence changes."""
    assert map_sdk_event({"data": "Hello"}) == {"type": "text", "content": "Hello"}
    assert map_sdk_event({"reasoningText": "considering"}) == {
        "type": "thinking",
        "content": "considering",
    }
    assert map_sdk_event({"reasoning_signature": "sig-123", "reasoningText": "prior thought"}) == {
        "type": "reasoning_signature",
        "signature": "sig-123",
    }
    assert map_sdk_event({"reasoningRedactedContent": b"redacted", "reasoningText": "prior thought"}) == {
        "type": "reasoning_redacted",
    }
    assert map_sdk_event({
        "citation": {
            "title": "Source Document",
            "location": {"documentPage": {"documentIndex": 0, "start": 1, "end": 3}},
            "sourceContent": [{"text": "source text"}],
        },
        "reasoningText": "prior thought",
    }) == {
        "type": "citation",
        "citation": {
            "location": {
                "type": "DOCUMENT",
                "start_page_index": 1,
                "end_page_index": 3,
                "title": "Source Document",
            },
            "source_content": {
                "type": "TEXT",
                "text": "source text",
                "document_name": "Source Document",
            },
        },
    }


def test_sdk_python_tool_event_mapping() -> None:
    """Verify incomplete tool JSON stays hidden until a safe tool-use or tool-result update exists.
    Run this case when cumulative tool callback mapping changes."""
    assert map_sdk_event({
        "type": "tool_use_stream",
        "current_tool_use": {"name": "lookup", "input": '{"query":'},
    }) is None
    assert map_sdk_event({
        "type": "tool_use_stream",
        "current_tool_use": {"name": "lookup", "input": '{"query":"patient"}'},
    }) == {
        "type": "tool_use",
        "tool_name": "lookup",
        "tool_input": {"query": "patient"},
    }
    assert map_sdk_event({
        "type": "tool_result",
        "tool_result": {"status": "success", "content": [{"text": "found"}]},
    }) == {
        "type": "tool_result",
        "result": {"status": "success", "content": [{"text": "found"}]},
    }


def test_sdk_python_agent_result_mapping() -> None:
    """Verify AgentResult becomes the terminal answer, usage, tool, context, stop, and session data PHP needs.
    Run this case when sdk-python AgentResult normalization or session fallback changes."""

    class FakeAgentResult(SimpleNamespace):
        """Mimic the sdk-python AgentResult attributes used at stream completion.

        Its string form represents the final answer a user sees.
        Use this local double to keep the contract smoke test independent of sdk-python and model credentials.
        """

        def __str__(self) -> str:
            """Return final display text as sdk-python AgentResult string conversion would.
            Use it when the adapter builds the text shown in the final PHP stream result."""
            return str(self.display_text)

    metrics = SimpleNamespace(
        accumulated_usage={
            "inputTokens": 12,
            "outputTokens": 4,
            "totalTokens": 16,
            "cacheReadInputTokens": 3,
        },
        accumulated_metrics={"latencyMs": 842.5, "timeToFirstByteMs": 210.1},
        tool_metrics={"lookup": SimpleNamespace(total_time=0.125)},
    )
    result = FakeAgentResult(
        display_text="Final answer\n",
        stop_reason="limit_turns",
        metrics=metrics,
        context_size=128,
        projected_context_size=144,
    )

    mapped = map_sdk_event({"result": result}, fallback_session_id="session-001")

    assert mapped is not None
    assert mapped["type"] == "complete"
    assert mapped["text"] == "Final answer"
    assert mapped["stop_reason"] == "limit_turns"
    assert mapped["usage"] == {
        "input_tokens": 12,
        "output_tokens": 4,
        "total_tokens": 16,
        "cache_read_input_tokens": 3,
        "latency_ms": 842.5,
        "time_to_first_byte_ms": 210.1,
    }
    assert mapped["tools_used"] == [{"name": "lookup", "duration_ms": 125}]
    assert mapped["context_size"] == 128
    assert mapped["projected_context_size"] == 144
    assert mapped["session_id"] == "session-001"

    result.display_text = "Final answer with user newline\n\n"
    mapped_with_user_newline = map_sdk_event({"result": result})
    assert mapped_with_user_newline is not None
    assert mapped_with_user_newline["text"] == "Final answer with user newline\n"


def test_sdk_python_control_event_is_skipped() -> None:
    """Verify SDK lifecycle callbacks do not create blank updates in the user's live stream.
    Run this case when fallback callback handling changes."""
    assert map_sdk_event({"init_event_loop": True}) is None


def test_url_media_guard() -> None:
    """Verify URL attachments retain public IPs while local and cloud-metadata destinations are blocked.
    Run this case when URL preflight, DNS pinning, or media policy changes."""
    validated_ips = assert_safe_url_source(
        "https://example.com/file.pdf",
        resolved_ips=["93.184.216.34", "93.184.216.34"],
        content_length=1024,
        content_type="application/pdf",
    )
    assert validated_ips == ("93.184.216.34",)
    try:
        assert_safe_url_source("http://169.254.169.254/latest/meta-data")
    except ValueError:
        # A user-provided URL pointing at cloud instance metadata must be rejected before the wrapper fetches it.
        pass
    else:
        raise AssertionError("metadata service IP must be rejected")
    try:
        assert_safe_url_source("http://localhost/file.pdf")
    except ValueError:
        # A hostname resolving to the wrapper itself could expose private files or services instead of the selected attachment.
        pass
    else:
        raise AssertionError("localhost must be rejected after hostname resolution")


def test_discovery() -> None:
    """Verify discovery advertises only gateway features a PHP application can use.
    Run this case when example routes or optional contract support changes."""
    response = discovery_response()
    assert response["wire_version"] == "1"
    assert response["features"]["streaming"] is True
    assert response["features"]["rich_input"] is False
    assert response["features"]["cache_points"] is False
    assert response["features"]["document_context"] is False
    assert response["features"]["document_citations"] is False


def test_traceparent_parse() -> None:
    """Verify valid trace context continues a user's operation while an all-zero trace ID is rejected.
    Run this case when trace-header validation changes."""
    parsed = parse_traceparent("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01")
    assert parsed is not None
    assert parsed["trace_id"] == "4bf92f3577b34da6a3ce929d0e0e4736"
    assert parse_traceparent("00-00000000000000000000000000000000-00f067aa0ba902b7-01") is None


def test_trace_middleware_uses_route_template() -> None:
    """Verify traces show a stable route template and never expose the user's concrete session path.
    Run this case when gateway route telemetry changes."""

    class FakeSpan:
        """Capture the attributes an operator would see on the wrapper span.

        It records values in memory instead of exporting telemetry.
        Use it to assert route safety without requiring an OpenTelemetry SDK backend.
        """

        def __init__(self) -> None:
            """Start with no attributes, matching a newly created server span.
            Use this local double before the middleware records the user's request route."""
            self.attributes: dict[str, object] = {}

        def set_attribute(self, key: str, value: object) -> None:
            """Record one safe attribute exactly as the tracing middleware supplies it.
            Use it to inspect what an operator would see for the user's request."""
            self.attributes[key] = value

    class FakeSpanContext:
        """Expose the captured span through the context-manager shape used by OpenTelemetry.

        Entering returns the same span and exiting performs no external work.
        Use it to exercise middleware lifecycle code synchronously in this smoke test.
        """

        def __init__(self, span: FakeSpan) -> None:
            """Keep the span that becomes current while the example request runs.
            Use it to connect the fake tracer lifecycle to the captured route attributes."""
            self.span = span

        def __enter__(self) -> FakeSpan:
            """Make the captured span available to the middleware body.
            Use it when the example request enters its traced operation."""
            return self.span

        def __exit__(self, *_args: object) -> None:
            """End the local context without exporting or suppressing an application exception.
            Use it when the example request leaves its traced operation."""
            return None

    class FakeTracer:
        """Return an in-memory span context whenever the middleware starts a wrapper request span.

        It replaces the external tracer only for this scenario.
        Use it to inspect caller-visible route attributes without network or exporter setup.
        """

        def __init__(self, span: FakeSpan) -> None:
            """Store the single span that captures this example request.
            Use it to keep the smoke scenario independent of an external exporter."""
            self.span = span

        def start_as_current_span(self, *_args: object, **_kwargs: object) -> FakeSpanContext:
            """Return a context manager around the captured span for the middleware lifecycle.
            Use it when the gateway starts tracing the user's example request."""
            return FakeSpanContext(self.span)

    class FakeTrace:
        """Provide the module-level `get_tracer()` entry point the tracing middleware calls.

        It owns one FakeTracer for the duration of the smoke scenario.
        Use it to replace only the tracing facade while leaving middleware behaviour intact.
        """

        def __init__(self, tracer: FakeTracer) -> None:
            """Store the tracer returned to middleware under any instrumentation name.
            Use it to replace only the module-level tracing facade in this scenario."""
            self.tracer = tracer

        def get_tracer(self, _name: str) -> FakeTracer:
            """Return the in-memory tracer for any instrumentation-library name.
            Use it when the middleware asks the tracing facade for its request tracer."""
            return self.tracer

    class FakeSpanKind:
        """Supply the server-span marker expected by the tracing middleware.

        The exact enum implementation is irrelevant to route sanitization.
        Use this minimal value to avoid importing an OpenTelemetry SDK in the dependency-free smoke run.
        """

        SERVER = "server"

    async def app(scope: dict[str, object], _receive: object, _send: object) -> None:
        """Simulate FastAPI resolving a concrete session URL to the safe route template.
        Use it to prove operators see a template instead of the user's secret path value."""
        scope["route"] = SimpleNamespace(path="/session/{session_id}/history")

    span = FakeSpan()
    original_trace = tracing_module.trace
    original_span_kind = tracing_module.SpanKind
    tracing_module.trace = FakeTrace(FakeTracer(span))
    tracing_module.SpanKind = FakeSpanKind
    try:
        middleware = TraceContextMiddleware(app)
        asyncio.run(middleware(
            {
                "type": "http",
                "method": "GET",
                "path": "/session/patient-secret-123/history",
                "headers": [],
            },
            None,
            None,
        ))
    finally:
        # Even if the middleware raises, restore the tracing facade so later smoke scenarios do not inherit this request's test doubles.
        tracing_module.trace = original_trace
        tracing_module.SpanKind = original_span_kind

    assert span.attributes["http.route"] == "/session/{session_id}/history"
    assert "url.path" not in span.attributes
    assert "patient-secret-123" not in str(span.attributes)


def test_discovery_fixture_alignment() -> None:
    """Verify gateway discovery keys stay aligned with the Wire Contract fixture consumed by PHP tests.
    Run this case when advertised endpoints or feature flags change."""
    import json

    fixture_path = REPO_ROOT / "tests" / "Fixtures" / "wire-contract" / "discovery-response.json"
    fixture = json.loads(fixture_path.read_text())
    response = discovery_response()
    assert response["wire_version"] == fixture["wire_version"]
    assert response["endpoints"].keys() == fixture["endpoints"].keys()
    assert response["features"].keys() == fixture["features"].keys()


# Running the file directly executes every contract scenario and prints one automation-friendly success line.
if __name__ == "__main__":
    test_usage_mapping()
    test_usage_mapping_rejects_non_finite_numbers()
    test_invoke_response()
    test_invoke_response_omits_an_unknown_stop_reason()
    test_sse_mapping()
    test_sse_frame_rejects_non_finite_numbers()
    test_sdk_python_stream_event_mapping()
    test_sdk_python_tool_event_mapping()
    test_sdk_python_agent_result_mapping()
    test_sdk_python_control_event_is_skipped()
    test_url_media_guard()
    test_discovery()
    test_traceparent_parse()
    test_trace_middleware_uses_route_template()
    test_discovery_fixture_alignment()
    print("python gateway contract smoke OK")
