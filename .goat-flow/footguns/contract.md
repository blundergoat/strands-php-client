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
