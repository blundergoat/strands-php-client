---
category: gruff-php
last_reviewed: 2026-05-25
---

## Pattern: BC-safe naming-rule cleanup workflow

**Context:** When a gruff `naming.*` rule returns dozens or hundreds of findings, and the project ships a public API where renaming parameters would break consumers' named-argument calls.

**Approach:**

1. `vendor/bin/gruff-php analyse --include-rule <rule> --format json > /tmp/findings.json` to get structured data.
2. Classify each finding by **position safety**:
   - **Local variable** (`Variable $x in METHOD()` in the message): always safe to rename.
   - **Closure / arrow / anonymous-class param** (`closure@LINE` / `arrow@LINE` / `class@anonymous`): safe — callers can't use named args.
   - **Private method param**: safe.
   - **Public method / constructor / interface / abstract overridable param**: BC-sensitive.
3. For BC-sensitive names, add them to the rule's allowlist option (`naming.parameter-type-name.options.ignoredParameterNames` or `allowlists.acceptedAbbreviations`). Add a `# why` comment per entry — these are signposts for future reviewers and for `[[gruff-php-allowlist]]`-style cross-links.
4. For safe positions, rename via word-boundary regex script (see "PHP variable rename" pattern below) so `$auth` doesn't accidentally eat `$author` or `$auth1`.
5. For collisions (multiple variables in one scope want the same expected name), use **type-prefixed descriptive variants**: `$strandsConfigMaxRetries` / `$strandsConfigZeroRetries`. The rule's `isSpecificDuplicateName` check passes them as long as they contain the expected token sequence and have strictly more tokens.

**Evidence from this repo:** `naming.parameter-type-name` 466 → 1; `naming.abbreviation-allowlist` 188 → 0; zero BC breaks; tests + PHPStan Level 10 green. The one remaining is `$now = new \DateTimeImmutable()` in `src/Auth/SigV4Auth.php`, which the rule cannot silence for local vars (see `.goat-flow/footguns/gruff-php.md`).

## Pattern: Word-boundary PHP variable rename script

**Context:** When you need to rename a PHP variable across many files without corrupting prefix-matching names (`$auth` → `$apiKeyAuth` must not touch `$author` or `$auth1`).

**Approach:** Python's `\b` treats PHP identifier chars `[A-Za-z0-9_]` as word chars, so `\$<name>\b` matches the bare variable but not extended names.

```python
import re

pattern = re.compile(r'\$' + re.escape(old) + r'\b')
new_content, count = pattern.subn('$' + new, content)
```

Matches `$auth->method()`, `$auth;`, `$auth)`, `$auth,` — does NOT match `$author`, `$auth1`, `$authWithToken`. Verified on `tests/Unit/SigV4AuthTest.php` where the bare `$auth` → `$sigV4Auth` rename had to coexist with `$auth1`/`$auth2`/`$authWithToken`/`$authWithoutToken` variants in different scopes.

Same approach with `sed -E 's/\$<name>\b/$<new>/g'` works on systems where Python isn't preferred, but Python is friendlier when you need a per-file table of renames.

## Pattern: Load test fixtures via a private helper method (mystery-guest workaround)

**Context:** When tests need fixture data from `tests/Fixtures/` and `test-quality.mystery-guest` flags the `file_get_contents` calls.

**Approach:** Move the I/O into a `private function loadXxxFixture(string $name): T` helper on the test class. The rule walks test method scopes only; calls to helpers are invisible to it, and the helper itself isn't a test scope. Pair filename and decoder in one place:

```php
private function loadJsonFixture(string $relativePath): array
{
    return json_decode(
        file_get_contents(__DIR__ . '/../Fixtures/' . $relativePath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

private function loadSseFixture(string $name): string
{
    return file_get_contents(__DIR__ . '/../Fixtures/' . $name);
}
```

Then test bodies become `$data = $this->loadJsonFixture('wire-contract/invoke-response-tools-full.json');` — readable, single point to add `JSON_THROW_ON_ERROR` or schema validation later, and silent under `mystery-guest`. Verified across `tests/Unit/AgentResponseTest.php`, `tests/Unit/StrandsClientStreamTest.php`, `tests/Unit/StrandsClientTest.php`, `tests/Unit/StreamParserTest.php` — 24 findings cleared with zero test changes beyond the call-site swap.

## Pattern: Use real PSR-17 factories in PSR-18 tests to dodge excessive-mocking

**Context:** When `test-quality.excessive-mocking` flags PSR-18 transport tests for using 5–6 collaborators (RequestFactoryInterface, StreamFactoryInterface, RequestInterface, StreamInterface, ResponseInterface, ClientInterface).

