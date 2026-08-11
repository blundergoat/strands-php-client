---
category: hooks
last_reviewed: 2026-08-11
---

# Hooks Footguns

## Footgun: the deny-dangerous git guard is deliberately patched away from the published template

**Status:** active | **Created:** 2026-08-11 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** When `goat-flow audit` or `hooks list` reports stale or drifted deny-dangerous files, do not run `goat-flow hooks sync` and do not reinstall with `--force`. Both restore registry bytes and delete the guard.
**Trigger phase:** VERIFY
**hallucination-risk:** high

**Symptoms:** `goat-flow audit . --harness` reports `Skill Template Drift: FAIL` naming `.goat-flow/hooks/deny-dangerous.sh`, and `hooks list` reports `installation stale` with `installed-version-mismatch` / "Installed hook bytes differ from the bundled registry version" for `deny-dangerous` on all four agents. Every other surface reports `currentVersion=true`. The audit prints a `goat-flow hooks sync` repair command, and following it removes the patch.

**Why it happens:** Three files carry local policy that goat-flow 1.15.1 does not ship. `.goat-flow/hooks/deny-dangerous/patterns-writes.sh` (search: `is_git_destructive`) replaces the published three-rule check (`--no-verify`, `reset --hard`, `clean -f`) with a subcommand table covering `checkout`, `switch`, `restore`, `rebase`, `merge`, `cherry-pick`, `revert`, `am`, `pull`, `stash`, `branch -D`, `tag -d`, `reflog expire`, `worktree remove`, and the rest, while leaving read-only evidence and documented dry runs allowed. `.goat-flow/hooks/deny-dangerous/deny-dangerous-self-test.sh` (search: `git checkout pathspec`) asserts each of those rules. `.goat-flow/hooks/deny-dangerous.sh` (search: `unexpected argument`) refuses a bare-word argument when a payload is already waiting on stdin, so a stray word cannot be checked in place of the real command.

The upstream files are the narrower side here, not the broken side: 1.15.1 simply has no equivalent rule. Restoring them re-allows every git command outside the published three, which is the opposite of the repository owner's standing instruction that the user performs all git operations.

**Evidence:** measured on 2026-08-11 against goat-flow 1.15.1. `is_git_destructive` in the shipped `workflow/hooks/deny-dangerous/patterns-writes.sh` matches only `--no-verify`, `^reset .*--hard`, and `^clean .*-f`. With the patch re-applied, `.goat-flow/hooks/deny-dangerous/deny-dangerous-self-test.sh` reports `PASS: deny-dangerous self-test (mode=full, executed=446, skipped=0)`, and `goat-flow hooks verify . --agent <id> --scenario deny-hook` exits 0 for claude, codex, antigravity, and copilot.

**Prevention:** Re-apply all three patches after any `goat-flow install`, `npm update`, or hook sync, then re-run the self-test before trusting the guard. Treat drift on these three paths as expected; drift on any other managed hook is still a real finding. The installer's own preview is the safety net — `goat-flow install . --agent <id> --dry-run` classifies them `both-changed` with `action=protect` and blocks, so an unforced upgrade cannot silently drop them.

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

Both now exit 2, verified via `--check` runs and the full self-test.

**Rule.** Every closed bypass MUST land with matching `expect_block` /
`expect_allow` cases in
`.goat-flow/hooks/deny-dangerous/deny-dangerous-self-test.sh` in the same
change (see search: `ampersand chained rm` and search:
`no-space lockfile redirect write`). The self-test is the only regression net
these scripts have; a guard fix without a self-test case is temporary. When
consolidating or rewriting hook logic, diff the old copies' guard patterns and
re-run the self-test before deleting them.

## Footgun: a duplicated hook registration passes `goat-flow audit` as effective

**Status:** active | **Created:** 2026-08-11 | **Evidence:** OBSERVED
**Decision changed:** After any goat-flow install, upgrade, or hook enable/disable, count the entries in each agent config's hook arrays yourself. A green `hookCoverage` line proves a hook is registered, not that it is registered once.
**Trigger phase:** VERIFY
**hallucination-risk:** high

**Symptoms:** `goat-flow audit . --harness --agent claude` reports `post-turn-safety` as `effective` with `isRegistered: true` and `isCurrentVersionInstalled: true`, while `.claude/settings.json` `hooks.Stop` holds two entries whose commands are byte-identical. The hook runs twice per turn against one registered timeout, and an infrastructure failure inside it keeps re-blocking instead of ending after one provider re-entry. Count by event *and* matcher: three `gruff-code-quality` registrations under `PostToolUse` are correct because their matchers are `Edit`, `Write`, and `Bash`. Two matcher-less entries for one event are not.

**Why it happens:** The audit's per-agent hook record exposes booleans — `isRegistered`, `isTrusted`, `isCurrentVersionInstalled` — and no entry count, so one registration and two produce the same JSON. Nothing in `hookCoverage` or `drift` walks array length. The same shape appears in the launcher's own root probe embedded in each config (search: `registrationNamesOperands`), which returns on its first match.

