---
category: release
last_reviewed: 2026-08-08
---

## Pattern: Changelog Delta Against origin/main

**Created:** 2026-05-24

**Context:** When updating `CHANGELOG.md` for "everything on this branch that is not in `origin/main`."

**Approach:** Check both committed branch history and the dirty worktree before writing release notes. Use `git log --oneline origin/main..HEAD` for committed intent, `git diff --name-status origin/main -- .` for tracked file changes, and `git ls-files --others --exclude-standard` for untracked files that belong to the current branch work. If the worktree is dirty, explicitly decide whether those uncommitted files are in scope before summarizing the branch.

**Evidence:** `CHANGELOG.md` (search: `## [1.5.0] - 2026-07-17`) records the completed release delta. `examples/python-gateway/README.md` (search: `Strands Python Gateway Template`) and `src/Http/ResponseObserver.php` (search: `interface ResponseObserver`) show tracked surfaces that branch-history-only reconciliation could have missed while they were uncommitted.