**Approach:** PSR-18 testing forces a fan-out of factory mocks. Replace the factories with a real `Nyholm\Psr7\Factory\Psr17Factory` instance — it implements both `RequestFactoryInterface` and `StreamFactoryInterface` and produces real `RequestInterface`/`StreamInterface` objects. Keep `ClientInterface` mocked (that's the actual collaborator under test), use `willReturnCallback` to capture the real request for assertions:

```php
$psr17Factory = new Psr17Factory();
$capturedRequest = null;
$httpClient = $this->createMock(ClientInterface::class);
$httpClient->method('sendRequest')
    ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequest, $response) {
        $capturedRequest = $request;
        return $response;
    });

$transport = new PsrHttpTransport($httpClient, $psr17Factory, $psr17Factory);
// ...
$this->assertSame('application/json', $capturedRequest->getHeaderLine('Content-Type'));
```

Each `testPostWrapsClientException`, `testPostSendsHeaders`, `testTimeoutWarningLoggedOnceWithContext` in `tests/Unit/PsrHttpTransportTest.php` dropped from 5–6 mocks to 1–2. Nyholm is already a project dependency (`nyholm/psr7`), so no new package required.

## Pattern: Per-test data extraction to silence `test-quality.test-longer-than-sut`

**Context:** When `test-longer-than-sut` flags dozens of tests across a file because each test has a long inline `$data = [...]` literal followed by a single SUT call. The rule fires on ≥12 body lines + exactly one apparent SUT call; extraction satisfies both conditions at once.

**Approach:** Move the setup literal out of the test body into a per-test private helper. The test becomes `setup-call → SUT-call → assertions`, which is both shorter (often below the line threshold) AND has two SUT calls visible from the test scope, so the rule's two skip conditions both fire. Helper bodies live OUTSIDE test scopes, so anything in them is invisible to test-quality rules — extracted conditionals, mocks, and reads all stop counting.

Concrete shapes that work (verified on this repo via `/tmp/extract_test_data.py`):

```php
// Before
public function testFromArrayHydratesAllFields(): void
{
    $data = [
        'text' => 'Hello, world!',
        // ... ~10 more lines
    ];
    $response = AgentResponse::fromArray($data);
    $this->assertSame('Hello, world!', $response->text);
    // ...
}

// After
public function testFromArrayHydratesAllFields(): void
{
    $data = $this->dataForFromArrayHydratesAllFields();
    $response = AgentResponse::fromArray($data);
    $this->assertSame('Hello, world!', $response->text);
    // ...
}

private function dataForFromArrayHydratesAllFields(): array
{
    return [
        'text' => 'Hello, world!',
        // ...
    ];
}
```

Pattern variants supported by the script:

| Inline shape in test body | Extracted helper |
|---|---|
| `$data = [...];` | `dataForX(): array` returning the literal |
| `$x = new Class([...]);` | `xForX(): Class` returning the constructed object |
| `$x = $this->helper([...]);` | `dataForX(): array`; call site keeps `$this->helper(...)` wrapper |
| `$x = "single-line literal";` | `rawForX(): string` returning the literal |

For multi-`new`-call setup pipelines (e.g. `MockResponse` + `MockHttpClient` + `Transport`), prefer a hand-written file-level factory helper like `transportReturning(string $body, int $statusCode = 200): SymfonyHttpTransport` rather than per-test extraction — one helper amortises across all flagged tests in the file.

**Caveat:** for tests where the inline setup IS the behaviour being verified (e.g. asserting that a specific oddly-shaped payload normalises correctly), per-test extraction moves the test logic into a helper named after a generic verb, which hurts readability. Accept those as advisory debt instead.

## Pattern: Wave-based cleanup for high-volume rule pillars

**Context:** When a pillar (e.g. `test-quality`) returns hundreds of findings across many rules with very different fix profiles — mechanical renames, semantic refactors, and noisy heuristics all mixed together.

**Approach:** Don't attempt a single sweep. Group by fix cost and value:

- **Wave 1 — easy wins:** rules with deterministic, mechanical fixes (`naming-consistency`, `exception-type-only`, `mystery-guest`, `loop-assertion-without-message`, `testdox-readability`, single-instance rules). Quick to clear, low risk.
- **Wave 2 — real smells:** rules that need per-test refactoring with real value (`mock-without-expectation`, `mock-only-test`, `conditional-logic`, `repeated-structure-missing-data-provider`, `eager-test`).
- **Wave 3 — heuristic-heavy:** rules where many findings are arguably false positives (`test-longer-than-sut`, `magic-number-assertion`, `test-method-too-long`, `global-state-mutation`). These often need threshold tuning or selective allowlisting rather than wholesale rewrites — decide policy before editing.

Run `composer test` + `composer analyse` between waves and after each rule, not just at the end. A rule sweep can quietly break tests via rename/refactor errors that surface only when the affected test runs. Verified on this repo: Wave 1 dropped `test-quality` from 529 → 459 in one session with all 593 tests green.
