---
category: verification
last_reviewed: 2026-08-10
---

## Lesson: A Passing Checker Can Mean Unchecked, Not Clean

**Created:** 2026-08-08
**Decision changed:** When a validator reports zero problems over content that looks suspect, confirm the validator actually parsed that content before treating the pass as evidence.
**Trigger phase:** VERIFY
**What happened:** A quality assessment found six learning-loop entries citing `vendor/blundergoat/gruff-php/...` paths that do not exist in this checkout, while `goat-flow stats --check` reported `totalStaleRefs: 0`. The natural reading was that the checker had no stale-ref rule. It does — adding one new entry whose citation used the two-span anchor form made the identical class of dead path fail the check on the next run. The six pre-existing entries had simply been written in a form the scanner does not resolve, so they were never checked at all.
**Evidence:** `.goat-flow/learning-loop/footguns/learning-loop.md` (search: `validates only one semantic-anchor form`) records the mechanism and both anchor forms.
**Prevention:** Treat a green validator over doubtful content as a hypothesis, not a result. Prove the rule exists by making it fire once — a deliberate violation that the checker catches converts "no findings" from ambiguous into meaningful. Then fix the violation rather than restyling it into the form the checker ignores.

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
**Evidence:** `.agents/hooks.json` (search: `"deny-dangerous"`) contains the corrected registered command exercised by the configured-command smoke.
**Prevention:** For manually authored agent hook registrations, run the configured command through the same `printf %s "$GOAT_HOOK_SMOKE_PAYLOAD" | { <command>; }` wrapper used by goat-flow audit before trusting a structural registration pass.

## Lesson: Release Closeout Evidence Goes Stale — Re-Verify Gates And CHANGELOG At HEAD Before Tagging

**Created:** 2026-07-05
**What happened:** The 1.5.0 closeout (M11) ran its gates and wrote the CHANGELOG on 2026-05-24, but nine later commits (PHPDoc pass, Symfony `^8.0` constraint widening, test refactors) landed before any tag existed. At HEAD, `composer preflight` failed (PHPMD: `StrandsClient` at 1001 lines vs the 1000 threshold) and the CHANGELOG's "593 tests, 1827 assertions" no longer matched the suite. Separately, two M01 checkboxes (the `class_exists` constructor guard and its swallow test) were ticked although `git log -S` showed the code never existed in any commit.
**Evidence:** `CHANGELOG.md` (search: `## [1.5.0]`) is the tracked release record whose claims and counts require fresh verification at the tag commit; its header date moves with the tag day, so cite the version, not the date.
**Prevention:** Release readiness is a property of the tag commit, not of the closeout session — re-run `composer preflight` and re-check every CHANGELOG count/claim at the exact commit being tagged. And a ticked checkbox requires an artifact greppable in the tree; tick with the artifact name, never from intent.

## Lesson: Verify Both Git Index And Working Tree After Tool-Driven Updates

**Created:** 2026-08-08
**Decision changed:** After tool-driven updates, inspect and validate both staged and unstaged diffs before claiming complete change coverage.
**Trigger phase:** VERIFY
**What happened:** The goat-flow installer staged Codex and shared harness changes while Claude and Copilot changes remained unstaged. The first `git diff --check` covered only the working tree, so staged whitespace needed a separate check.
**Prevention:** After any installer or generator runs, capture `git status --short`, run both `git diff --check` and `git diff --cached --check`, and inspect both name-status views before asserting path or whitespace integrity.

## Lesson: Put A Known-Bad Case In Every Probe Of A Guard

**Created:** 2026-08-10
**Decision changed:** When probing a policy hook, linter, or any other checker, include a case whose verdict is already known and read that case first. A probe whose controls come back wrong is measuring the harness, not the guard.
**Trigger phase:** VERIFY
**What happened:** While reviewing PR #6, a battery of git commands was piped into `.goat-flow/hooks/deny-dangerous.sh` as `bash .goat-flow/hooks/deny-dangerous.sh policy < payload.json`, mirroring the `"policy"` argument that appears in the host hook command. Every command returned exit 0. The run looked like a clean all-clear across the whole destructive-git surface. The controls in the same batch — `git push`, `git commit`, `rm -rf /` — also returned exit 0, which is what exposed the mistake. `main()` routes an unrecognised positional into `CHECK_COMMAND`, so the hook had evaluated the literal string `policy`, found it harmless, and never read the payload. The mode argument belongs to `run-with-bash.mjs` and is not forwarded to the shell script.
**Evidence:** `.goat-flow/hooks/deny-dangerous.sh` (search: `unexpected argument`) now fails closed when a bare argument arrives alongside a payload on stdin; `.goat-flow/hooks/run-with-bash.mjs` (search: `spawnSync(`) shows the launcher passing only the hook script path.
**Prevention:** Every probe of a checker carries at least one input the checker must reject. Read the control lines before the findings; identical verdicts across controls and subjects mean the harness is wrong. For goat-flow hooks specifically, drive them the way a host does — no positional arguments, payload on stdin — or use the documented `--check=<command>` form.
