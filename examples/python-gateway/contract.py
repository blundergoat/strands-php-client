"""Translate sdk-python callback objects into Strands HTTP Wire Contract v1 payloads.

Use these helpers at the Python/PHP boundary so application screens receive stable snake_case JSON and terminal SSE events.
The module also supplies safe URL preflight checks and a truthful discovery document for wrapper implementations copied from this example.
"""

from __future__ import annotations

import ipaddress
import json
import math
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


def extract_usage(raw_usage: Mapping[str, Any] | None) -> dict[str, Any]:
    """Normalize finite SDK or wrapper counters into the snake_case usage map consumed by PHP.
    Use it at invoke and stream boundaries; None or unusable values are omitted so the PHP DTO keeps zero defaults."""
    # Some agent results omit metrics entirely; an empty map lets the PHP Usage DTO keep its documented zero defaults.
    if raw_usage is None:
        return {}

    usage = {
        "input_tokens": _finite_usage_number(raw_usage, "input_tokens", "inputTokens"),
        "output_tokens": _finite_usage_number(raw_usage, "output_tokens", "outputTokens"),
        "total_tokens": _finite_usage_number(raw_usage, "total_tokens", "totalTokens"),
        "cache_read_input_tokens": _finite_usage_number(raw_usage, "cache_read_input_tokens", "cacheReadInputTokens"),
        "cache_write_input_tokens": _finite_usage_number(raw_usage, "cache_write_input_tokens", "cacheWriteInputTokens"),
        "latency_ms": _finite_usage_number(raw_usage, "latency_ms", "latencyMs"),
        "time_to_first_byte_ms": _finite_usage_number(raw_usage, "time_to_first_byte_ms", "timeToFirstByteMs"),
    }

    normalized_usage: dict[str, Any] = {}
    # Each known field becomes one stable counter name for the PHP Usage DTO.
    for usage_field, usage_value in usage.items():
        # Omit unavailable values so the PHP client applies its documented zero default.
        if usage_value is not None:
            normalized_usage[usage_field] = usage_value

    return normalized_usage


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
    """Build the complete Wire Contract response a PHP invoke() caller renders after the agent finishes.
    Use it after AgentResult normalization; optional None values are omitted while empty text or mappings remain explicit."""
    response: dict[str, Any] = {
        "text": text,
        "agent": agent,
        "usage": extract_usage(usage),
        "stop_reason": stop_reason,
    }
    # A session ID means the user can continue this conversation in a later request.
    if session_id is not None:
        response["session_id"] = session_id
    # The model name is optional diagnostic metadata for apps that choose to display or record it.
    if model is not None:
        response["model"] = model
    # A normalized message envelope lets advanced UIs render citations or message-level metadata.
    if message is not None:
        response["message"] = dict(message)
    # Current context size can power a user-facing remaining-context hint when the SDK reports it.
    if context_size is not None:
        response["context_size"] = context_size
    # Projected size helps a UI warn before the next turn would exceed the agent's context limit.
    if projected_context_size is not None:
        response["projected_context_size"] = projected_context_size

    return response


