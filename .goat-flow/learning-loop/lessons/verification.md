---
category: verification
last_reviewed: 2026-07-04
---

## Lesson: Verify Generated PHPDoc Formatting And Phrasing

**Created:** 2026-05-24
**What happened:** While fixing Gruff `docs.missing-public-phpdoc` findings, the first automated PHPDoc insertion pass left double-indented docblocks and awkward summaries such as "Create create mock transport" before a grep and snippet review caught it.
**Evidence:** Generated docblocks in `tests/Unit/Streaming/StreamCallbackHandlerTest.php` (search: `Verifies that text event dispatches to onText`) and `tests/Unit/StrandsClientPostJsonTest.php` (search: `Create mock transport for the test scenario`) were re-generated after the bad phrasing/indentation was found.
**Prevention:** After bulk-generating comments, grep for repeated verb patterns (`Create create`, `Load load`, `No value is returned`) and open representative source/test snippets before trusting the analyzer count alone. When replacing an existing docblock, replace from the line start and derive indentation from the following declaration line, not from the doc comment start.

## Lesson: Patch Repeated Assertions By Semantic Context

**Created:** 2026-05-24
**What happened:** While updating `Usage` tests for numeric-string parsing, repeated `$response->usage->outputTokens` assertions caused the wrong test expectations to be patched twice before the focused test run caught it.
**Evidence:** `tests/Unit/AgentResponseTest.php` (search: `testFromArrayHandlesNonIntUsageValues`) contains nearby repeated output-token assertions with different expected values.
**Prevention:** When changing repeated assertions, patch within the specific test method block or re-open the edited region before running tests. Do not rely on the first matching assertion text.

## Lesson: Verify automated-reviewer "addressed" tags against the file

**Created:** 2026-05-24
**What happened:** While triaging CodeRabbit feedback on PR #6, a comment about an SSE-fixture extension claim (`sse-*.txt` vs `.sse`) carried the trailing annotation "✅ Addressed in commit 6299334". I initially treated this as resolved and put it in the "disagree / already fixed" pile. A second-pass read of `.goat-flow/learning-loop/patterns/testing.md` (search: `SSE fixtures (\`*.sse\``) showed the doc had only been partially updated — the annotation referred to a different change in that commit, not to the full doc fix. (Today the file correctly cites `*.sse`; the prior in-flight state cited `sse-*.txt`.)

**Evidence:** The CodeRabbit thread on `.goat-flow/learning-loop/patterns/testing.md` carried "✅ Addressed in commit 6299334" but the file contents at that point still contained the original `sse-*.txt` text until this PR's follow-up fix. Compare current `.goat-flow/learning-loop/patterns/testing.md` (search: `SSE fixtures (\`*.sse\``) to the historical state.

**Prevention:** Treat reviewer annotations as hints, never as verification. Before dismissing any finding as "already fixed", open the file at the cited line and confirm the current contents. This applies equally to CodeRabbit, Codex, Copilot, and any future tool — the annotation describes intent (or a sibling change), not the current state of the file.

## Lesson: Smoke-Test Manually Authored Hook Commands Before Audit

**Created:** 2026-07-04
**What happened:** While registering the Antigravity deny hook, the first `.agents/hooks.json` command string started `bash -c '` but missed the final closing quote. Aggregate harness audit could still detect the registered path, but the Antigravity-specific runtime smoke failed with `unexpected EOF while looking for matching \`''`.
**Evidence:** `.agents/hooks.json` (search: `"deny-dangerous"`) was corrected after the configured-command smoke failed; `.goat-flow/plans/antigravity-deny-hook/M01-register-antigravity-deny-hook.md` (search: `Runtime smoke shows`) records the verification target.
**Prevention:** For manually authored agent hook registrations, run the configured command through the same `printf %s "$GOAT_HOOK_SMOKE_PAYLOAD" | { <command>; }` wrapper used by goat-flow audit before trusting a structural registration pass.
