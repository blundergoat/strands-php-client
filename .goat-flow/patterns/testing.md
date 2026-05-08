---
category: testing
last_reviewed: 2026-05-08
---

## Pattern: Fixture-based testing with JSON responses and SSE text files

**Context:** When writing tests for `StrandsClient`, transports, or streaming behaviour.

**Approach:** Use static fixture files in `tests/Fixtures/` rather than inline response data. JSON fixtures (`invoke-*.json`) provide canned agent responses for invoke tests. SSE text fixtures (`sse-*.txt`) provide raw event streams for streaming/parser tests. Tests load fixtures via `file_get_contents(__DIR__ . '/../Fixtures/<name>')` and feed them to mocked transports. Add new fixtures for new response shapes rather than embedding large JSON/SSE payloads in test methods.
