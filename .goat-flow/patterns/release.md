---
category: release
last_reviewed: 2026-05-24
---

## Pattern: Changelog Delta Against origin/main

**Created:** 2026-05-24

**Context:** When updating `CHANGELOG.md` for "everything on this branch that is not in `origin/main`."

**Approach:** Check both committed branch history and the dirty worktree before writing release notes. Use `git log --oneline origin/main..HEAD` for committed intent, `git diff --name-status origin/main -- .` for tracked file changes, and `git ls-files --others --exclude-standard` for untracked files that belong to the current branch work. If the worktree is dirty, explicitly decide whether those uncommitted files are in scope before summarizing the branch.

**Evidence:** `CHANGELOG.md` (search: `## [1.5.0] - Unreleased`) currently documents committed branch work plus uncommitted 1.5.0 bridge files. `examples/python-gateway/README.md` (search: `Strands Python Gateway Template`) and `src/Http/ResponseObserver.php` (search: `interface ResponseObserver`) were untracked during changelog reconciliation, so relying only on commit history would have missed them.