Duplication is not cosmetic for `post-turn-safety`. `.goat-flow/hooks/post-turn-safety.sh` (search: `finish_infrastructure_failure`) blocks the first infrastructure failure and then ends exactly one unchanged provider re-entry. Two registrations run that guard twice against a single payload: the first call matches the stored fingerprint, calls `clear_stop_reentry_state`, and returns 0; the second finds no state, re-arms it through `write_stop_reentry_state`, and returns 2. The turn blocks again and the bound never expires.

**Evidence:** observed on 2026-08-11 in this checkout against goat-flow 1.15.1. Parsing `.claude/settings.json` gives `hooks.Stop.length === 2` with both entries serialising identically at 5655 characters, while `git show HEAD:.claude/settings.json` gives one — the surplus arrived with the 1.15.1 registration rewrite, which also changed `PreToolUse` and added a third `PostToolUse` matcher. The same session's `goat-flow audit . --harness --agent claude` reported `post-turn-safety` `effectiveState.status: effective`, and `goat-flow install . --agent claude --dry-run` previews 64 managed files of which none is an agent config — no `settings.json` or `hooks.json` entry appears — so registration is outside every preview an upgrade offers. A live `post-turn-safety-reentry-v1-*.state` fingerprint sits in `.goat-flow/scratchpad/`, so the infrastructure-failure path does fire here. The re-entry consequence is traced through the two functions named above, not measured against a forced double failure.

Three of the four agent configs carry the same defect, so this is the registration writer, not one bad edit: `.claude/settings.json` duplicates `Stop`/`post-turn-safety`, `.codex/hooks.json` duplicates `Stop`/`post-turn-safety`, and `.github/hooks/hooks.json` duplicates both `preToolUse`/`deny-dangerous` and `postToolUse`/`gruff-code-quality`. Only `.agents/hooks.json` is clean.

**Prevention:** Treat agent config registration as unaudited. After any install, `npm update`, or hook enable/disable, tally every config by `(event, matcher, script)` and require exactly one — checking Claude alone misses the Codex and Copilot copies. Repair by deleting the surplus entry by hand, keeping the survivor's current launcher command; `JSON.stringify(config, null, 2)` plus a trailing newline reproduces these files byte-for-byte, so a programmatic dedupe changes nothing else. Do not repair with `goat-flow hooks sync`: the entry above this one explains why sync restores registry `deny-dangerous` bytes and deletes the git guard. Under Claude scope, `.codex/` and `.agents/` are read-only — report those to their owner rather than editing them.

## Resolved Entries

## Footgun: `run-with-bash.mjs` is deliberately patched to bound hook runtime

**Status:** resolved | **Created:** 2026-08-08 | **Evidence:** ACTUAL_MEASURED
**Resolution:** goat-flow 1.15.1 ships the bound upstream and reads the same `GOAT_FLOW_HOOK_LAUNCH_TIMEOUT_MS` variable, so the local patch was dropped on 2026-08-11 and `run-with-bash.mjs` is now stock.

Before the patch, a wedged hook froze the agent session with no output and no recovery path: the launcher runs the hook with `stdio: "inherit"`, so the call blocks the tool invocation until the hook exits, and the 1.15.0 template passed no `timeout`. This repository added one defaulting to 75000 ms, chosen to sit above the hooks' own 60s budgets and below the 90s host timeout.

1.15.1 moved the lifecycle into `.goat-flow/hooks/hook-launch-runtime.mjs` (search: `resolveHookLaunchTimeoutMs`), which reads the same variable but validates it against the registered host deadline instead of falling back silently. That is strictly stronger than the local patch: a non-numeric or out-of-range override is now rejected rather than replaced by a default.

**Evidence:** measured on 2026-08-11 against 1.15.1 with the stock launcher. A benign payload exits 0; `GOAT_FLOW_HOOK_LAUNCH_TIMEOUT_MS=1` returns `BLOCKED: Policy hook unavailable: hook exceeded its deadline and was killed.` with exit 2; `GOAT_FLOW_HOOK_LAUNCH_TIMEOUT_MS=abc` returns `BLOCKED: Policy hook unavailable: hook timeout configuration is invalid.` with exit 2. The local patch accepted `abc` and silently used 75000.

## Footgun: `post-turn-safety.sh` is deliberately patched away from the published template

**Status:** resolved | **Created:** 2026-08-08 | **Evidence:** ACTUAL_MEASURED
**Resolution:** goat-flow 1.15.1 returns 2 from every infrastructure-failure path, so the local patch was dropped on 2026-08-11 and `post-turn-safety.sh` is now stock.

The published 1.15.0 tarball shipped a `main()` whose three infrastructure-failure paths returned 1 instead of 2. Because `main "$@"` is the last statement, that return became the script's exit code, and a Stop hook exiting 1 is non-blocking — so when the safety scan could not run at all, the turn ended looking clean. This repository patched the three returns to 2 on 2026-08-08, which the audit then flagged as drift.

**Evidence:** re-measured on 2026-08-11 against the stock 1.15.1 script. Outside any git repository it prints `post-turn-safety: scan incomplete (git repository root unavailable).` and exits 2; with `TMPDIR` pointing at a nonexistent path it prints `post-turn-safety: scan incomplete (scan workspace unavailable).` and exits 2. Both cases exited 1 under 1.15.0. 1.15.1 also adds `post-turn-safety.sh --self-test`, which reports `post-turn-safety self-test: ok`.
