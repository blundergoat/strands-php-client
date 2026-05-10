# Strands PHP Client - Codex Instructions

PHP client library for consuming Strands Agents over HTTP. It supports `invoke()`, SSE streaming, custom JSON/SSE endpoints, authentication strategies, retry logic, and Laravel/Symfony integrations.

Core invariant: `HttpTransport` is an interface. Do not add default method bodies or turn it into an abstract class.

## Workspace Boundary

The controlling GOAT Flow workspace may differ from the selected target project. Treat `.goat-flow/` in the controlling workspace as process state, skills/reference material, and learning-loop storage; treat the selected target project as the source of code evidence and implementation changes. Use target-scoped commands such as `git -C <target> status` when the two are not obviously the same directory.

## Truth Order

1. The user's explicit instruction for this session.
2. This instruction file.
3. `.goat-flow/architecture.md` and `.goat-flow/code-map.md`.
4. Project docs in `README.md`, `CONTRIBUTING.md`, and `docs/`.
5. GOAT skills/reference files loaded on demand.

## Autonomy Tiers

### Always

Read relevant files before changes, search learning-loop notes, edit within the requested scope, run focused validation, and keep changes small.

### Ask First

Before crossing these boundaries, state the boundary, related code read, footgun checked, local instruction checked, and rollback command: instruction files, package/config files, CI/hooks, framework integrations, public interfaces, and public class/method/namespace add/remove/rename.

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
- Learning loop: `.goat-flow/footguns/`, `.goat-flow/lessons/`, `.goat-flow/patterns/`, `.goat-flow/decisions/`
- Tool playbooks: `.goat-flow/skill-reference/` - read the matching playbook before declaring a tool unavailable.
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

MUST read relevant files before changes. Never fabricate codebase facts. Search `.goat-flow/footguns/`, `.goat-flow/lessons/`, `.goat-flow/patterns/`, and `.goat-flow/decisions/` before code changes. Before declaring any tool or capability unavailable, read the matching `.goat-flow/skill-reference/` playbook and run its Availability Check verbatim.

### SCOPE

Declare intent, complexity tier, files allowed to change, non-goals, and blast radius before writes. If scope expands, stop and re-scope.

### ACT

Declare `State: [MODE] | Goal: [one line] | Exit: [condition]` when using GOAT skills. Match existing patterns, keep edits narrow, and preserve unrelated user changes.

### VERIFY

Run focused checks for changed files. Do not claim checks passed without the literal pass/fail line from this session. Check cross-references after renames. If verification catches a recurring trap, update the learning loop before DoD.

## Definition of Done

Confirm the relevant gates: tests or focused checks pass, formatting/static analysis is addressed, cross-references resolve, no unapproved boundary changes remain, learning-loop notes are updated when needed, and old patterns are grepped after renames.

## Artifact Routing

| Artifact | Destination |
| --- | --- |
| Footgun or code trap | `.goat-flow/footguns/` |
| Lesson from an agent mistake | `.goat-flow/lessons/` |
| Architecture or policy decision | `.goat-flow/decisions/` |
| Reusable implementation or testing pattern | `.goat-flow/patterns/` |
| Session continuity note | `.goat-flow/logs/sessions/` |
| Scratch work | `.goat-flow/scratchpad/` |

Read the destination directory's `README.md` before editing GOAT Flow artifacts.

## Router Table

| Surface | Path |
| --- | --- |
| Tool playbooks (CLI/MCP availability checks: browser-use, page-capture, skill-* references) | `.goat-flow/skill-reference/` - read BEFORE declaring a tool unavailable |
| Learning loop | `.goat-flow/footguns/`, `.goat-flow/lessons/`, `.goat-flow/patterns/`, `.goat-flow/decisions/` |
| Architecture | `.goat-flow/architecture.md` |
| Code map | `.goat-flow/code-map.md` |
| Glossary | `.goat-flow/glossary.md` |
| Agent skills | `.agents/skills/` |
| Agent hooks | `.codex/hooks/`, `.codex/hooks.json`, `.codex/config.toml` |
| Source code | `src/` |
| Tests | `tests/` |
| Documentation | `README.md`, `CONTRIBUTING.md`, `docs/` |

## PHP Project Patterns

Imports are alphabetical in groups: PHP built-ins, third-party, then `StrandsPhpClient\...`. Code style is PSR-12 with short arrays, single-quoted strings, trailing commas in multiline constructs, blank lines before returns, and no unused imports.

Use custom exceptions: `StrandsException`, `AgentErrorException`, and `StreamInterruptedException`. Pass structured agent error bodies via `responseBody` where available and document thrown exceptions in PHPDoc.

Tests live under `tests/Unit/`, mirror `src/`, use mocked HTTP only, and follow Arrange-Act-Assert. Test names describe feature and scenario, for example `testInvokeRetriesOnRetryableStatusCode`.
