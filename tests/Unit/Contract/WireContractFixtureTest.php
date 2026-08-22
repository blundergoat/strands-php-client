<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Wire Contract Fixture behavior for app integrations.
 *
 * Use this file when changing Wire Contract Fixture or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

/**
 * Exercises Wire Contract Fixture through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
final class WireContractFixtureTest extends TestCase
{
    /** Shared fixture path for contract examples that app callers depend on. */
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/wire-contract';

    /**
     * Provides app-facing scenarios for invoke response fixture.
     *
     * @return iterable<string, array{string}> Scenario data for invoke response fixture behavior.
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
     * Provides app-facing scenarios for invoke request fixture.
     *
     * @return iterable<string, array{string}> Scenario data for invoke request fixture behavior.
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
     * Provides app-facing scenarios for stream fixture.
     *
     * @return iterable<string, array{string}> Scenario data for stream fixture behavior.
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
     * Provides app-facing scenarios for non invoke json fixture.
     *
     * @return iterable<string, array{string}> Scenario data for non invoke json fixture behavior.
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
        $fixtureData = self::jsonFixture($path);

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
        $fixtureData = self::jsonFixture($path);

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
        $fixtureData = self::jsonFixture(self::FIXTURE_DIR . '/error-response.json');

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
        $fixtureData = self::jsonFixture($path);

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
        $events = $streamParser->feed(self::textFixture($path));

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
     * Supports the json fixture step in the app-facing flow.
     *
     * @param string $path request path that becomes part of the signed URL.
     * @return array<string, mixed> Fixture data used to verify the public wire contract.
     */
    private static function jsonFixture(string $path): array
    {
        $raw = self::textFixture($path);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded, basename($path));

        /** @var array<string, mixed> $decoded validated before app code uses it. */
        return $decoded;
    }

    /**
     * Handle text fixture.
     *
     * @param string $path Fixture path supplied by the data provider.
     * @return string String value produced by the helper.
     */
    private static function textFixture(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw, basename($path));

        return $raw;
    }
}
