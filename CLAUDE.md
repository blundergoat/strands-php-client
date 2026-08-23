# CLAUDE.md — strands-php-client

**Project identity.** `blundergoat/strands-php-client` is a PHP 8.2+ library that consumes [Strands Agents](https://github.com/strands-agents/strands-agents) over HTTP — invoke, SSE streaming, custom-endpoint passthrough — with Laravel and Symfony integrations. **Core invariant:** the library never runs an agentic loop in PHP; it only marshals requests/responses for a Python agent. **Contract invariant:** this client targets the Strands HTTP Wire Contract v1 emitted by wrapper services, not raw sdk-python `TypedDict` shapes — see `docs/wire-contract.md` and `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md`. Cross-cutting concerns: PSR-3 logging, PSR-18/Symfony transport abstraction, immutable DTOs/builders, strict types, defensive parsing.

**Goat-flow version:** 1.16.0

**Workspace boundary.** The controlling goat-flow workspace (skills, templates, manifest) lives in `node_modules/@blundergoat/goat-flow/`. The selected target project is this repository root. Adapt commands, paths, and boundaries from the target — do not echo the controlling workspace's paths into installed surfaces.

## Truth Order

1. User's explicit instruction for this session
2. This file (`CLAUDE.md`)
3. `.goat-flow/architecture.md`, `.goat-flow/code-map.md`, `.goat-flow/glossary.md`
4. `.goat-flow/learning-loop/decisions/` (ADRs, including the wire-contract ADR)
5. Skills loaded on demand from `.claude/skills/`
6. `AGENTS.md` (peer instruction; do not modify under Claude scope)

## Autonomy Tiers

**Always.** Read `src/`, `tests/`, `docs/`, `composer.json`, `phpstan.neon`, `phpunit.xml`. Run `composer test`, `composer analyse`, `composer cs:check`, `composer preflight`. Edit inside the declared scope. Write session notes to `.goat-flow/logs/sessions/`.

**Ask First** — state boundary touched, related code read, footgun checked, local instruction checked, rollback command:
- `composer.json` `require` / `require-dev` (dependency surface — affects every consumer; rollback `git checkout composer.json composer.lock`)
- `src/Http/HttpTransport.php` (interface — every transport implementer breaks; rollback `git checkout src/Http/`)
- `src/Auth/AuthStrategy.php` (interface — every auth driver breaks; rollback `git checkout src/Auth/`)
- `src/Integration/Laravel/` and `src/Integration/Symfony/` (framework wiring — consumer apps depend on service IDs / config keys)
- Wire-contract shapes (`docs/wire-contract.md`, `tests/Fixtures/wire-contract/`, `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md`) — changes here break Python wrappers and PHP consumers simultaneously
- `.github/workflows/`, `scripts/preflight-checks.sh`, `phpstan.neon`, `phpunit.xml`, `infection.json5` (CI / quality gates)
- 3+ files renamed, moved, or deleted at once
- `.claude/settings.json`, `.goat-flow/hooks/` (harness configuration)

**Never.** Freeze writes if interrupted. Do not edit `AGENTS.md`, `.codex/`, `.agents/`, `.gemini/` under Claude scope. Do not commit, push, branch, reset, or run destructive git operations — the user owns all git operations. Do not weaken PHPStan Level 10, PSR-12, or test coverage to make checks pass.

## Hard Rules

- Modify files in place. No `_modified`, `_new`, `_backup`, `_v2` variants — git history is the backup.
- Severity order: SECURITY > CORRECTNESS > INTEGRATION > PERFORMANCE > STYLE.
- `declare(strict_types=1)` in every PHP file. `readonly` on DTO properties. Fully qualified PHPDoc array shapes (`array<string, string>`), never bare `array` or `mixed` without justification.
- Cite file evidence with semantic anchors (function name, unique string, or `(search: "pattern")`) — never bare line numbers.
- Preserve cross-file consistency for the same concept (e.g., `Usage::fromArray()` is the canonical hydrator — `AgentResponse::parseUsage()`, `MessageMetadata`, `OtelTracingMiddleware`, and `StrandsClient` all route through it; never fork or re-wrap it).
- Stream-cancellation callbacks compare with `=== false` (preserves void-returning callers). Do not collapse to `if (!$onChunk(...))`.
- Sub-agents get one objective, structured return, and a 5-call budget.
- No features, abstractions, or error handling beyond what was asked.
- Ambiguous requirements: present interpretations; do not pick silently.

## Key Resources

- **Learning loop** (read each `INDEX.md` first, open entries only on hits): `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, `.goat-flow/learning-loop/decisions/`
- **Playbooks**: `.goat-flow/skill-docs/playbooks/README.md` is the full index; tool availability at `.goat-flow/skill-docs/playbooks/browser-use.md` and `.goat-flow/skill-docs/playbooks/page-capture.md`, plus discipline playbooks for changelog, release notes, writing style, code comments, naming and placement, test selection, and hook-policy testing
- **Skill-authoring methodology**: `.goat-flow/skill-docs/skill-quality-testing/` — load the README first, then the topical authoring guide
- **Wire contract**: `docs/wire-contract.md`, `tests/Fixtures/wire-contract/`, `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md`
- **Project orientation**: `.goat-flow/architecture.md`, `.goat-flow/code-map.md`, `.goat-flow/glossary.md`
- **Peer instructions**: `AGENTS.md` (project conventions, coding patterns, testing patterns — read for context, do not modify)

## Essential Commands

```bash
composer test                      # PHPUnit suite (must be green)
composer analyse                   # PHPStan Level 10
composer cs:check                  # PHP-CS-Fixer dry-run (PSR-12)
composer cs:fix                    # PHP-CS-Fixer apply
composer analyse:complexity        # Cyclomatic complexity ≤ 20
composer analyse:messdetector      # PHPMD
composer preflight                 # Everything above, gate before commit
composer mutate                    # Infection (slow; XDEBUG_MODE=coverage required)
.goat-flow/hooks/deny-dangerous/deny-dangerous-self-test.sh   # Hook self-test
```

Single file/method: `vendor/bin/phpunit tests/Unit/FooTest.php --filter testBar`

## Execution Loop: READ → SCOPE → ACT → VERIFY

When a goat-* skill is active, its Step 0 replaces READ and selects the skill's mode/depth. SCOPE still applies before writes: a skill may write when its selected mode permits writes or the user explicitly approves them. `/goat-plan` File-Write may create gitignored milestone files without a separate approval gate; `/goat-debug` D3 still requires approval before fixes. Resume at ACT after Step 0 output or when a blocking gate releases.

### READ
MUST read relevant files before changes. Never fabricate codebase facts. For URL, local HTML, localhost, screenshot, rendered UI, or browser-visible behaviour, check browser evidence first. Use INDEX-first retrieval: read the `INDEX.md` rows in `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, and `.goat-flow/learning-loop/patterns/` first and open entries only on hits; include `.goat-flow/learning-loop/decisions/INDEX.md` for architecture or policy work. Grep buckets only after the INDEX pass or a known retrieval miss. Before declaring any tool or capability unavailable, read the matching playbook in `.goat-flow/skill-docs/playbooks/` (e.g. `browser-use.md`, `page-capture.md`) and run that doc's "Availability Check" section verbatim — project-local CLI tools at `~/.local/bin/` are valid; do not conflate "no harness/MCP tool" with "no tool". Discipline playbooks have no availability check and need their own trigger: read the matching one before editing `CHANGELOG.md`, release notes, `README.md` or `docs/` prose, PR/issue text, learning-loop entry bodies, source comments, or instrumentation. Before creating, changing, reviewing, consolidating, moving, or pruning tests, read `.goat-flow/skill-docs/playbooks/test-selection.md`.

### SCOPE
Declare intent, complexity tier (Hotfix / Standard Feature / System Change / Infrastructure), mode, files allowed to change, non-goals, blast radius. Expanding beyond scope means stop and re-scope with the human.

### ACT
Declare `State: [MODE] | Goal: [one line] | Exit: [condition]`. Mode must be Plan, Implement, Explain, Debug, or Review. Implement in 2–3 turns; a 4th read without writing means checkpoint or re-scope.

### VERIFY
Run `composer analyse` and `composer test` on changed PHP. Run `shellcheck` on changed `.sh`. Check cross-references after renames (grep the old name). Tick milestone checkboxes immediately, not at the end. Stop-the-line on broken tests, build failures, or behaviour regressions; preserve evidence, return to diagnosis, re-plan.

**Hallucination red-flags:**

1. **Checks passed.** Do not claim tests pass or any check passed (`composer analyse`, `composer test`, `composer preflight`, audit) without showing the literal pass/fail line copied verbatim from this session's run. Paraphrase, cached output, or prior-session results do not count.
2. **Completion.** Do not claim completion without listing the specific files changed in this turn. If no files were changed, say so explicitly.
3. **Fix verification.** Do not claim a fix works without running the reproduction steps that originally demonstrated the bug. "Looks correct" is not verification.
4. **Hedged claims.** Do not use "should work", "probably fine", "looks good" as verification. These are guesses, not evidence.

Rationalisations to reject — see `.goat-flow/skill-docs/skill-preamble.md` ("Rationalisations to reject" table) for the canonical Excuse / Reality pairs.

- **Stop-the-line:** When tests break, builds fail, or behaviour regresses — stop expanding scope. Preserve evidence, return to diagnosis, re-plan before continuing.
- Level 1 (isolated): note, continue. Level 2 (cross-doc, broken refs, evidence): MUST full stop, wait for human. Two corrections on same approach = MUST rewind.
- Recovery: missing context → read first. Out-of-scope → name boundary, redirect. Conflicting sources → flag, ask.

If VERIFY caught a failure or you corrected course, update the learning loop before DoD: behavioural mistakes → `.goat-flow/learning-loop/lessons/<category>.md`, architectural traps → `.goat-flow/learning-loop/footguns/<category>.md` with `**Status:** active | **Created:** YYYY-MM-DD | **Evidence:** ACTUAL_MEASURED`, significant decisions → `.goat-flow/learning-loop/decisions/`, optional continuity → `.goat-flow/logs/sessions/`.

## Definition of Done

Confirm all six gates: (1) `composer analyse` clean on changed files; (2) `composer test` green; (3) no broken cross-references after renames; (4) no unapproved Ask-First boundary changes; (5) learning loop updated if VERIFY tripped; (6) any milestone file's `- [x]` ticks current.

## Artifact Routing

Add footguns → `.goat-flow/learning-loop/footguns/<category>.md` (read `.goat-flow/learning-loop/footguns/README.md` first). Add lessons → `.goat-flow/learning-loop/lessons/<category>.md`. Add decisions → `.goat-flow/learning-loop/decisions/ADR-NNN-*.md`. Add patterns → `.goat-flow/learning-loop/patterns/<name>.md`. These are documentation artifacts, not runtime code.

## Router Table

| Resource | Path |
|----------|------|
| Instruction file (this file) | `CLAUDE.md` |
| Learning loop | `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, `.goat-flow/learning-loop/decisions/` |
| Skill reference (meta) | `.goat-flow/skill-docs/` |
| Tool playbooks (README index; tools e.g. browser-use, page-capture; disciplines e.g. changelog, release notes, prose style) | `.goat-flow/skill-docs/playbooks/` — read when a request names one, and BEFORE declaring a tool unavailable |
| Skill-authoring methodology | `.goat-flow/skill-docs/skill-quality-testing/` — load the README, then the topical authoring guide |
| Architecture | `.goat-flow/architecture.md` |
| Orientation | `.goat-flow/code-map.md`, `.goat-flow/glossary.md` |
| Wire contract | `docs/wire-contract.md`, `tests/Fixtures/wire-contract/`, `.goat-flow/learning-loop/decisions/ADR-001-strands-http-wire-contract.md` |
| Claude skills + harness | `.claude/skills/`, `.claude/settings.json`, `.goat-flow/hooks/` |
| Source | `src/` (entry: `src/StrandsClient.php`) |
| Tests | `tests/Unit/`, `tests/Fixtures/`, `tests/Support/` |
| Project docs | `docs/usage-guide.md`, `docs/auth.md`, `docs/rich-input.md`, `docs/interrupts-and-guardrails.md`, `docs/laravel-config.md`, `docs/symfony-config.md`, `docs/wire-contract.md` |
| Quality config | `phpstan.neon`, `phpmd.xml`, `infection.json5`, `phpunit.xml`, `.php-cs-fixer.php` |
| Scripts | `scripts/preflight-checks.sh`, `scripts/check-cyclomatic-complexity.php` |
| CI | `.github/workflows/ci.yml` |
| Commit guidance | `.github/git-commit-instructions.md` |
| Workspace notes | `.goat-flow/logs/sessions/`, `.goat-flow/plans/`, `.goat-flow/scratchpad/` |
| Peer instructions | `AGENTS.md` (do not modify under Claude scope) |
