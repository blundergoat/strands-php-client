# Strands PHP Client - Codex Instructions

PHP client library for consuming Strands Agents over HTTP. It supports `invoke()`, SSE streaming, custom JSON/SSE endpoints, authentication strategies, retry logic, and Laravel/Symfony integrations.

Core invariant: `HttpTransport` is an interface. Do not add default method bodies or turn it into an abstract class.

Contract invariant: this client targets the Strands HTTP Wire Contract v1 (`docs/wire-contract.md`, `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md`), not raw sdk-python `TypedDict` shapes.

## Goat-flow harness

This project uses goat-flow (v1.13.0). For Claude-specific scope and the full execution loop, see `CLAUDE.md`. Architecture, code map, and glossary live under `.goat-flow/`. The learning loop (footguns / lessons / patterns / decisions) is under `.goat-flow/learning-loop/` — grep before every change.

## Workspace Boundary

The controlling GOAT Flow workspace may differ from the selected target project. Treat `.goat-flow/` in the controlling workspace as process state, skills/reference material, and learning-loop storage; treat the selected target project as the source of code evidence and implementation changes. Use target-scoped commands such as `git -C <target> status` when the two are not obviously the same directory.

## Truth Order

1. The user's explicit instruction for this session.
2. This instruction file.
3. `.goat-flow/architecture.md` and `.goat-flow/code-map.md`.
4. `.goat-flow/learning-loop/decisions/` (ADRs including the wire-contract ADR).
5. Project docs in `README.md`, `CONTRIBUTING.md`, and `docs/`.
6. GOAT skills/reference files loaded on demand.

## Autonomy Tiers

### Always

Read relevant files before changes, search learning-loop notes, edit within the requested scope, run focused validation, and keep changes small.

### Ask First

Before crossing these boundaries, state the boundary, related code read, footgun checked, local instruction checked, and rollback command: instruction files, package/config files, CI/hooks, framework integrations, public interfaces, wire-contract shapes, and public class/method/namespace add/remove/rename.

### Never

Do not edit secrets or credential files. Do not push, commit, run destructive git commands, or overwrite files without checking existing content first. Freeze writes if interrupted or told "no changes."

## Hard Rules

- If a file exists, modify it in place. Do not create `_modified`, `_new`, `_backup`, or `_v2` variants.
- Severity order: SECURITY > CORRECTNESS > INTEGRATION > PERFORMANCE > STYLE.
- Every PHP file must use `declare(strict_types=1);`.
- Use `readonly` promoted DTO properties and defensive `fromArray()` parsing.
- Use fully qualified PHPDoc array shapes such as `array<string, string>`; avoid bare `array` and `mixed` unless justified.
- `AgentContext` style builders are immutable clone-and-mutate APIs.
- Use PSR-3 logs with snake_case context arrays.
- Stream callbacks compare cancellation with `=== false`.
- Do not add dependencies without updating Composer metadata and docs as needed.

## Key Resources

- Architecture: `.goat-flow/architecture.md`
- Code map: `.goat-flow/code-map.md`
- Glossary: `.goat-flow/glossary.md`
- Learning loop: `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, `.goat-flow/learning-loop/decisions/`
- Tool playbooks: `.goat-flow/skill-docs/playbooks/` — read the matching playbook before declaring a tool unavailable.
- Wire contract: `docs/wire-contract.md`, `tests/Fixtures/wire-contract/`
- Main docs: `README.md`, `CONTRIBUTING.md`, `docs/usage-guide.md`, `docs/auth.md`, `docs/laravel-config.md`, `docs/symfony-config.md`

## Essential Commands

```bash
composer install
composer test
composer cs:check
composer cs:fix
composer analyse
composer analyse:messdetector
composer analyse:complexity
composer preflight
vendor/bin/phpunit tests/Unit/StrandsClientTest.php
vendor/bin/phpunit --filter testInvokeReturnsResponse
```

## Execution Loop

### READ

MUST read relevant files before changes. Never fabricate codebase facts. Search `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, and `.goat-flow/learning-loop/decisions/` before code changes. Before declaring any tool or capability unavailable, read the matching playbook in `.goat-flow/skill-docs/playbooks/` (e.g. `browser-use.md`, `page-capture.md`) and run that doc's "Availability Check" section verbatim — project-local CLI tools at `~/.local/bin/` are valid; do not conflate "no harness/MCP tool" with "no tool".

### SCOPE

Declare intent, complexity tier, files allowed to change, non-goals, and blast radius before writes. If scope expands, stop and re-scope.

### ACT

Declare `State: [MODE] | Goal: [one line] | Exit: [condition]` when using GOAT skills. Match existing patterns, keep edits narrow, and preserve unrelated user changes.

### VERIFY

Run focused checks for changed files. Do not claim checks passed without the literal pass/fail line from this session. Check cross-references after renames. If verification catches a recurring trap, update the learning loop before DoD.

**Hallucination red-flags:**

1. **Checks passed.** Do not claim tests pass or any check passed (`composer test`, `composer analyse`, `composer preflight`, audit) without showing the literal pass/fail line copied verbatim from this session's run. Paraphrase, cached output, or prior-session results do not count.
2. **Completion.** Do not claim completion without listing the specific files changed in this turn. If no files were changed, say so explicitly.
3. **Fix verification.** Do not claim a fix works without running the reproduction steps that originally demonstrated the bug. "Looks correct" is not verification.
4. **Hedged claims.** Do not use "should work", "probably fine", "looks good" as verification. These are guesses, not evidence.

Rationalisations to reject — see `.goat-flow/skill-docs/skill-preamble.md` ("Rationalisations to reject" table) for the canonical Excuse / Reality pairs.

## Definition of Done

Confirm the relevant gates: tests or focused checks pass, formatting/static analysis is addressed, cross-references resolve, no unapproved boundary changes remain, learning-loop notes are updated when needed, and old patterns are grepped after renames.

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

| Surface | Path |
| --- | --- |
| Skill reference (meta) | `.goat-flow/skill-docs/` |
| Tool playbooks (CLI/MCP availability checks: browser-use, page-capture, skill-quality-testing) | `.goat-flow/skill-docs/playbooks/` — read BEFORE declaring a tool unavailable |
| Learning loop | `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, `.goat-flow/learning-loop/decisions/` |
| Architecture | `.goat-flow/architecture.md` |
| Code map | `.goat-flow/code-map.md` |
| Glossary | `.goat-flow/glossary.md` |
| Claude instruction file | `CLAUDE.md` |
| Claude skills + harness | `.claude/skills/`, `.claude/settings.json`, `.goat-flow/hooks/` |
| Wire contract | `docs/wire-contract.md`, `tests/Fixtures/wire-contract/`, `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md` |
| Source code | `src/` |
| Tests | `tests/` |
| Documentation | `README.md`, `CONTRIBUTING.md`, `docs/` |
| Commit guidance | `.github/git-commit-instructions.md` |

## PHP Project Patterns

Imports are alphabetical in groups: PHP built-ins, third-party, then `StrandsPhpClient\...`. Code style is PSR-12 with short arrays, single-quoted strings, trailing commas in multiline constructs, blank lines before returns, and no unused imports.

Use custom exceptions: `StrandsException`, `AgentErrorException`, and `StreamInterruptedException`. Pass structured agent error bodies via `responseBody` where available and document thrown exceptions in PHPDoc.

Tests live under `tests/Unit/`, mirror `src/`, use mocked HTTP only, and follow Arrange-Act-Assert. Test names describe feature and scenario, for example `testInvokeRetriesOnRetryableStatusCode`.
