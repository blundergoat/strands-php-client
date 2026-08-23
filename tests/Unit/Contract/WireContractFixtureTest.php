<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

/**
 * Verifies every Wire Contract fixture remains structured and parseable through its matching client boundary.
 *
 * Use these tests when adding or changing request, response, error, custom endpoint, or stream fixtures.
 * They protect the v1 payload shapes shared by wrappers and calling applications.
 */
final class WireContractFixtureTest extends TestCase
{
    /** Shared fixture path for contract examples that app callers depend on. */
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/wire-contract';

    /**
     * Lists every invoke-response fixture that a calling application must still hydrate.
     * Use it to keep new terminal response shapes inside the executable contract suite.
     *
     * @return iterable<string, array{string}> Invoke-response paths; empty means required contract fixtures are missing.
     */
    public static function invokeResponseFixtureProvider(): iterable
    {
        $paths = glob(self::FIXTURE_DIR . '/invoke-response-*.json') ?: [];
        sort($paths);

        // Each invoke-response fixture represents one terminal answer shape a consuming app must keep parsing.
        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    /**
     * Lists every invoke-request fixture that a wrapper must accept from calling applications.
     * Use it to keep new request envelopes inside the executable contract suite.
     *
     * @return iterable<string, array{string}> Invoke-request paths; empty means required contract fixtures are missing.
     */
    public static function invokeRequestFixtureProvider(): iterable
    {
        $paths = glob(self::FIXTURE_DIR . '/invoke-request-*.json') ?: [];
        sort($paths);

        // Each invoke-request fixture represents one prompt envelope an app may send to its wrapper.
        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    /**
     * Lists every stream fixture whose terminal event sequence must remain parseable.
     * Use it when adding a caller-visible typed or error stream profile.
     *
     * @return iterable<string, array{string}> Stream fixture paths; empty means required contract fixtures are missing.
     */
    public static function streamFixtureProvider(): iterable
    {
        $paths = glob(self::FIXTURE_DIR . '/stream-*.sse') ?: [];
        sort($paths);

        // Each stream fixture represents one event sequence a live answer UI must accept.
        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    /**
     * Lists custom JSON response fixtures that remain outside typed invoke and error checks.
     * Use it to verify domain-specific endpoint objects stay structurally valid.
     *
     * @return iterable<string, array{string}> Custom JSON paths; empty means no domain-specific fixtures are registered.
     */
    public static function nonInvokeJsonFixtureProvider(): iterable
    {
        $paths = glob(self::FIXTURE_DIR . '/*.json') ?: [];
        sort($paths);

        // Walk every JSON fixture, then leave the invoke and error files to their more specific tests below.
        foreach ($paths as $path) {
            $filename = basename($path);
            // Invoke and error fixtures have dedicated assertions, so this provider keeps only custom response objects.
            if (
                str_starts_with($filename, 'invoke-request-')
                || str_starts_with($filename, 'invoke-response-')
                || $filename === 'error-response.json'
            ) {
                continue;
            }

            yield $filename => [$path];
        }
    }

    /**
     * Confirms invoke response fixtures parse as agent responses so existing apps remain compatible with Wire Contract v1.
     *
     * @param string $path Fixture path supplied by the data provider.
     * @return void
     */
    #[DataProvider('invokeResponseFixtureProvider')]
    public function testInvokeResponseFixturesParseAsAgentResponses(string $path): void
    {
        $fixtureData = self::loadJsonFixture($path);

        $response = AgentResponse::fromArray($fixtureData);

        $this->assertArrayHasKey('text', $fixtureData, basename($path));
        $this->assertSame($fixtureData['text'], $response->text, basename($path));
    }

    /**
     * Confirms invoke request fixtures are valid request envelopes so existing apps remain compatible with Wire Contract v1.
     *
     * @param string $path Fixture path supplied by the data provider.
     * @return void
     */
    #[DataProvider('invokeRequestFixtureProvider')]
    public function testInvokeRequestFixturesAreValidRequestEnvelopes(string $path): void
    {
        $fixtureData = self::loadJsonFixture($path);

        $this->assertArrayHasKey('message', $fixtureData, basename($path));
        $this->assertTrue(
            is_string($fixtureData['message']) || is_array($fixtureData['message']),
            sprintf('%s message must be a string or rich message object', basename($path)),
        );

        // A session ID is optional, but when present it must be a string the app can reuse on the next turn.
        if (isset($fixtureData['session_id'])) {
            $this->assertIsString($fixtureData['session_id'], basename($path));
        }

        // Optional context must remain an object so callers can safely add it to a follow-up request.
        if (isset($fixtureData['context'])) {
            $this->assertIsArray($fixtureData['context'], basename($path));
        }
    }

    /**
     * Confirms error response fixture is structured JSON so existing apps remain compatible with Wire Contract v1.
     *
     * @return void
     */
    public function testErrorResponseFixtureIsStructuredJson(): void
    {
        $fixtureData = self::loadJsonFixture(self::FIXTURE_DIR . '/error-response.json');

        $this->assertArrayHasKey('message', $fixtureData);
        $this->assertIsString($fixtureData['message']);

        // A wrapper may omit its machine code, but a present code must be safe for app-side branching.
        if (isset($fixtureData['code'])) {
            $this->assertIsString($fixtureData['code']);
        }

        // Structured detail is optional; when present it must remain an object the error UI can inspect.
        if (isset($fixtureData['detail'])) {
            $this->assertIsArray($fixtureData['detail']);
        }
    }

    /**
     * Confirms other JSON fixtures are structured objects so existing apps remain compatible with Wire Contract v1.
     *
     * @param string $path Fixture path supplied by the data provider.
     * @return void
     */
    #[DataProvider('nonInvokeJsonFixtureProvider')]
    public function testOtherJsonFixturesAreStructuredObjects(string $path): void
    {
        $fixtureData = self::loadJsonFixture($path);

        $this->assertNotSame([], $fixtureData, basename($path));
    }

    /**
     * Confirms stream() fixtures parse to terminal events so existing apps remain compatible with Wire Contract v1.
     *
     * @param string $path Fixture path supplied by the data provider.
     * @return void
     */
    #[DataProvider('streamFixtureProvider')]
    public function testStreamFixturesParseToTerminalEvents(string $path): void
    {
        $streamParser = new StreamParser();
        $events = $streamParser->feed(self::loadTextFixture($path));

        $this->assertNotSame([], $events, basename($path));
        $this->assertSame(0, $streamParser->getSkippedEvents(), basename($path));

        $lastEvent = $events[array_key_last($events)];
        $this->assertTrue($lastEvent->isTerminal(), basename($path));
        $this->assertContains(
            $lastEvent->type,
            [StreamEventType::Complete, StreamEventType::Error],
            basename($path),
        );
    }

    /**
     * Decodes one Wire Contract JSON file after proving its contents are readable.
     * Use it when a fixture represents a request, response, error, or custom endpoint object.
     *
     * @param string $path Full fixture path; empty cannot identify a contract example.
     * @return array<string, mixed> Decoded fixture fields; an empty object remains a valid explicit fixture.
     */
    private static function loadJsonFixture(string $path): array
    {
        $raw = self::loadTextFixture($path);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded, basename($path));

        /** @var array<string, mixed> $decoded validated before app code uses it. */
        return $decoded;
    }

    /**
     * Loads raw JSON or SSE fixture bytes before decoding or stream parsing.
     * Use it to keep filesystem validation in one place for every contract example.
     *
     * @param string $path Full fixture path; empty cannot identify a contract example.
     * @return string Fixture bytes; never empty for the committed Wire Contract examples.
     */
    private static function loadTextFixture(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw, basename($path));

        return $raw;
    }
}
