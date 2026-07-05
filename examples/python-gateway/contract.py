from __future__ import annotations

import ipaddress
import json
import socket
from collections.abc import Iterable, Mapping
from typing import Any
from urllib.parse import urlparse


WIRE_VERSION = "1"
MAX_URL_MEDIA_BYTES = 10 * 1024 * 1024
SAFE_URL_MEDIA_TYPES = {
    "application/pdf",
    "image/gif",
    "image/jpeg",
    "image/png",
    "image/webp",
    "text/plain",
}


def extract_usage(raw: Mapping[str, Any] | None) -> dict[str, Any]:
    """Normalize sdk-python or wrapper usage counters into snake_case."""
    if raw is None:
        return {}

    usage = {
        "input_tokens": _number(raw, "input_tokens", "inputTokens"),
        "output_tokens": _number(raw, "output_tokens", "outputTokens"),
        "total_tokens": _number(raw, "total_tokens", "totalTokens"),
        "cache_read_input_tokens": _number(raw, "cache_read_input_tokens", "cacheReadInputTokens"),
        "cache_write_input_tokens": _number(raw, "cache_write_input_tokens", "cacheWriteInputTokens"),
        "latency_ms": _number(raw, "latency_ms", "latencyMs"),
        "time_to_first_byte_ms": _number(raw, "time_to_first_byte_ms", "timeToFirstByteMs"),
    }

    return {key: value for key, value in usage.items() if value is not None}


def build_invoke_response(
    *,
    text: str,
    agent: str = "reference-agent",
    session_id: str | None = None,
    usage: Mapping[str, Any] | None = None,
    model: str | None = None,
    stop_reason: str | None = "end_turn",
    message: Mapping[str, Any] | None = None,
    context_size: int | None = None,
    projected_context_size: int | None = None,
) -> dict[str, Any]:
    response: dict[str, Any] = {
        "text": text,
        "agent": agent,
        "usage": extract_usage(usage),
        "stop_reason": stop_reason,
    }
    if session_id is not None:
        response["session_id"] = session_id
    if model is not None:
        response["model"] = model
    if message is not None:
        response["message"] = dict(message)
    if context_size is not None:
        response["context_size"] = context_size
    if projected_context_size is not None:
        response["projected_context_size"] = projected_context_size

    return response


def map_sdk_event(event: Mapping[str, Any]) -> dict[str, Any]:
    """Normalize a fake/sdk-python event into the SSE event shape PHP reads."""
    event_type = str(event.get("type", "text"))

    if event_type in {"text", "thinking"}:
        return {
            "type": event_type,
            "content": str(event.get("content", "")),
        }

    if event_type == "tool_use":
        return {
            "type": "tool_use",
            "tool_name": str(event.get("tool_name", event.get("name", ""))),
            "tool_input": _object(event.get("tool_input", event.get("input", {}))),
        }

    if event_type == "tool_result":
        return {
            "type": "tool_result",
            "tool_name": str(event.get("tool_name", event.get("name", ""))),
            "result": event.get("result", {}),
        }

    if event_type == "complete":
        usage = extract_usage(_object(event.get("usage", {})))
        return {
            key: value
            for key, value in {
                "type": "complete",
                "text": str(event.get("text", "")),
                "session_id": event.get("session_id"),
                "usage": usage,
                "tools_used": event.get("tools_used", []),
                "stop_reason": event.get("stop_reason", "end_turn"),
                "context_size": event.get("context_size"),
                "projected_context_size": event.get("projected_context_size"),
            }.items()
            if value is not None
        }

    if event_type == "error":
        return {
            "type": "error",
            "message": str(event.get("message", "Agent stream failed.")),
            "code": str(event.get("code", "agent_error")),
        }

    return {"type": event_type, **dict(event)}


def sse_frame(event: Mapping[str, Any]) -> str:
    return f"data: {json.dumps(dict(event), separators=(',', ':'))}\n\n"


def fake_stream_events(text: str, *, session_id: str | None = None) -> Iterable[dict[str, Any]]:
    yield {"type": "thinking", "content": "Preparing response."}
    yield {"type": "text", "content": text}
    yield {
        "type": "complete",
        "text": text,
        "session_id": session_id,
        "usage": {"input_tokens": 12, "output_tokens": 4},
        "stop_reason": "end_turn",
    }


def discovery_response() -> dict[str, Any]:
    return {
        "name": "strands-reference-gateway",
        "wire_version": WIRE_VERSION,
        "endpoints": {
            "invoke": "/invoke",
            "stream": "/stream",
            "health": "/health",
        },
        "features": {
            "rich_input": True,
            "streaming": True,
            "message_metadata": True,
            "context_size": True,
            "cache_points": True,
            "document_context": True,
            "document_citations": True,
            "stream_resume": False,
            "a2a": False,
        },
    }


def assert_safe_url_source(
    url: str,
    *,
    resolved_ips: Iterable[str] = (),
    content_length: int | None = None,
    content_type: str | None = None,
) -> None:
    """Validate URL media before any app-specific fetcher downloads it."""
    parsed = urlparse(url)
    if parsed.scheme not in {"http", "https"}:
        raise ValueError("URL media must use http or https")
    if parsed.hostname is None:
        raise ValueError("URL media host is required")

    _reject_blocked_ip(parsed.hostname)

    checked_ips = tuple(resolved_ips)
    if not checked_ips:
        checked_ips = tuple(_resolve_host_ips(parsed.hostname))

    for resolved_ip in checked_ips:
        _reject_blocked_ip(resolved_ip)

    if content_length is not None and content_length > MAX_URL_MEDIA_BYTES:
        raise ValueError("URL media content is too large")
    if content_type is not None and content_type.split(";")[0].strip().lower() not in SAFE_URL_MEDIA_TYPES:
        raise ValueError("URL media content type is not allowed")


def _number(raw: Mapping[str, Any], snake_key: str, camel_key: str) -> int | float | None:
    value = raw.get(snake_key, raw.get(camel_key))
    if isinstance(value, bool) or value is None:
        return None
    if isinstance(value, int | float):
        return value
    if isinstance(value, str):
        try:
            return float(value) if "." in value else int(value)
        except ValueError:
            return None

    return None


def _object(value: Any) -> dict[str, Any]:
    return dict(value) if isinstance(value, Mapping) else {}


def _reject_blocked_ip(host_or_ip: str) -> None:
    try:
        ip = ipaddress.ip_address(host_or_ip)
    except ValueError:
        return

    if (
        ip.is_loopback
        or ip.is_link_local
        or ip.is_private
        or ip.is_multicast
        or ip.is_unspecified
        or ip == ipaddress.ip_address("169.254.169.254")
    ):
        raise ValueError("URL media host resolves to a blocked network")


def _resolve_host_ips(hostname: str) -> set[str]:
    try:
        results = socket.getaddrinfo(hostname, None, type=socket.SOCK_STREAM)
    except OSError as exc:
        raise ValueError("URL media host could not be resolved") from exc

    ips = {str(result[4][0]) for result in results if result[4]}
    if not ips:
        raise ValueError("URL media host could not be resolved")

    return ips
