---
category: gruff-php
last_reviewed: 2026-08-24
---

> **Version boundary (2026-08-08).** These lessons were written against `blundergoat/gruff-php` **v0.1.x**; the package is now pinned at **^0.5.1** in `require-dev`. The analyzer's rule directory was renamed from Rule to Rules, and `naming.parameter-type-name` was **removed** in favour of `naming.identifier-quality`, whose single global `ignoredNames` option replaces the old parameter-only allowlist. Evidence paths have been re-pointed and re-verified against v0.5.1 where the class survives. Lessons whose mechanics belonged to the removed rule carry their own supersession note — the workflow advice in them still holds, the rule internals do not.

## Lesson: Don't bulk-rename across the codebase without classifying findings first

**Created:** 2026-05-25

*Rule renamed since: the 466 findings came from `naming.parameter-type-name` under v0.1.x; v0.5.1 raises the equivalent through `naming.identifier-quality`. The classification workflow below is rule-agnostic and still the right first move.*

`naming.parameter-type-name` returned 466 findings in this repo. Charging into a global rename would have broken BC (named-argument callers), made code strictly worse (`$now` → `$dateTimeImmutable`), and touched Ask-First boundaries (`AuthStrategy`, `HttpTransport`, `StrandsClient` ctors, Citation/Message DTOs, Laravel/Symfony integrations). The right move was a one-pass classification of every finding into:

