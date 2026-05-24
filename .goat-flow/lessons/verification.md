---
category: verification
last_reviewed: 2026-05-24
---

## Lesson: Patch Repeated Assertions By Semantic Context

**Created:** 2026-05-24
**What happened:** While updating `Usage` tests for numeric-string parsing, repeated `$response->usage->outputTokens` assertions caused the wrong test expectations to be patched twice before the focused test run caught it.
**Evidence:** `tests/Unit/AgentResponseTest.php` (search: `testFromArrayHandlesNonIntUsageValues`) contains nearby repeated output-token assertions with different expected values.
**Prevention:** When changing repeated assertions, patch within the specific test method block or re-open the edited region before running tests. Do not rely on the first matching assertion text.

## Lesson: Verify automated-reviewer "addressed" tags against the file

**Created:** 2026-05-24
**What happened:** While triaging CodeRabbit feedback on PR #6, a comment about an SSE-fixture extension claim (`sse-*.txt` vs `.sse`) carried the trailing annotation "✅ Addressed in commit 6299334". I initially treated this as resolved and put it in the "disagree / already fixed" pile. A second-pass read of `.goat-flow/patterns/testing.md` (search: `SSE fixtures (\`*.sse\``) showed the doc had only been partially updated — the annotation referred to a different change in that commit, not to the full doc fix. (Today the file correctly cites `*.sse`; the prior in-flight state cited `sse-*.txt`.)

**Evidence:** The CodeRabbit thread on `.goat-flow/patterns/testing.md` carried "✅ Addressed in commit 6299334" but the file contents at that point still contained the original `sse-*.txt` text until this PR's follow-up fix. Compare current `.goat-flow/patterns/testing.md` (search: `SSE fixtures (\`*.sse\``) to the historical state.

**Prevention:** Treat reviewer annotations as hints, never as verification. Before dismissing any finding as "already fixed", open the file at the cited line and confirm the current contents. This applies equally to CodeRabbit, Codex, Copilot, and any future tool — the annotation describes intent (or a sibling change), not the current state of the file.
