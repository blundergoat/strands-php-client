---
category: gruff-php
last_reviewed: 2026-07-04
---

## Footgun: `naming.parameter-type-name` allowlist ignores local variables

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

The `ignoredParameterNames` option on `naming.parameter-type-name` only filters function/method **parameters**. It does **not** apply to local variables produced by `$x = new Foo()` assignments — those run through a separate code path (`localObjectFindings`) that has no allowlist hook.

**Concrete case from this repo:** `src/Auth/SigV4Auth.php (search: "now = new \\DateTimeImmutable")`. The local `$now` is semantically meaningful ("the current moment for signing") but the rule wants `$dateTimeImmutable`. Adding `now` to `ignoredParameterNames` does nothing because it's a local, not a parameter. The only escape hatches for locals are:

1. Rename to the type-mirroring name (loses semantic meaning).
2. Restructure to avoid the `new X()` assignment (e.g. inline, or use a static factory like `DateTimeImmutable::createFromFormat()` that the rule does not pattern-match).
3. Accept the finding.

**Evidence:** `vendor/blundergoat/gruff-php/src/Rule/Naming/ParameterTypeNameRule.php (search: "localObjectFindings")` — only the parameter loop at `(search: "in_array($param->var->name, $ignoredParameterNames")` calls the allowlist; `localObjectFindings` does not.

## Footgun: Renaming public ctor / method params breaks named-argument callers

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

`naming.parameter-type-name` will tell you to rename `$config` to `$strandsConfig` on `StrandsClient::__construct`. Doing so is a **BC break**: any consumer calling `new StrandsClient(config: ..., transport: ...)` (named arguments, common in modern PHP) breaks at the call site.

Affected surfaces in this repo: every public constructor in `src/Config/`, `src/Response/` (DTOs — `Citation`, `Message`, `StreamEvent`), `src/StrandsClient.php`, plus interface methods on `src/Http/RequestMiddleware.php` and `src/Http/ResponseObserver.php`.

**Defensive workflow:** allowlist BC-sensitive parameter names in `.gruff-php.yaml` (`naming.parameter-type-name.options.ignoredParameterNames`) rather than renaming. See `.goat-flow/learning-loop/patterns/gruff-php.md` for the workflow.

## Footgun: gruff baseline file is all-or-nothing

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

`gruff-php analyse --generate-baseline` captures **every current finding** as accepted debt. There is no per-finding `@gruff-ignore` annotation, no per-rule baseline file, and no path-pattern allowlist for most rules. Generating a baseline mid-cleanup will silently accept noisy heuristic findings (e.g. `test-longer-than-sut`, `magic-number-assertion`) alongside the truly legitimate exceptions you wanted to record.

**Evidence:** `.gruff-php.yaml` (search: "--generate-baseline") documents that the baseline records current findings as known debt.

**Defensive workflow:** drive findings down by rename / allowlist / refactor first; generate the baseline only when the remaining set is genuinely "accepted debt." The few rules with their own ignore options (`naming.abbreviation-allowlist.options.ignoredNames`, `naming.parameter-type-name.options.ignoredParameterNames`, `test-quality.conditional-logic.options.ignoredPathPatterns`) are documented under each rule's config block.

## Footgun: `test-quality.mystery-guest` flags `file_get_contents` only in test bodies, not helpers

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

The rule's escape hatch is structural: it walks `TestQualityNodeHelper::testScopes($analysisUnit)` and flags read functions there. A `private function loadJsonFixture(...)` helper on the same test class is NOT a "test scope," so calls to it from test methods are invisible to the rule, while the helper itself can use `file_get_contents` freely.

**Implication:** if you mix raw `file_get_contents(...)` in tests with a helper method elsewhere in the same file, you'll see findings on the raw call sites and zero findings on the helper — the same I/O, different visibility. The fix is mechanical (move the read into a helper), not philosophical.

**Evidence:** `vendor/blundergoat/gruff-php/src/Rule/TestQuality/MysteryGuestRule.php (search: "testScopes")`.

## Footgun: PHP variable rename via plain string replace eats prefixes

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

A literal `$auth` → `$apiKeyAuth` replace will turn `$author` into `$apiKeyAuthor` and `$auth1` into `$apiKeyAuth1`. Use a word-boundary regex (`\$<name>\b` with Python `re` or `sed -E 's/\$<name>\b/$<new>/g'`) — PHP identifier chars `[A-Za-z0-9_]` are word chars under `\b`, so `\$auth\b` matches `$auth->` but not `$author` and not `$auth1`.

**Concrete case:** the bulk rename script for `naming.abbreviation-allowlist` would have corrupted `$author`-style names in tests had it used plain replace_all. See `.goat-flow/learning-loop/patterns/gruff-php.md` for the regex pattern.
