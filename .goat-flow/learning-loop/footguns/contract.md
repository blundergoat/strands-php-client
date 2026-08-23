---
category: contract
last_reviewed: 2026-05-24
---

## Footgun: Interrupt invoke responses may have empty text

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** high

**Symptoms:** Fixture smoke tests or parser assertions fail when they assume every `invoke-response-*.json` fixture must contain non-empty `text`.

**Why it happens:** Interrupt responses represent control flow, not final assistant text. The canonical fixture `tests/Fixtures/wire-contract/invoke-response-interrupt.json` (search: `"stop_reason": "interrupt"`) intentionally has `"text": ""` while carrying `interrupts`.

**Evidence:** `src/Response/AgentResponse.php` (search: `text: is_string`) preserves the text field exactly and `tests/Fixtures/wire-contract/invoke-response-interrupt.json` (search: `"text": ""`) documents the valid empty-text shape.

**Prevention:** Contract tests should assert that parsed response text matches the fixture value, not that it is non-empty. Use `stop_reason` and `interrupts` to identify interrupt responses.

## Footgun: Media content blocks need a top-level `format` field

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** high

**Symptoms:** A new `AgentInput::with*` helper for an image, document, or video block looks correct in tests (source structure matches, media_type matches) but the wrapper rejects the payload, or accepts it inconsistently across base64/url/s3 source types. The bug is invisible to wrapper-agnostic unit tests.

**Why it happens:** The Strands HTTP Wire Contract v1 puts `format` at the BLOCK level — alongside `type` and `source` — separate from `source.media_type`. It is easy to assume `source.media_type` is sufficient because base64 and url sources include it; s3 sources don't have a `media_type` at all, which is why `format` is required at the block level instead.

**Evidence:** `docs/wire-contract.md` (search: `Required fields`) lists `format, source` as required for `image`, `document`, and `video`. The canonical request fixture `tests/Fixtures/wire-contract/invoke-request-rich-input.json` (search: `"type": "image"`) shows `format` as a top-level block key. Three `AgentInput` helpers previously omitted it (`withImage`, `withImageFromUrl`, and the document/video URL variants); they now derive `format` from the media type. See `src/Context/AgentInput.php` (search: `deriveImageFormat`).

**Prevention:** Every new media-block helper MUST add a `format` key at the same level as `type` and `source`. When the user supplies a MIME type only, derive `format` (e.g. `image/png` -> `png`). Add a positive test that asserts `payload['content'][N]['format']` for each new helper.
