---
category: gruff-php
last_reviewed: 2026-05-25
---

## Lesson: Don't bulk-rename across the codebase without classifying findings first

**Created:** 2026-05-25

`naming.parameter-type-name` returned 466 findings in this repo. Charging into a global rename would have broken BC (named-argument callers), made code strictly worse (`$now` → `$dateTimeImmutable`), and touched Ask-First boundaries (`AuthStrategy`, `HttpTransport`, `StrandsClient` ctors, Citation/Message DTOs, Laravel/Symfony integrations). The right move was a one-pass classification of every finding into:

1. **Local variables / closure / arrow / anonymous-class params** — safe to rename (callers can't observe names).
2. **Private method params** — safe.
3. **Public method / constructor params, interface methods, abstract overridable methods** — BC-sensitive; allowlist instead of renaming.
4. **Semantically-richer-than-type names** (e.g. `$now`, `$previous`, `$completeEvent`) — allowlist or accept.

Classification took one Python script over the JSON output (`gruff-php analyse --format json`). Doing it up-front turned 466 findings into 1 remaining (the irreducible `$now` local) with zero BC breaks. See `.goat-flow/patterns/gruff-php.md` for the workflow.

## Lesson: gruff's token-sequence variant check accepts descriptive name expansions

**Created:** 2026-05-25

When two variables in the same scope both expect the same type-mirrored name (e.g. two `SigV4Auth` instances both wanting to be `$sigV4Auth`), the rule calls `isSpecificDuplicateName` which:

- Tokenises both the candidate name and the expected name (camelCase + digit split, lowercased).
- Returns true when the candidate has **strictly more tokens** and the expected tokens appear as a **contiguous subsequence** anywhere in the candidate.

So in a test that builds two SigV4Auths to compare regions, both `$sigV4AuthUsEast` and `$sigV4AuthEuWest` pass (each has 6 tokens containing the 4-token sequence `[sig, v, 4, auth]`). `$auth1`/`$auth2` does not pass (no `[sig, v, 4, auth]` subsequence).

**Practical rule when fixing collisions:** name variants `<expected-name><Discriminator>`, where `<Discriminator>` is one or more camelCase tokens describing what differs between the variants. Examples that pass: `$strandsConfigMaxRetries`, `$strandsConfigZeroRetries`, `$sigV4AuthExecuteApi`, `$sigV4AuthLambda`.

**Evidence:** `vendor/blundergoat/gruff-php/src/Rule/Naming/ParameterTypeNameRule.php (search: "isSpecificDuplicateName")`, tokenizer at `vendor/blundergoat/gruff-php/src/Rule/Naming/IdentifierTokenizer.php`.

## Lesson: `naming.abbreviation-allowlist` is global, not parameter-scoped

**Created:** 2026-05-25

`allowlists.acceptedAbbreviations` in `.gruff-php.yaml` exempts an identifier name **everywhere**: properties, parameters, AND local variables. Unlike `naming.parameter-type-name`'s `ignoredParameterNames`, there's no local-vs-parameter split. So adding `url` once covers every `$url` parameter and `$url = ...` local in `src/` and `tests/`.

**Implication for cleanup ordering:** when a short identifier name appears on a public-API parameter (BC-sensitive), allowlist it — that covers the public surface AND every safe local-var use, with no rename cost. Reserve renames for the truly-obscuring abbreviations (`$mw`, `$ctx`, `$def`, `$dto`, `$sig`, `$pos`) where the verbose form genuinely communicates intent better.

## Lesson: not every gruff rule has options — some need refactor or accept-as-debt

**Created:** 2026-05-25

Rules with no config knobs encountered in this repo:

- `test-quality.private-reflection` — no allowlist, no path-ignore. The 5 cases here (`StrandsFacadeTest` Laravel facade accessor, `StrandsServiceProviderTest` factory wiring, three `SigV4AuthTest` private-method tests) are all legitimate testing techniques. Fix options are: extract methods to a public utility class (BC concern: new public API), make methods public (worse, leaks internals), or accept as known debt.
- `test-quality.mystery-guest` — no options. Refactor (move I/O into a helper, see footguns/gruff-php.md) is the only path; accepting means the finding stays.

**Default order of operations** when first encountering a rule with high findings: (1) read the rule source to learn its option surface, (2) decide if it's a real smell or a heuristic, (3) for heuristics with no options, plan acceptance criteria before doing any rewrites.

## Lesson: `createMock` → `createStub` lowers severity but does NOT clear `test-quality.mock-without-expectation`

**Created:** 2026-05-25

`MockWithoutExpectationRule::isMockCreationExpression` treats `createMock`, `createStub`, `getMockBuilder`, `mock`, `partialMock`, `spy`, and `prophesize` identically. Converting `createMock` to `createStub` does change the **severity** from Warning (`dead-mock`) to Advisory (`stub-only`), but the finding still appears. Use the conversion when you want to communicate intent to readers AND drop the gate from blocking, but expect the advisory count to remain. To actually clear the finding you must add a real verification: `$stub->expects($this->once())->method('foo')->with(...)` or remove the mock entirely.

**Evidence:** `vendor/blundergoat/gruff-php/src/Rule/TestQuality/MockWithoutExpectationRule.php (search: "stub-only")`, plus `vendor/blundergoat/gruff-php/src/Rule/TestQuality/TestQualityNodeHelper.php (search: "createstub")`.

## Lesson: `test-quality.mock-only-test` does not recognise `$mock->expects(...)->with(...)` as a SUT assertion

**Created:** 2026-05-25

Standard PHPUnit "verify a collaborator was called correctly" tests look like:

```php
$transport->expects($this->once())
    ->method('post')
    ->with($expectedUrl, $expectedHeaders, $expectedBody);
$sut->doThing();
```

The mock framework fails the test if the recorded interactions don't match `with(...)`. `MockOnlyTestRule` doesn't count that as a "real assertion" — it counts `$this->assertX(...)` calls. The 44 cases in this repo are largely legitimate (verifying request envelope shape sent to transport, observer being notified with correct args, etc.); rewriting them to use a capture-then-assert spy pattern is a 5-min-per-test refactor with marginal real value.

**Recommendation:** treat as known debt unless a specific test would genuinely read better with capture-spy. Document the pattern (see `.goat-flow/patterns/gruff-php.md` "PSR-17 real implementations" entry — the capture pattern there is the same shape).

## Lesson: `test-quality.eager-test` is over-eager on multi-attribute lifecycle tests

**Created:** 2026-05-25

The rule fires when a test has ≥3 assertions AND ≥2 distinct SUT method calls (`minAssertions` default 3). Middleware/observer/span tests legitimately call `beforeRequest()` + `afterResponse()` (the begin/end span lifecycle) and then assert on the resulting state — name, kind, attribute keys, status code, etc. Splitting one such test into 9 single-assertion tests is strictly worse: same setup repeated 9 times, harder to read, slower.

**Recommendation:** for genuine lifecycle tests this is a false positive. Raise the threshold project-wide (`thresholds.minAssertions`) only if your codebase has *no* tests that legitimately verify multi-attribute state. Otherwise leave as advisory debt — the 10 OtelTracingMiddlewareTest cases here are all legitimate.
