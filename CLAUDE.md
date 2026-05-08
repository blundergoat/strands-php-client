# Strands PHP Client — goat-flow v1.5.0

PHP client library for consuming Strands AI agents over HTTP (invoke, SSE streaming).
Core invariant: `HttpTransport` is an **interface** — no default method bodies, no abstract class.

## Workspace Boundary

The controlling goat-flow workspace and the selected target project are the same directory. All `.goat-flow/` surfaces, source code, and project-specific content live in this repo.

## Truth Order

1. User's explicit instruction for this session.
2. This instruction file.
3. Architecture (`.goat-flow/architecture.md`).
4. Skills/templates loaded on demand.

## Autonomy Tiers

### Always
Read files, run validation (`composer test`, `composer analyse`, `composer cs:check`), edit within declared scope, write continuity notes only when useful.

### Ask First
Before touching these boundaries, state: boundary touched, related code read, footgun checked, local instruction checked, rollback command.

- **Instruction files**: `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md`
- **Architecture/config**: `composer.json`, `phpstan.neon`, `phpunit.xml`, `phpmd.xml`, `infection.json5`, `.php-cs-fixer.php`
- **Framework integrations**: `src/Integration/Laravel/`, `src/Integration/Symfony/`
- **CI/hooks**: `.github/workflows/`, `.claude/hooks/`, `.claude/settings.json`, `scripts/`
- **Interfaces**: `src/Http/HttpTransport.php`, `src/Auth/AuthStrategy.php`, `src/Http/RequestMiddleware.php`
- **Add/remove/rename**: any public class, method, or namespace

### Never
- Do not edit secrets, `.env`, or credential files.
- Do not push, commit, or run destructive git commands.
- Do not overwrite files without checking destination first.
- Freeze writes first if interrupted or told "no changes."

## Hard Rules

- If file exists, modify in place. Never create `_modified`, `_new`, `_backup`, or `_v2` variants.
- Severity order: SECURITY > CORRECTNESS > INTEGRATION > PERFORMANCE > STYLE.
- `declare(strict_types=1)` in every PHP file. `readonly` on DTO properties.
- Fully qualified PHPDoc types (`array<string, string>`, not bare `array`).
- No features, abstractions, or error handling beyond what was asked.
- Ambiguous requirements: present interpretations; do not pick silently.

## Key Resources

- **Learning loop** (grep before every change): `.goat-flow/footguns/`, `.goat-flow/lessons/`, `.goat-flow/patterns/`, `.goat-flow/decisions/`.
- **Tool playbooks**: `.goat-flow/skill-reference/browser-use.md`, `.goat-flow/skill-reference/page-capture.md` — read BEFORE declaring a tool unavailable.
- **Agent guidelines**: `AGENTS.md` — coding patterns, testing patterns, style rules.

## Essential Commands

```bash
composer test                    # PHPUnit (477 tests)
composer analyse                 # PHPStan Level 10
composer cs:check                # PHP-CS-Fixer dry-run (PSR-12)
composer cs:fix                  # Auto-format
composer analyse:messdetector    # PHPMD
composer analyse:complexity      # Cyclomatic complexity (max 20)
composer mutate                  # Infection mutation testing
composer preflight               # All quality gates at once
```

Single file/method: `vendor/bin/phpunit tests/Unit/FooTest.php --filter testBar`

## Execution Loop: READ -> SCOPE -> ACT -> VERIFY

When a goat-* skill is active, the skill's Step 0 replaces READ and selects the skill's mode/depth. SCOPE still applies before writes. Resume at ACT after Step 0 output.

### READ
MUST read relevant files before changes. Never fabricate codebase facts. Check browser evidence first for URL, local HTML, localhost, screenshot, rendered UI, or browser-visible behaviour. Use grep-first retrieval across learning-loop dirs; include decisions for architecture, policy, or setup work. Before declaring any tool unavailable, read the matching `.goat-flow/skill-reference/` playbook and run its Availability Check.

### SCOPE
Declare intent, complexity tier, mode, files allowed to change, non-goals, and blast radius. Expanding beyond scope means stop and re-scope.

### ACT
Declare `State: [MODE] | Goal: [one line] | Exit: [condition]`. Mode: Plan, Implement, Explain, Debug, or Review.

### VERIFY
Run required checks for changed files. Check cross-references after renames. Do not claim checks passed without the literal pass/fail line from this session. Stop the line when tests break, builds fail, or behaviour regresses. If VERIFY caught a failure or you corrected course, update the learning loop before DoD.

## Definition of Done

MUST confirm all six gates:
1. Lint/typecheck passes on changed files.
2. No broken cross-references.
3. No unapproved boundary changes.
4. Logs updated if tripped.
5. Working notes current.
6. Grep old pattern after renames.

## Artifact Routing

| Artifact | Destination |
|----------|-------------|
| Footgun (code trap) | `.goat-flow/footguns/` |
| Lesson (agent mistake) | `.goat-flow/lessons/` |
| Decision record | `.goat-flow/decisions/` |
| Reusable pattern | `.goat-flow/patterns/` |

Read the target directory's `README.md` before editing.

## Router Table

| Surface | Path |
|---------|------|
| Learning loop | `.goat-flow/footguns/`, `.goat-flow/lessons/`, `.goat-flow/patterns/`, `.goat-flow/decisions/` |
| Skill reference + tool playbooks | `.goat-flow/skill-reference/` |
| Architecture | `.goat-flow/architecture.md` |
| Code map | `.goat-flow/code-map.md` |
| Glossary | `.goat-flow/glossary.md` |
| Agent skills | `.claude/skills/` |
| Agent guidelines | `AGENTS.md` |
| Source code | `src/` |
| Tests | `tests/` |
| Scripts | `scripts/` |
| Config | `composer.json`, `phpstan.neon`, `phpunit.xml`, `phpmd.xml`, `infection.json5` |
| Docs | `docs/` |
| CI | `.github/workflows/ci.yml` |
| Session logs | `.goat-flow/logs/sessions/` |
| Scratchpad | `.goat-flow/scratchpad/` |
| Tasks | `.goat-flow/tasks/` |
