---
category: testing
last_reviewed: 2026-05-24
---

## Pattern: Fixture-based testing with JSON responses and SSE files

**Context:** When writing tests for `StrandsClient`, transports, or streaming behaviour.

**Approach:** Use static fixture files in `tests/Fixtures/` rather than inline response data. JSON fixtures (`invoke-*.json`) provide canned agent responses for invoke tests. SSE fixtures (`*.sse`, e.g. `stream-success.sse`, `stream-guardrail.sse`) provide raw event streams for streaming/parser tests. Load fixtures via a private helper method (`loadJsonFixture`, `loadSseFixture`) on the test class — NOT raw `file_get_contents` in the test body. The helper approach (a) keeps a single point for decoder options like `JSON_THROW_ON_ERROR`, (b) silences `test-quality.mystery-guest` which only walks test-method scopes (see [[gruff-php]] pattern bucket), and (c) makes the test body read as `$data = $this->loadJsonFixture('wire-contract/invoke-response-tools-full.json')`. Add new fixtures for new response shapes rather than embedding large JSON/SSE payloads in test methods.

## Pattern: Classify wire-contract fixtures before parsing

**Context:** When adding executable coverage for `tests/Fixtures/wire-contract/`.

**Approach:** Route fixtures by filename and contract role before selecting a parser: `invoke-response-*.json` through `AgentResponse::fromArray()`, `invoke-request-*.json` as request envelopes, `stream-*.sse` through `StreamParser`, `error-response.json` as structured error JSON, and discovery/metadata fixtures as structured contract objects. Do not apply one invariant, such as non-empty response text, across every fixture class.
