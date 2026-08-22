# Strands Python Gateway Template

This directory contains source-repository example code for a Python HTTP wrapper around `strands-agents/sdk-python`. Composer archives exclude
`examples/`; copy it from the repository rather than treating it as installed PHP runtime code.

The PHP client targets the Strands HTTP Wire Contract v1, not raw sdk-python `TypedDict` payloads. This template accepts PHP-friendly JSON, calls a
Python agent, and emits the snake_case JSON and SSE shapes documented in the [Wire Contract](../../docs/wire-contract.md).

## Run Locally

Install your Python dependencies in your app environment:

```bash
python3 -m pip install fastapi uvicorn pydantic opentelemetry-api opentelemetry-sdk opentelemetry-instrumentation-fastapi
```

Start the example gateway:

```bash
uvicorn app:app --reload --app-dir examples/python-gateway
```

Smoke check the pure contract helpers:

```bash
PYTHONDONTWRITEBYTECODE=1 python3 examples/python-gateway/tests/smoke_contract.py
```

The example uses a fake agent by default, so it does not require model credentials.

## What to Copy

- `contract.py`: wire-contract normalization helpers for actual sdk-python text, result, reasoning, citation, and tool callback shapes.
- `tracing.py`: FastAPI trace-context continuation middleware.
- `app.py`: minimal `/health`, `/invoke`, `/stream`, and custom-endpoint blueprint.

Custom routes keep app-owned request and response schemas. Normalize only safe summaries into shared fields such as `usage`, `stop_reason`, and
`tools_used`. Untyped sdk-python lifecycle callbacks return `None` from `map_sdk_event()` and must not become empty text events.

The fake app advertises only streaming, message metadata, and context sizes. Rich input, cache points, document context/citations, stream resume,
and A2A remain disabled until the app adds their request-side behavior.

## URL Media

URL media fetching is intentionally absent. `contract.py::assert_safe_url_source()` validates the scheme, host, and resolved public addresses but does
not fetch anything.

A production fetcher must also:

- Connect to one validated IP while preserving the original hostname for the HTTP `Host` header and TLS verification.
- Repeat validation and address pinning after every redirect so DNS rebinding cannot switch the request to a private address.
- Reject localhost, link-local, metadata-service, and private-network destinations.
- Enforce response-size, content-type, redirect-count, and timeout limits before passing bytes to sdk-python or a model provider.
