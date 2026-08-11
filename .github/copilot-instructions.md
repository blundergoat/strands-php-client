# Strands PHP Client - Copilot Instructions

PHP 8.2+ client library for consuming Strands Agents over HTTP. Core invariant: `HttpTransport` is an interface. Contract invariant: this client targets Strands HTTP Wire Contract v1, not raw sdk-python `TypedDict` shapes.

**Goat-flow version:** 1.15.1

## Workspace Boundary

The controlling goat-flow workspace lives in `node_modules/@blundergoat/goat-flow/`. The selected target project is this repository root. Adapt commands and paths from the target project; do not echo controlling-workspace paths into installed surfaces.

## Truth Order

1. User's explicit instruction for this session.
2. This instruction file.
3. `.goat-flow/architecture.md`, `.goat-flow/code-map.md`, `.goat-flow/glossary.md`.
4. `.goat-flow/learning-loop/decisions/` ADRs, including the wire-contract ADR.
5. Project docs in `README.md`, `CONTRIBUTING.md`, and `docs/`.
6. Peer instructions in `AGENTS.md` and `CLAUDE.md`.

## Autonomy Tiers

**Always:** read relevant files before changes, search learning-loop notes, edit within declared scope, preserve unrelated user changes, and run focused validation.

**Ask First:** before touching instruction files, package/config files, CI/hooks, framework integrations, public interfaces, wire-contract shapes, public class/method/namespace add/remove/rename, or 3+ file moves/deletes. State boundary, related code read, footgun checked, local instruction checked, and rollback command.

**Never:** edit secrets or credential files. Do not push, commit, run destructive git commands, or overwrite files without checking existing content first. Freeze writes if interrupted or told no changes.

## Hard Rules

- Modify files in place; do not create `_modified`, `_new`, `_backup`, or `_v2` variants.
- Severity order: SECURITY > CORRECTNESS > INTEGRATION > PERFORMANCE > STYLE.
- Every PHP file uses `declare(strict_types=1);`.
- Use readonly promoted DTO properties and defensive `fromArray()` parsing.
- Use fully qualified PHPDoc array shapes such as `array<string, string>`.
- `AgentContext` style builders are immutable clone-and-mutate APIs.
- Use PSR-3 logs with snake_case context arrays.
- Stream callbacks compare cancellation with `=== false`.
- Do not add dependencies without updating metadata and docs as needed.

## Commit Messages

Recent history is mixed: conventional commits are common but not universal. Prefer clear conventional-commit subjects when committing is explicitly requested, and see `docs/coding-standards/git-commit-message.md` before preparing commit text.

## Key Resources

- Architecture: `.goat-flow/architecture.md`
- Code map: `.goat-flow/code-map.md`
- Glossary: `.goat-flow/glossary.md`
- Learning loop: `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, `.goat-flow/learning-loop/decisions/`
- Tool playbooks: `.goat-flow/skill-docs/playbooks/README.md` is the full index; examples include `.goat-flow/skill-docs/playbooks/browser-use.md` and `.goat-flow/skill-docs/playbooks/page-capture.md`
- Wire contract: `docs/wire-contract.md`, `tests/Fixtures/wire-contract/`, `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md`

## Essential Commands

```bash
composer install
composer test
composer cs:check
composer analyse
composer analyse:messdetector
composer analyse:complexity
composer preflight
.goat-flow/hooks/deny-dangerous/deny-dangerous-self-test.sh
vendor/bin/phpunit tests/Unit/StrandsClientTest.php
vendor/bin/phpunit --filter testInvokeReturnsResponse
```

## Execution Loop: READ -> SCOPE -> ACT -> VERIFY

When a goat-* skill is active, its Step 0 replaces READ and selects the skill's mode/depth. SCOPE still applies before writes. Resume at ACT after Step 0 output or when a blocking gate releases.

### READ

MUST read relevant files before changes. Never fabricate codebase facts. Search `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, and `.goat-flow/learning-loop/decisions/` before code changes. Before declaring any tool or capability unavailable, read the matching playbook in `.goat-flow/skill-docs/playbooks/` (e.g. `browser-use.md`, `page-capture.md`) and run that doc's "Availability Check" section verbatim - project-local CLI tools at `~/.local/bin/` are valid; do not conflate "no harness/MCP tool" with "no tool".

### SCOPE

Declare intent, complexity tier, mode, files allowed to change, non-goals, and blast radius before writes. If scope expands, stop and re-scope.

### ACT

Declare `State: [MODE] | Goal: [one line] | Exit: [condition]`. Match existing patterns, keep edits narrow, and preserve unrelated user changes.

### VERIFY

Run focused checks for changed files. Check cross-references after renames. Do not claim checks passed without the literal pass/fail line from this session. Stop the line on broken tests, build failures, or behaviour regressions.

**Hallucination red-flags:**

1. **Checks passed.** Do not claim tests, audit, or analysis passed without showing the literal pass/fail line copied from this session.
2. **Completion.** Do not claim completion without listing the specific files changed in this turn. If no files changed, say so.
3. **Fix verification.** Do not claim a fix works without running the reproduction steps that originally demonstrated the bug.
4. **Hedged claims.** Do not use "should work", "probably fine", or "looks good" as verification.

Rationalisations to reject live in `.goat-flow/skill-docs/skill-preamble.md`.

## Definition of Done

Confirm focused checks pass, formatting/static analysis is addressed, cross-references resolve, no unapproved boundary changes remain, and learning-loop notes are updated when verification catches a recurring trap.

## Artifact Routing

| Artifact | Destination |
| --- | --- |
| Footgun or code trap | `.goat-flow/learning-loop/footguns/` |
| Lesson from an agent mistake | `.goat-flow/learning-loop/lessons/` |
| Architecture or policy decision | `.goat-flow/learning-loop/decisions/` |
| Reusable implementation or testing pattern | `.goat-flow/learning-loop/patterns/` |
| Session continuity note | `.goat-flow/logs/sessions/` |
| Scratch work | `.goat-flow/scratchpad/` |

Read the destination directory's `README.md` before editing GOAT Flow artifacts.

## Router Table

| Resource | Path |
| --- | --- |
| Instruction file | `.github/copilot-instructions.md` |
| Learning loop | `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, `.goat-flow/learning-loop/decisions/` |
| Skill reference (meta) | `.goat-flow/skill-docs/` |
| Skill playbooks (tools) | `.goat-flow/skill-docs/playbooks/` |
| Architecture | `.goat-flow/architecture.md` |
| Orientation | `.goat-flow/code-map.md`, `.goat-flow/glossary.md` |
| Copilot skills/config | `.github/skills/`, `.github/hooks/`, `docs/coding-standards/git-commit-message.md` |
| Peer instructions | `AGENTS.md`, `CLAUDE.md` |
| Source | `src/` |
| Tests | `tests/` |
| Documentation | `README.md`, `CONTRIBUTING.md`, `docs/` |
| Workspace notes | `.goat-flow/logs/sessions/`, `.goat-flow/plans/`, `.goat-flow/scratchpad/` |