def map_sdk_event(
    event: Mapping[str, Any],
    *,
    fallback_session_id: str | None = None,
) -> dict[str, Any] | None:
    """Normalize one SDK callback for the PHP stream and preserve the request session on terminal events.
    Use it for every callback; None means a lifecycle or incomplete update that should not reach the user's live screen."""
    raw_event_type = event.get("type")
    # A missing or non-string type may still be one of sdk-python's untyped callbacks handled later.
    event_type = raw_event_type if isinstance(raw_event_type, str) else None

    # Text and thinking callbacks both become visible incremental content, distinguished by their event type.
    if event_type in {"text", "thinking"}:
        return {
            "type": event_type,
            "content": str(event.get("content", "")),
        }

    # A complete typed tool-use callback already has enough information for the app's activity trail.
    if event_type == "tool_use":
        return {
            "type": "tool_use",
            "tool_name": str(event.get("tool_name", event.get("name", ""))),
            "tool_input": _tool_input(event.get("tool_input", event.get("input", {}))),
        }

    # Streaming tool input is cumulative JSON; emit it only after the object is complete enough for the PHP callback.
    if event_type == "tool_use_stream":
        current_tool_use = _mapping_or_empty(event.get("current_tool_use"))
        tool_name = current_tool_use.get("name")
        tool_input = _complete_tool_input(current_tool_use.get("input"))
        # sdk-python sends cumulative JSON on every tool-input delta; an empty name or incomplete object would create a misleading UI update.
        if not isinstance(tool_name, str) or tool_name == "" or tool_input is None:
            return None

        return {
            "type": "tool_use",
            "tool_name": tool_name,
            "tool_input": tool_input,
        }

    # Tool results are app-visible activity entries, but their optional name may arrive under either SDK spelling.
    if event_type == "tool_result":
        tool_result = event.get("tool_result", event.get("result", {}))
        # Mapping results are copied so later SDK mutation cannot change an event the UI already received.
        displayable_tool_result = dict(tool_result) if isinstance(tool_result, Mapping) else tool_result
        normalized_tool_result_event = {"type": "tool_result", "result": displayable_tool_result}
        tool_name = event.get("tool_name", event.get("name"))
        # A missing tool name still leaves a useful result; add the label only when it is a real string.
        if isinstance(tool_name, str):
            normalized_tool_result_event["tool_name"] = tool_name

        return normalized_tool_result_event

    # A wrapper-normalized complete callback carries the final answer, usage, session, and context values the PHP StreamResult needs.
    if event_type == "complete":
        normalized_usage = extract_usage(_mapping_or_empty(event.get("usage", {})))
        complete_event_session_id = event.get("session_id")
        # A wrapper may emit an explicit None even though the request belongs to a session; retain that request ID for the user's next turn.
        if complete_event_session_id is None:
            complete_event_session_id = fallback_session_id

        complete_event = {
            "type": "complete",
            "text": str(event.get("text", "")),
            "usage": normalized_usage,
            "tools_used": event.get("tools_used", []),
            "stop_reason": event.get("stop_reason", "end_turn"),
        }
        optional_terminal_fields = {
            "session_id": complete_event_session_id,
            "context_size": event.get("context_size"),
            "projected_context_size": event.get("projected_context_size"),
        }
        # Each present optional value preserves conversation or context detail for the final PHP result.
        for field_name, field_value in optional_terminal_fields.items():
            # None means the wrapper supplied no detail, so omit the field rather than inventing a UI value.
            if field_value is not None:
                complete_event[field_name] = field_value

        return complete_event

    # An explicit error is terminal, so give the PHP app a stable message and code even when the wrapper omitted them.
    if event_type == "error":
        return {
            "type": "error",
            "message": str(event.get("message", "Agent stream failed.")),
            "code": str(event.get("code", "agent_error")),
        }

    # Preserve an already typed future event so newer wrapper fields are not discarded at this normalization boundary.
    if isinstance(event_type, str):
        return {"type": event_type, **dict(event)}

    # Raw sdk-python text deltas use `data` without a type; map them to the text update a chat UI expects.
    if isinstance(event.get("data"), str):
        return {"type": "text", "content": event["data"]}

    # Reasoning signatures take precedence over adjacent reasoning text because they are verification events, not displayable thought content.
    if isinstance(event.get("reasoning_signature"), str):
        return {"type": "reasoning_signature", "signature": event["reasoning_signature"]}

    # A redaction marker tells the app reasoning existed but must not be displayed.
    if "reasoningRedactedContent" in event:
        return {"type": "reasoning_redacted"}

    # Citation callbacks are flattened into the source shape the PHP citation DTO understands.
    if isinstance(event.get("citation"), Mapping):
        return {"type": "citation", "citation": _normalize_citation(event["citation"])}

    # Raw reasoning text becomes a thinking update only when no higher-priority reasoning marker was present.
    if isinstance(event.get("reasoningText"), str):
        return {"type": "thinking", "content": event["reasoningText"]}

    # The SDK AgentResult omits wrapper-owned session state, so copy the request's ID into the final event when the app supplied one.
    if "result" in event:
        terminal_event = _map_agent_result(event["result"])

        # A one-shot request has no session ID; omitting it tells the PHP app there is no conversation to continue.
        if fallback_session_id is not None:
            terminal_event["session_id"] = fallback_session_id

        return terminal_event

    # Lifecycle/control callbacks have no PHP payload; empty text defaults would hide adapter bugs and create false updates.
    return None


