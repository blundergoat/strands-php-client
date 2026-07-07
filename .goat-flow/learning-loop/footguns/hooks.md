---
category: hooks
last_reviewed: 2026-07-07
---

# Hooks Footguns

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
