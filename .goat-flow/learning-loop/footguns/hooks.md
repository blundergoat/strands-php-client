---
category: hooks
last_reviewed: 2026-08-08
---

# Hooks Footguns

## Footgun: `run-with-bash.mjs` is deliberately patched to bound hook runtime

**Status:** active | **Created:** 2026-08-08 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** When `goat-flow audit` reports drift on this file, do not restore it from the template; re-apply the timeout instead.
**Trigger phase:** VERIFY

**Symptoms:** Before the patch, a wedged hook froze the agent session with no output and no recovery path. The launcher runs the hook with `stdio: "inherit"`, so the call blocks the tool invocation until the hook exits, and the shipped template passed no `timeout`.

**Why it happens:** The template's hook `spawnSync` (search: `const hookExecution = spawnSync`) omitted `timeout`, while the same file already establishes the pattern for its Windows path lookup (search: `timeout: 5000`). The `gruff` and `post-turn` modes start analyzers, which can hang on a stuck child process.

**Evidence:** the patch adds `GOAT_FLOW_HOOK_LAUNCH_TIMEOUT_MS` defaulting to 75000, chosen to sit above the hooks' own 60s budgets (`GOAT_FLOW_POST_TURN_SAFETY_MAX_SECONDS`, `GRUFF_CODE_QUALITY_TIMEOUT_SECONDS`) so they still bail gracefully, and below the 90s host timeout in `.claude/settings.json` so the fail-closed reason reaches the user rather than a silent kill. Verified both directions: a benign command still exits 0, and `GOAT_FLOW_HOOK_LAUNCH_TIMEOUT_MS=1` produces `BLOCKED: Policy hook unavailable: hook exceeded 1ms and was killed` with exit 2.

**Prevention:** Keep the patch through any `goat-flow install` or hook sync. Node reports a timeout as `error.code === "ETIMEDOUT"` with a null status, which the launcher's existing unavailable path already treats as fail-closed for `policy` and `post-turn`.

## Footgun: `post-turn-safety.sh` is deliberately patched away from the published template

**Status:** active | **Created:** 2026-08-08 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** When `goat-flow audit` reports drift on this one hook, do NOT restore it from the template. The drift is intentional and the template is the broken side.
**Trigger phase:** VERIFY
**hallucination-risk:** high

**Symptoms:** `goat-flow audit . --harness` returns `overall: fail` with a single drift finding — `hook template (workflow/hooks/post-turn-safety.sh) and installed copy differ` — and `.goat-flow/install-state/*.json` records a sha256 for this file that no longer matches. Everything else in the audit passes.

**Why it happens:** The published `@blundergoat/goat-flow` 1.15.0 tarball ships a `main()` whose three infrastructure-failure paths `return 1` instead of `2`. Because `main "$@"` is the last statement, that return becomes the script's exit code, and a Stop hook exiting 1 is non-blocking — so when the safety scan cannot run at all, the turn ends looking clean. This contradicts the same function's own budget-exhaustion path, which returns 2 under the comment `An incomplete native scan must block instead of showing a clean turn`, and contradicts the hook's registration wrapper in `.claude/settings.json`, whose `reportUnavailable` exits 2. This repo patched the three returns to 2 on 2026-08-08, which is what the audit now flags.

**Evidence:** reproduced twice — outside a git repository, and with a dirty worktree plus an unwritable `TMPDIR` so `mktemp -d` fails; the shipped script exited 1 with `post-turn-safety: cannot create scan work directory; cannot scan changed content.` The published tarball for 1.15.0 (md5 `b99a333872d46ef3ffef45aa9373a8ad`) carries the `return 1` form, so the fault is in the release, not in this checkout. Restoring from the template reintroduces it.

**Prevention:** Keep the patch. Re-apply it after any `goat-flow install`, `npm update`, or hook sync, and re-run the two reproductions before trusting a green turn. Treat drift on this specific path as expected until a goat-flow release ships the fail-closed returns; drift on any *other* managed hook is still a real finding. Note that a same-version unpublished build of 1.15.0 (md5 `5f6756b80ae70f4dd2b09c6576a290dd`) does contain the fix, so "the template has it" depends entirely on which build is installed — check the bytes, not the version string.

## Footgun: Hook policy fixes regress silently unless the self-test encodes them

**Status:** active | **Created:** 2026-07-07 | **Evidence:** ACTUAL_MEASURED

**Trap.** The deny-dangerous guard logic has been rewritten/consolidated across
locations (per-runtime copies in `.claude/hooks/` / `.codex/hooks/` → shared
`.goat-flow/hooks/deny-dangerous.sh` + `.goat-flow/hooks/deny-dangerous/`).
Any bypass fix applied to the guard scripts but NOT encoded as a
`deny-dangerous-self-test.sh` case is lost the next time the logic is rebuilt,
and nothing fails. PR #6 review fixes marked "Addressed" (CodeRabbit) regressed
exactly this way and went unnoticed until re-measured on 2026-07-07:

- Bare-`&` chaining: `echo ok & rm -rf /` → exit 0 (allowed) because
  `split_command_segments_into` (`.goat-flow/hooks/deny-dangerous.sh`, search:
  `split_command_segments_into`) split only on `&&`/`||`/`;`/newline. Fixed by
  adding a bare-`&` branch (search: `Bare & (background/job control)`) that
  leaves `2>&1`, `>&2`, `&>file` unsplit.
- No-space lockfile redirect: `echo x>package-lock.json` → exit 0 (allowed)
  because `lockfile_write_re` (`.goat-flow/hooks/deny-dangerous/patterns-shell.sh`,
  search: `lockfile_write_re`) required `[[:space:]]+` after the redirect
  operator. Fixed with `[[:space:]]*`.

Both now exit 2, verified via `--check` runs and the full self-test
(`PASS: deny-dangerous self-test (mode=full, executed=288, skipped=0)`).

**Rule.** Every closed bypass MUST land with matching `expect_block` /
`expect_allow` cases in
`.goat-flow/hooks/deny-dangerous/deny-dangerous-self-test.sh` in the same
change (see search: `ampersand chained rm` and search:
`no-space lockfile redirect write`). The self-test is the only regression net
these scripts have; a guard fix without a self-test case is temporary. When
consolidating or rewriting hook logic, diff the old copies' guard patterns and
re-run the self-test before deleting them.