def sse_frame(event: Mapping[str, Any]) -> str:
    """Encode one normalized callback as the SSE frame received by a PHP live-update callback.
    Use it only after map_sdk_event() returned an object; non-finite numbers raise instead of sending PHP an unreadable frame."""
    return f"data: {json.dumps(dict(event), separators=(',', ':'), allow_nan=False)}\n\n"


def fake_stream_events(text: str, *, session_id: str | None = None) -> Iterable[dict[str, Any]]:
    """Yield a minimal thinking, text, and complete sequence for trying the PHP UI without model credentials.
    Use it in the copyable demo; a None session omits conversation continuity and empty text produces an explicit blank text event."""
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
    """Advertise only the routes and optional behaviours this gateway implements for its PHP caller.
    Use it for a health or discovery route so an app enables only controls the copied wrapper actually supports."""
    return {
        "name": "strands-reference-gateway",
        "wire_version": WIRE_VERSION,
        "endpoints": {
            "invoke": "/invoke",
            "stream": "/stream",
            "health": "/health",
        },
        "features": {
            "rich_input": False,
            "streaming": True,
            "message_metadata": True,
            "context_size": True,
            "cache_points": False,
            "document_context": False,
            "document_citations": False,
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
) -> tuple[str, ...]:
    """Validate a user media URL and return public IPs the fetcher must pin against DNS rebinding.
    Use it before any remote fetch; an empty resolved_ips input triggers DNS lookup, while missing length or type skips only that optional check."""
    parsed = urlparse(url)
    # Non-HTTP schemes could expose local files or unsupported protocols instead of the media the user selected.
    if parsed.scheme not in {"http", "https"}:
        raise ValueError("URL media must use http or https")
    # A URL without a host cannot be safely resolved or pinned by the wrapper's media fetcher.
    if parsed.hostname is None:
        raise ValueError("URL media host is required")

    _reject_blocked_ip(parsed.hostname)

    checked_ips = tuple(dict.fromkeys(resolved_ips))
    # The caller may supply pre-resolved addresses; otherwise resolve once now so the eventual connection can pin the checked result.
    if not checked_ips:
        checked_ips = tuple(sorted(_resolve_host_ips(parsed.hostname)))

    # Every candidate address must be public because the fetcher may connect to any one of them after validation.
    for resolved_ip in checked_ips:
        _reject_blocked_ip(resolved_ip)

    # Oversized remote media would make the user wait while consuming unbounded wrapper memory or bandwidth.
    if content_length is not None and content_length > MAX_URL_MEDIA_BYTES:
        raise ValueError("URL media content is too large")
    # Only known document/image/text types should reach the agent when the server reports a content type.
    if content_type is not None and content_type.split(";")[0].strip().lower() not in SAFE_URL_MEDIA_TYPES:
        raise ValueError("URL media content type is not allowed")

    return checked_ips


def _finite_usage_number(usage_data: Mapping[str, Any], snake_key: str, camel_key: str) -> int | float | None:
    """Read one finite usage value so the normalized usage map remains safe for strict JSON encoders.
    Use it during usage normalization; missing, boolean, empty, nonnumeric, NaN, or infinite input returns None and is omitted."""
    usage_value = usage_data.get(snake_key, usage_data.get(camel_key))
    # Missing values and booleans do not represent a usage counter the PHP app can display.
    if isinstance(usage_value, bool) or usage_value is None:
        return None

    # Python integers are exact; floating-point counters must be finite before JSON encoding.
    if isinstance(usage_value, int):
        return usage_value
    # A floating-point counter is usable only when strict JSON encoders can represent it.
    if isinstance(usage_value, float):
        # NaN and infinity are not valid strict JSON numbers and must not reach the PHP usage display.
        if not math.isfinite(usage_value):
            return None

        return usage_value

    # Wrappers sometimes supply counters as strings, so try an exact integer before accepting a finite decimal or exponent.
    if isinstance(usage_value, str):
        try:
            return int(usage_value)
        except ValueError:
            # For example, a decimal or exponent such as `10.5` or `1e3` is numeric but cannot be parsed as an exact integer first.
            try:
                parsed_usage_number = float(usage_value)
            except ValueError:
                # For example, an empty or unit-suffixed counter cannot become a trustworthy value in the PHP usage display.
                return None

            # A decimal or exponent is usable only when it becomes a finite strict-JSON number.
            if not math.isfinite(parsed_usage_number):
                return None

            return parsed_usage_number

    return None


def _mapping_or_empty(candidate_value: Any) -> dict[str, Any]:
    """Copy a mapping into the mutable object shape used while assembling a PHP-facing response.
    Use it at optional SDK boundaries; None or any non-mapping value becomes an empty object."""
    # A real mapping is copied for safe mutation; None and scalar SDK members represent no object detail.
    return dict(candidate_value) if isinstance(candidate_value, Mapping) else {}


def _tool_input(tool_input_value: Any) -> dict[str, Any]:
    """Return complete tool arguments for the app activity trail.
    Use it for typed tool events; None or incomplete cumulative JSON becomes an empty object until a displayable argument map exists."""
    completed_tool_input = _complete_tool_input(tool_input_value)

    # A missing complete object keeps the activity entry safe but empty until later cumulative JSON arrives.
    return completed_tool_input if completed_tool_input is not None else {}


def _complete_tool_input(tool_input_value: Any) -> dict[str, Any] | None:
    """Parse one SDK tool-input value into a complete object safe for a tool-use update.
    Use it for cumulative callbacks; None, malformed, incomplete, or non-object JSON returns None and stays hidden from the UI."""
    # Some SDK callbacks already expose the complete argument object, so copy it without JSON parsing.
    if isinstance(tool_input_value, Mapping):
        return dict(tool_input_value)
    # Streaming callbacks expose cumulative JSON text that may be incomplete on early deltas.
    if isinstance(tool_input_value, str):
        try:
            decoded_tool_input = json.loads(tool_input_value)
        except json.JSONDecodeError:
            # For example, `{"query":` is a normal intermediate callback, not an error the user should see.
            return None
        # Only an object can become named tool arguments in the PHP activity display.
        return dict(decoded_tool_input) if isinstance(decoded_tool_input, Mapping) else None

    return None


def _normalize_citation(citation_data: Mapping[str, Any]) -> dict[str, Any]:
    """Flatten an sdk-python citation into the source and location object rendered by PHP.
    Use it for citation callbacks; empty or unrecognized SDK detail falls back to the original mapping for forward compatibility."""
    # A wrapper that already emitted Wire Contract citation fields needs no SDK-shape translation.
    if any(key in citation_data for key in ("source_content", "generated_content", "source", "text")):
        return dict(citation_data)

    raw_citation_title = citation_data.get("title")
    # A string title can label the source; missing or non-string titles remain absent.
    citation_title = raw_citation_title if isinstance(raw_citation_title, str) else None
    citation_location = _normalize_citation_location(_mapping_or_empty(citation_data.get("location")), citation_title)
    source_content: dict[str, Any] = {}
    raw_source_content = citation_data.get("sourceContent")
    # SDK sourceContent is a list of text fragments; join them into the single source block used by the PHP DTO.
    if isinstance(raw_source_content, list):
        source_text_fragments = []
        # Each SDK fragment may contribute text to the source excerpt shown below the answer.
        for source_fragment in raw_source_content:
            # Malformed or text-less fragments cannot produce a useful citation excerpt.
            if isinstance(source_fragment, Mapping) and isinstance(source_fragment.get("text"), str):
                source_text_fragments.append(source_fragment["text"])
        source_text = "\n".join(source_text_fragments)
        # Empty fragments give the user no source text, so omit the block instead of rendering a blank citation.
        if source_text != "":
            source_content = {"type": "TEXT", "text": source_text}
            # A document title doubles as its display name when the SDK location points into a document.
            if citation_title is not None and citation_location.get("type") == "DOCUMENT":
                source_content["document_name"] = citation_title

    normalized_citation = {}
    # Include a location only when the SDK supplied enough detail for a useful link or range label.
    if citation_location:
        normalized_citation["location"] = citation_location
    # Include source text only when at least one non-empty SDK fragment survived normalization.
    if source_content:
        normalized_citation["source_content"] = source_content

    return normalized_citation or dict(citation_data)


def _normalize_citation_location(location_data: Mapping[str, Any], citation_title: str | None) -> dict[str, Any]:
    """Map SDK document, web, or search-result detail into the compact location shown beside a citation.
    Use it during citation normalization; empty detail and a None title return an empty location object."""
    location: dict[str, Any] = {}
    location_shapes = (
        ("documentChar", "DOCUMENT", "start_character_index", "end_character_index"),
        ("documentChunk", "DOCUMENT", "start_chunk_index", "end_chunk_index"),
        ("documentPage", "DOCUMENT", "start_page_index", "end_page_index"),
    )
    # Document citations use one of three mutually exclusive range shapes; the first populated shape becomes the UI location.
    for sdk_key, wire_type, start_key, end_key in location_shapes:
        document_range = _mapping_or_empty(location_data.get(sdk_key))
        # A populated document range gives the app a source type and optional start/end indices to display.
        if document_range:
            location = {"type": wire_type}
            # The SDK may omit a start index; only copy real integers into the contract.
            if isinstance(document_range.get("start"), int):
                location[start_key] = document_range["start"]
            # The SDK may omit an end index; only copy real integers into the contract.
            if isinstance(document_range.get("end"), int):
                location[end_key] = document_range["end"]
            break

    web_location = _mapping_or_empty(location_data.get("web"))
    # A web location supersedes any document shape because it gives the user a directly navigable source.
    if web_location:
        location = {"type": "WEB"}
        # Add the link only when the SDK supplied a string URL the PHP DTO can expose.
        if isinstance(web_location.get("url"), str):
            location["url"] = web_location["url"]

    search_result_location = _mapping_or_empty(location_data.get("searchResultLocation"))
    # Search-result locations identify a ranked result instead of a document range or direct web URL.
    if search_result_location:
        location = {"type": "SEARCH_RESULT"}
        # A numeric rank lets the UI explain which search result supported the answer.
        if isinstance(search_result_location.get("searchResultIndex"), int):
            location["search_result_rank"] = search_result_location["searchResultIndex"]

    # A title is useful across document, web, and search locations, and can stand alone when no typed location survived.
    if citation_title is not None:
        location["title"] = citation_title

    return location


def _member_or_default(source_object: Any, member_name: str, default_value: Any = None) -> Any:
    """Read an SDK member from a mapping test double or the real attribute object.
    Use it at the SDK boundary; absent or failing lazy attributes return the caller's fallback, which defaults to None."""
    # Smoke tests and some wrappers represent SDK result objects as mappings rather than attribute instances.
    if isinstance(source_object, Mapping):
        return source_object.get(member_name, default_value)
    try:
        return getattr(source_object, member_name)
    except (AttributeError, RuntimeError):
        # For example, a lazy SDK property may raise at stream shutdown; the UI should receive an omitted optional field instead.
        return default_value


def _map_agent_result(agent_result: Any) -> dict[str, Any]:
    """Build the terminal event a PHP StreamResult needs from sdk-python AgentResult.
    Use it at stream completion; absent optional SDK members are omitted while the result string supplies final display text."""
    agent_metrics = _member_or_default(agent_result, "metrics")
    usage_values = _mapping_or_empty(_member_or_default(agent_metrics, "accumulated_usage", {}))
    usage_values.update(_mapping_or_empty(_member_or_default(agent_metrics, "accumulated_metrics", {})))

    stop_reason = _member_or_default(agent_result, "stop_reason")
    context_size = _member_or_default(agent_result, "context_size")
    projected_context_size = _member_or_default(agent_result, "projected_context_size")

    # sdk-python adds one framework newline after text output; remove only that suffix so user-authored trailing newlines remain visible.
    terminal_event: dict[str, Any] = {
        "type": "complete",
        "text": str(agent_result).removesuffix("\n"),
        "usage": extract_usage(usage_values),
        "tools_used": _tool_summaries(agent_metrics),
    }
    # A missing stop reason leaves the PHP typed and raw stop fields unset rather than displaying "None".
    if stop_reason is not None:
        terminal_event["stop_reason"] = str(stop_reason)
    # Context counts must be real integers because booleans are also int instances in Python but make misleading UI hints.
    if isinstance(context_size, int) and not isinstance(context_size, bool):
        terminal_event["context_size"] = context_size
    # The same strict rule protects the projected next-turn context hint.
    if isinstance(projected_context_size, int) and not isinstance(projected_context_size, bool):
        terminal_event["projected_context_size"] = projected_context_size

    return terminal_event


def _tool_summaries(agent_metrics: Any) -> list[dict[str, Any]]:
    """Summarize SDK tool timings for an app activity trail without exposing tool payloads.
    Use it at stream completion; missing metrics return an empty list and unsafe durations omit only duration_ms."""
    tool_metrics = _member_or_default(agent_metrics, "tool_metrics", {})
    # No SDK timing map means the terminal UI has no tool summaries to show.
    if not isinstance(tool_metrics, Mapping):
        return []

    tool_summaries = []
    # Each SDK tool metric becomes one safe name-and-duration summary for the PHP result.
    for tool_name, tool_metric in tool_metrics.items():
        tool_summary: dict[str, Any] = {"name": str(tool_name)}
        total_time = _member_or_default(tool_metric, "total_time")
        # A finite duration lets the UI show elapsed milliseconds; NaN/infinity would break strict JSON consumers.
        if isinstance(total_time, int) and not isinstance(total_time, bool):
            tool_summary["duration_ms"] = round(total_time * 1000)
        # Fractional SDK seconds are useful when finite and become rounded milliseconds for the PHP result.
        elif isinstance(total_time, float) and math.isfinite(total_time):
            tool_summary["duration_ms"] = round(total_time * 1000)
        tool_summaries.append(tool_summary)

    return tool_summaries


def _reject_blocked_ip(host_or_ip: str) -> None:
    """Reject a literal address that could reach the wrapper host or a private network.
    Use it before and after DNS resolution; a hostname is deferred, while an empty or invalid hostname is handled by the resolver."""
    try:
        parsed_ip_address = ipaddress.ip_address(host_or_ip)
    except ValueError:
        # For example, a user may attach `example.com`; resolve and check that hostname later because only literal IPs are classified here.
        return

    # Local, private, link-local, multicast, unspecified, and cloud-metadata addresses are never safe URL-media destinations.
    if (
        parsed_ip_address.is_loopback
        or parsed_ip_address.is_link_local
        or parsed_ip_address.is_private
        or parsed_ip_address.is_multicast
        or parsed_ip_address.is_unspecified
        or parsed_ip_address == ipaddress.ip_address("169.254.169.254")
    ):
        raise ValueError("URL media host resolves to a blocked network")


def _resolve_host_ips(hostname: str) -> set[str]:
    """Resolve every stream-capable address once so the fetcher can validate and pin its destinations.
    Use it when the caller supplied no checked IPs; empty or failed DNS results raise a user-safe ValueError."""
    try:
        address_records = socket.getaddrinfo(hostname, None, type=socket.SOCK_STREAM)
    except OSError as exc:
        # For example, a misspelled or offline host cannot be safely fetched for the user's attachment.
        raise ValueError("URL media host could not be resolved") from exc

    resolved_ips = set()
    # Each non-empty socket record contributes one concrete address the media fetcher can validate and pin.
    for address_record in address_records:
        # A record without an address tuple gives the fetcher nothing safe to connect to.
        if address_record[4]:
            resolved_ips.add(str(address_record[4][0]))
    # A resolver response with no usable addresses leaves the fetcher nothing validated to pin.
    if not resolved_ips:
        raise ValueError("URL media host could not be resolved")

    return resolved_ips
