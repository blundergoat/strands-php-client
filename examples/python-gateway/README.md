# Strands Python Gateway Template

This directory is example code for a Python HTTP wrapper around `strands-agents/sdk-python`.

The PHP client targets the Strands HTTP Wire Contract v1, not raw sdk-python `TypedDict` payloads. A wrapper built from this template accepts PHP-friendly JSON, calls your Python agent, and emits the snake_case response/SSE shapes documented in `docs/wire-contract.md`.

## Run Locally

Install your Python dependencies in your app environment:

```bash
python -m pip install fastapi uvicorn pydantic opentelemetry-api opentelemetry-sdk opentelemetry-instrumentation-fastapi
```

Start the example gateway:

```bash
uvicorn app:app --reload --app-dir examples/python-gateway
```

Smoke check the pure contract helpers:

```bash
python examples/python-gateway/tests/smoke_contract.py
```

The example uses a fake agent by default, so it does not require model credentials.

## What To Copy

- `contract.py`: wire-contract normalization helpers.
- `tracing.py`: FastAPI trace-context continuation middleware.
- `app.py`: minimal `/health`, `/invoke`, `/stream`, and custom-endpoint blueprint.

Custom app routes should keep using app-owned request and response schemas. Normalize only safe summaries into shared fields such as `usage`, `stop_reason`, and `tools_used`.

## URL Media

URL media fetching is intentionally not implemented in the template. If an app accepts URL source blocks, it must add an explicit fetcher that rejects localhost, link-local, metadata-service IPs, RFC1918/private networks, non-HTTP schemes, oversized content, unsafe content types, and slow responses before passing bytes into sdk-python or a provider.
