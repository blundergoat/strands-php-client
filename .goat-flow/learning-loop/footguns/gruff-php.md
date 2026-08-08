---
category: gruff-php
last_reviewed: 2026-08-08
---

> **Version boundary (2026-08-08).** These entries were written against `blundergoat/gruff-php` **v0.1.x**. The package is now pinned at **^0.5.1** in `composer.json` `require-dev`. Two things changed that this bucket depends on: the analyzer's rule directory was renamed from Rule to Rules, and the `naming.parameter-type-name` rule was **removed** and replaced by `naming.identifier-quality`, which exposes a single global `ignoredNames` option instead of a parameter-only allowlist. Evidence paths below have been re-pointed at the v0.5.1 layout and re-verified; anything that depended on the removed rule now sits in the resolved section at the end of this file. Re-validate a rule's mechanics against the installed source before acting on an entry created before this line.

## Footgun: Renaming public ctor / method params breaks named-argument callers

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED
**Decision changed:** Never take a naming rule's rename suggestion on a public constructor or interface method; allowlist the name instead.

Any naming rule that suggests renaming `$config` to `$strandsConfig` on `StrandsClient::__construct` is proposing a **BC break**: a consumer calling `new StrandsClient(config: ..., transport: ...)` with named arguments breaks at the call site. This hazard is independent of which analyzer version flags it — v0.1.x raised it as `naming.parameter-type-name`, v0.5.x raises the equivalent through `naming.identifier-quality`.

Affected surfaces in this repo: every public constructor in `src/Config/`, `src/Response/` (DTOs — `Citation`, `Message`, `StreamEvent`), `src/StrandsClient.php`, plus interface methods on `src/Http/RequestMiddleware.php` and `src/Http/ResponseObserver.php`.

**Defensive workflow:** allowlist BC-sensitive names in `.gruff-php.yaml` rather than renaming. Under v0.5.1 the knob is `naming.identifier-quality.options.ignoredNames` (search: `'ignoredNames' => self::DEFAULT_IGNORED_NAMES`), which applies globally rather than to parameters only. See `.goat-flow/learning-loop/patterns/gruff-php.md` for the workflow.

## Footgun: gruff baseline file is all-or-nothing

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED
**Decision changed:** Do not generate a baseline mid-cleanup; drive findings down first, then record only genuine accepted debt.

`gruff-php analyse --generate-baseline` captures **every current finding** as accepted debt. There is no per-finding `@gruff-ignore` annotation, no per-rule baseline file, and no path-pattern allowlist for most rules. Generating a baseline mid-cleanup silently accepts noisy heuristic findings (`test-longer-than-sut`, `magic-number-assertion`) alongside the legitimate exceptions you meant to record.

**Evidence:** `.gruff-php.yaml` (search: `--generate-baseline`) documents that the baseline records current findings as known debt.

**Defensive workflow:** drive findings down by rename, allowlist, or refactor first; generate the baseline only when the remainder is genuinely accepted debt. Rules that ship their own ignore options under v0.5.1 include `naming.abbreviation-allowlist.options.ignoredNames`, `naming.identifier-quality.options.ignoredNames`, and `test-quality.conditional-logic.options.ignoredPathPatterns`; each is documented under its own config block.

## Footgun: `test-quality.mystery-guest` flags `file_get_contents` only in test bodies, not helpers

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED
**Decision changed:** Fix a mystery-guest finding by moving the read into a private helper; do not argue about the I/O itself.

The rule's escape hatch is structural: it walks `TestQualityNodeHelper::testScopes($analysisUnit)` and flags read functions found there. A `private function loadJsonFixture(...)` helper on the same test class is not a "test scope", so calls to it from test methods are invisible to the rule while the helper itself may use `file_get_contents` freely.

**Implication:** mixing raw `file_get_contents(...)` in tests with a helper elsewhere in the same file produces findings on the raw call sites and zero on the helper — same I/O, different visibility. The fix is mechanical, not philosophical.

**Evidence:** `vendor/blundergoat/gruff-php/src/Rules/TestQuality/MysteryGuestRule.php` (search: `testScopes`) — re-verified against v0.5.1.

## Footgun: PHP variable rename via plain string replace eats prefixes

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED
**Decision changed:** Use a word-boundary regex for any bulk PHP variable rename, never a plain string replace.

A literal `$auth` → `$apiKeyAuth` replace turns `$author` into `$apiKeyAuthor` and `$auth1` into `$apiKeyAuth1`. Use a word-boundary regex (`\$<name>\b` with Python `re`, or `sed -E 's/\$<name>\b/$<new>/g'`) — PHP identifier characters `[A-Za-z0-9_]` are word characters under `\b`, so `\$auth\b` matches `$auth->` but not `$author` and not `$auth1`.

**Concrete case:** the bulk rename script for `naming.abbreviation-allowlist` would have corrupted `$author`-style names in tests had it used plain replace-all. See `.goat-flow/learning-loop/patterns/gruff-php.md` for the regex pattern.

## Resolved Entries

## Footgun: `naming.parameter-type-name` allowlist ignores local variables

**Status:** resolved | **Created:** 2026-05-25 | **Evidence:** OBSERVED
**Resolution:** The rule was removed in `gruff-php` v0.5.x. Its successor, `naming.identifier-quality`, exposes one global `ignoredNames` option that covers parameters and locals alike, so the parameter-only allowlist gap this entry warned about no longer exists.

Under v0.1.x the `ignoredParameterNames` option on `naming.parameter-type-name` filtered function and method **parameters** only. Local variables produced by `$x = new Foo()` ran through a separate `localObjectFindings` path with no allowlist hook, so the semantically meaningful `$now` in `src/Auth/SigV4Auth.php` (search: `now = new \DateTimeImmutable`) could not be exempted and the rule wanted `$dateTimeImmutable`.

**Evidence:** the v0.1.x source path `src/Rule/Naming/ParameterTypeNameRule.php` no longer exists in the installed package; `naming.parameter-type-name` is absent from `vendor/blundergoat/gruff-php/src/Rules/RuleRegistry.php` (search: `naming.identifier-quality`), and `ignoredParameterNames` and `localObjectFindings` appear nowhere under `vendor/blundergoat/gruff-php/src/Rules/Naming/`.
