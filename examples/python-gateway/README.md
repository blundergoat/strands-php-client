# Strands Python Gateway Template

This directory contains source-repository example code for a Python HTTP wrapper around `strands-agents/sdk-python`. Composer archives exclude
`examples/`; copy it from the repository rather than treating it as installed PHP runtime code.

The PHP client targets the Strands HTTP Wire Contract v1, not raw sdk-python `TypedDict` payloads. A wrapper built from this template accepts PHP-friendly JSON, calls your Python agent, and emits the snake_case response/SSE shapes documented in `docs/wire-contract.md`.

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

## What To Copy

- `contract.py`: wire-contract normalization helpers for actual sdk-python text, result, reasoning, citation, and tool callback shapes.
- `tracing.py`: FastAPI trace-context continuation middleware.
- `app.py`: minimal `/health`, `/invoke`, `/stream`, and custom-endpoint blueprint.

Custom routes keep app-owned request and response schemas. Normalize only safe summaries into shared fields such as `usage`, `stop_reason`, and
`tools_used`. Untyped sdk-python lifecycle callbacks return `None` from `map_sdk_event()` and must not become empty text events.

The fake app advertises only streaming, message metadata, and context sizes. Rich input, cache points, document context/citations, stream resume,
and A2A remain disabled until the app adds their request-side behavior.

## URL Media

URL media fetching is intentionally not implemented in the template. If an app accepts URL source blocks, it must add an explicit fetcher that rejects localhost, link-local, metadata-service IPs, RFC1918/private networks, non-HTTP schemes, oversized content, unsafe content types, and slow responses before passing bytes into sdk-python or a provider.