1. **Local variables / closure / arrow / anonymous-class params** — safe to rename (callers can't observe names).
2. **Private method params** — safe.
3. **Public method / constructor params, interface methods, abstract overridable methods** — BC-sensitive; allowlist instead of renaming.
4. **Semantically-richer-than-type names** (e.g. `$now`, `$previous`, `$completeEvent`) — allowlist or accept.

Classification took one Python script over the JSON output (`gruff-php analyse --format json`). Doing it up-front turned 466 findings into 1 remaining (the irreducible `$now` local) with zero BC breaks. See `.goat-flow/learning-loop/patterns/gruff-php.md` for the workflow.

## Lesson: Scope method renames to the declaring receiver

**Created:** 2026-08-24
**Decision changed:** Rename a private helper only at its declaration and `$this->` call sites; audit other receivers before replacing the same method token.
**Trigger phase:** ACT

A word-boundary rename of the private test helper `getSpans()` to `exportedSpans()` also changed `$this->exporter->getSpans()` to a method that the OpenTelemetry exporter does not provide. The helper name was safe to change, but the identical selector on another receiver was not. The full suite exposed the mistake as 25 errors before the two vendor calls were restored.

For a method rename, match the receiver as well as the token: update `private function oldName` and `$this->oldName(` explicitly, then grep every `->oldName(` and `->newName(` occurrence by receiver before running the focused tests. Evidence: `tests/Http/Middleware/OtelTracingMiddlewareTest.php` and `tests/Http/Middleware/OtelTracingMiddlewareObserverTest.php` (search: `private function exportedSpans`); `vendor/open-telemetry/sdk/Trace/SpanExporter/InMemoryExporter.php` (search: `public function getSpans`).

## Lesson: gruff's token-sequence variant check accepts descriptive name expansions

**Created:** 2026-05-25

When two variables in the same scope both expect the same type-mirrored name (e.g. two `SigV4Auth` instances both wanting to be `$sigV4Auth`), the rule calls `isSpecificDuplicateName` which:

- Tokenises both the candidate name and the expected name (camelCase + digit split, lowercased).
- Returns true when the candidate has **strictly more tokens** and the expected tokens appear as a **contiguous subsequence** anywhere in the candidate.

So in a test that builds two SigV4Auths to compare regions, both `$sigV4AuthUsEast` and `$sigV4AuthEuWest` pass (each has 6 tokens containing the 4-token sequence `[sig, v, 4, auth]`). `$auth1`/`$auth2` does not pass (no `[sig, v, 4, auth]` subsequence).

**Practical rule when fixing collisions:** name variants `<expected-name><Discriminator>`, where `<Discriminator>` is one or more camelCase tokens describing what differs between the variants. Examples that pass: `$strandsConfigMaxRetries`, `$strandsConfigZeroRetries`, `$sigV4AuthExecuteApi`, `$sigV4AuthLambda`.

**Superseded by v0.5.1.** `isSpecificDuplicateName` and its host rule `naming.parameter-type-name` no longer exist — the symbol appears nowhere under `vendor/blundergoat/gruff-php/src/Rules/Naming/`. Treat the token-sequence mechanics above as v0.1.x history. The `<expected-name><Discriminator>` naming convention it produced is still good practice and still reads well, but do not expect `naming.identifier-quality` to accept or reject variants by the same rule.

**Evidence:** the tokenizer survives at `vendor/blundergoat/gruff-php/src/Rules/Naming/IdentifierTokenizer.php` (search: `class IdentifierTokenizer`); the rule that consumed it does not.

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

**Evidence:** `vendor/blundergoat/gruff-php/src/Rules/TestQuality/MockWithoutExpectationRule.php (search: "stub-only")`, plus `vendor/blundergoat/gruff-php/src/Rules/TestQuality/TestQualityNodeHelper.php (search: "createstub")`.

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

**Recommendation:** treat as known debt unless a specific test would genuinely read better with capture-spy. Document the pattern (see `.goat-flow/learning-loop/patterns/gruff-php.md` "PSR-17 real implementations" entry — the capture pattern there is the same shape).

## Lesson: `test-quality.eager-test` is over-eager on multi-attribute lifecycle tests

**Created:** 2026-05-25

The rule fires when a test has ≥3 assertions AND ≥2 distinct SUT method calls (`minAssertions` default 3). Middleware/observer/span tests legitimately call `beforeRequest()` + `afterResponse()` (the begin/end span lifecycle) and then assert on the resulting state — name, kind, attribute keys, status code, etc. Splitting one such test into 9 single-assertion tests is strictly worse: same setup repeated 9 times, harder to read, slower.

**Recommendation:** for genuine lifecycle tests this is a false positive. Raise the threshold project-wide (`thresholds.minAssertions`) only if your codebase has *no* tests that legitimately verify multi-attribute state. Otherwise leave as advisory debt — the 10 OtelTracingMiddlewareTest cases here are all legitimate.

## Lesson: `security.sensitive-data-logging` flags LLM `token` counts as auth tokens

**Created:** 2026-05-25

`SecurityNodeHelper::hasSensitiveContext()` matches the regex `/(?:api[_-]?key|auth(?:orization)?|cookie|pass(?:word|wd)?|private[_-]?key|secret|token)/i` against variable names, property names, and array keys passed to logger calls. Any identifier containing the substring `token` triggers it.

In LLM clients, `inputTokens` / `outputTokens` / `total_tokens` are the **industry-standard** OpenTelemetry gen_ai semantic-convention field names for prompt/completion size — nothing to do with authentication. The rule fires on `$response->usage->inputTokens` references in legitimate observability logging. The two findings in `src/StrandsClient.php` (search: "Strands invoke response", "Strands stream complete") are both this false positive.

**Recommendation:** accept as known false positive. Renaming `Usage::$inputTokens` / `$outputTokens` would be a BC break for public DTO properties AND deviate from gen_ai semantic conventions; renaming the array log keys still leaves the property accesses in the call tree, so the rule fires anyway. The honest fix would be a vocabulary allowlist option upstream in gruff-php.

## Lesson: `security.dangerous-function-call` flags every `$callable()` syntax in tests

**Created:** 2026-05-25

`DangerousFunctionCallRule` walks `Expr\FuncCall` nodes; when the call's `name` is not a `Node\Name` (i.e. a variable, array dim fetch, or other expression) and the rule's callable-source tracker can't match the receiver to a known callable parameter/property/local, it reports `'dynamic function call'`. The tracker can't see `__invoke`-able class instances, so `$callbackHandler($event)` and `$onChunk($chunk)` get flagged even when the receiver is obviously a Closure or invokable.

**Mechanical fix:** rewrite `$var(args)` → `$var->__invoke(args)`. PHP treats both identically for Closures and `__invoke`-able objects; the rule walks FuncCall only and ignores MethodCall, so the finding vanishes. Applied to 52 test invocations across this repo via a regex script (see `.goat-flow/learning-loop/patterns/gruff-php.md` "word-boundary PHP variable rename" pattern — the same regex shape works for adding `->__invoke`). All 595 tests stayed green.

**Caveat:** only safe when the receiver is **known** to be a Closure or `__invoke`-able instance. For a raw `callable`-typed parameter that might be a function-name string or `[obj, 'method']` array, calling `->__invoke()` would error. In this repo every flagged case was a Closure delivered via `willReturnCallback` or a PrintingCallbackHandler/StreamCallbackHandler instance.

## Lesson: `test-quality.test-longer-than-sut` is mostly a setup-extraction nudge

**Created:** 2026-05-25

The rule fires when a test method body is ≥12 non-blank lines AND has exactly one apparent SUT call. The fix that actually matches the rule's intent is to **extract setup into a helper** so the test body shrinks AND `sutCalls` becomes 2+ (helper call + real SUT call). The rule walks `Stmt\ClassMethod` test-scope bodies via `NodeIndex::descendantsOfAny`, so calls living inside private helper methods are invisible — they don't count as test-body calls and they don't trigger any other rule downstream.

Patterns that respond well to mechanical extraction (verified on this repo, 110 of 144 findings cleared):

- **Inline `$data = [...]` literal first** → `private function dataFor<TestName>(): array` returning the literal. Replaces the literal with `$data = $this->dataFor<TestName>();`.
- **`$x = new ClassName([...inline...])`** → `private function <className>For<TestName>(): ClassName` that does the `new`. Test body collapses to a single one-liner setup.
- **`$x = $this->helperOnTestClass([...inline...])`** → second-layer helper that returns just the array; call site stays `$this->originalHelper($this->dataForX())`.
- **`$x = "<single-line literal>";`** → `private function rawFor<TestName>(): string` returning the literal. Common in parser tests.
- **Multi-class setup pipeline** (e.g. `$resp = new MockResponse(...); $client = new MockHttpClient($resp); $transport = new SymfonyHttpTransport($client);`) → one factory helper like `transportReturning(string $body, int $status)` that consolidates the pipeline.

**Cases the heuristic gets wrong** (treat as advisory debt):

- Tests that exercise a **single SUT call** but legitimately need ≥12 lines of unique inline setup that can't be parameterised cleanly (one-off edge-case data shapes, multi-line concat strings).
- Tests with **named-argument constructors** that vary on 2–3 dimensions; collapsing into a defaulted helper makes the test less self-explanatory.
- Tests whose setup IS the behaviour being tested (e.g. asserting that a complex inline payload normalises correctly — the payload shape is the contract).

**Evidence:** see the 5 extraction patterns implemented in `/tmp/extract_test_data.py` (script run during this session) plus the per-file helpers added to `tests/Unit/SymfonyHttpTransportTest.php` (search: "transportReturning") and `tests/Unit/SigV4AuthTest.php` (search: "sigV4AuthWith").

## Lesson: `test-quality.magic-number-assertion` doesn't recognise LLM `usage` property names

**Created:** 2026-05-25

The rule fires on `$this->assertSame(<literal>, <expr>)` when the literal isn't in `allowedLiterals` (config) AND the expression's property/key name isn't in the hardcoded `CONTEXTUAL_NUMERIC_NAMES` list. That list is **not configurable** — it's a private constant in `MagicNumberAssertionRule.php`. The names it does recognise are general analyzer/test terms (`count`, `total`, `findings`, `score`, `complexity`, etc.). It does NOT include domain-specific numeric names like `inputTokens`, `outputTokens`, `latencyMs`, `totalEvents`, `textEvents`, `cacheReadInputTokens` — all of which show up in any LLM client test.

In this repo every `assertSame(100, $agentResponse->usage->inputTokens)` round-trip test triggers the rule because:
- The literal 100 isn't an HTTP code (the only thing the default allowlist covers).
- The property `inputTokens` isn't in the contextual-name allowlist.

The literals are **inherently** paired with their fixture-data counterparts; the test's whole point is "I put 100 in, prove 100 came back." Extracting `const FIXTURE_INPUT_TOKENS = 100` and using it in both setup and assertion adds an indirection without adding signal — the reader still has to chase the constant to verify the round-trip.

**Recommendation:** accept these 96 findings as Advisory debt. The rule ships with `Confidence::Low` and `Severity::Advisory` precisely because the rule's own author knows this is heuristic. If gruff-php ever exposes `additionalContextualNames` as a config option, add `inputTokens`/`outputTokens`/`latencyMs`/`textEvents`/`totalEvents` and revisit.

**Evidence:** `vendor/blundergoat/gruff-php/src/Rules/TestQuality/MagicNumberAssertionRule.php (search: "CONTEXTUAL_NUMERIC_NAMES")` — hardcoded list at the top of the file.

## Lesson: `sensitive-data.high-entropy-string` flags long MIME types and rule references

**Created:** 2026-05-25

The detector matches string literals ≥32 chars in `[A-Za-z0-9_+\/=.-]` that aren't recognised as URLs/routes/known file extensions. The exemption for path-like literals only catches strings that **start** with `/`, `./`, `../`, or a scheme (`http://`, etc.). Strings that contain a `/` mid-string and have high character variety — common for fully-qualified MIME types and rule-set references — fail the exemption and fire the rule.

Confirmed false positives in this repo:
- `phpmd.xml` (search: "rulesets/codesize.xml/TooManyPublicMethods"): a PHPMD rule reference.
- `src/Context/AgentInput.php` (search: "application/vnd.openxmlformats-officedocument.wordprocessingml.document") and its test mirror: the standard OpenXML MIME type.

**Recommendation:** accept these as known debt. Splitting the MIME with concatenation (`'application/vnd.openxmlformats-' . 'officedocument.wordprocessingml.document'`) would silence the rule but ruins grep-ability and adds runtime work for zero security gain. Constants don't help — the literal still appears in the const declaration line.
