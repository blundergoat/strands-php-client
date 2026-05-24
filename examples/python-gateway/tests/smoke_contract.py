from __future__ import annotations

import sys
from pathlib import Path


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
from tracing import parse_traceparent  # noqa: E402


def test_usage_mapping() -> None:
    usage = extract_usage({"inputTokens": "12", "outputTokens": 3, "latency_ms": 10.5})
    assert usage["input_tokens"] == 12
    assert usage["output_tokens"] == 3
    assert usage["latency_ms"] == 10.5


def test_invoke_response() -> None:
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


def test_sse_mapping() -> None:
    frames = [sse_frame(map_sdk_event(event)) for event in fake_stream_events("Echo")]
    assert frames[0].startswith("data: ")
    assert '"type":"complete"' in frames[-1]


def test_url_media_guard() -> None:
    assert_safe_url_source(
        "https://example.com/file.pdf",
        resolved_ips=["93.184.216.34"],
        content_length=1024,
        content_type="application/pdf",
    )
    try:
        assert_safe_url_source("http://169.254.169.254/latest/meta-data")
    except ValueError:
        pass
    else:
        raise AssertionError("metadata service IP must be rejected")


def test_discovery() -> None:
    response = discovery_response()
    assert response["wire_version"] == "1"
    assert response["features"]["streaming"] is True


def test_traceparent_parse() -> None:
    parsed = parse_traceparent("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01")
    assert parsed is not None
    assert parsed["trace_id"] == "4bf92f3577b34da6a3ce929d0e0e4736"
    assert parse_traceparent("00-00000000000000000000000000000000-00f067aa0ba902b7-01") is None


def test_discovery_fixture_alignment() -> None:
    import json

    fixture_path = REPO_ROOT / "tests" / "Fixtures" / "wire-contract" / "discovery-response.json"
    fixture = json.loads(fixture_path.read_text())
    response = discovery_response()
    assert response["wire_version"] == fixture["wire_version"]
    assert response["endpoints"].keys() == fixture["endpoints"].keys()
    assert response["features"].keys() == fixture["features"].keys()


if __name__ == "__main__":
    test_usage_mapping()
    test_invoke_response()
    test_sse_mapping()
    test_url_media_guard()
    test_discovery()
    test_traceparent_parse()
    test_discovery_fixture_alignment()
    print("python gateway contract smoke OK")
