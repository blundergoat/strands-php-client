<?php

declare(strict_types=1);

/**
 * Tests caller-visible Wire Contract Fixture behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

/**
 * Verifies Wire Contract Fixture behavior that application users rely on.
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

        foreach ($paths as $path) {
            $filename = basename($path);
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
     * Verifies that invoke response fixtures parse as agent responses.
     *
     * @param string $path Fixture path supplied by the data provider.
     * @return void
     */
    #[DataProvider('invokeResponseFixtureProvider')]
    public function testInvokeResponseFixturesParseAsAgentResponses(string $path): void
    {
        $data = self::jsonFixture($path);

        $response = AgentResponse::fromArray($data);

        $this->assertArrayHasKey('text', $data, basename($path));
        $this->assertSame($data['text'], $response->text, basename($path));
    }

    /**
     * Verifies that invoke request fixtures are valid request envelopes.
     *
     * @param string $path Fixture path supplied by the data provider.
     * @return void
     */
    #[DataProvider('invokeRequestFixtureProvider')]
    public function testInvokeRequestFixturesAreValidRequestEnvelopes(string $path): void
    {
        $data = self::jsonFixture($path);

        $this->assertArrayHasKey('message', $data, basename($path));
        $this->assertTrue(
            is_string($data['message']) || is_array($data['message']),
            sprintf('%s message must be a string or rich message object', basename($path)),
        );

        if (isset($data['session_id'])) {
            $this->assertIsString($data['session_id'], basename($path));
        }

        if (isset($data['context'])) {
            $this->assertIsArray($data['context'], basename($path));
        }
    }

    /**
     * Verifies that error response fixture is structured JSON.
     *
     * @return void
     */
    public function testErrorResponseFixtureIsStructuredJson(): void
    {
        $data = self::jsonFixture(self::FIXTURE_DIR . '/error-response.json');

        $this->assertArrayHasKey('message', $data);
        $this->assertIsString($data['message']);

        if (isset($data['code'])) {
            $this->assertIsString($data['code']);
        }

        if (isset($data['detail'])) {
            $this->assertIsArray($data['detail']);
        }
    }

    /**
     * Verifies that other JSON fixtures are structured objects.
     *
     * @param string $path Fixture path supplied by the data provider.
     * @return void
     */
    #[DataProvider('nonInvokeJsonFixtureProvider')]
    public function testOtherJsonFixturesAreStructuredObjects(string $path): void
    {
        $data = self::jsonFixture($path);

        $this->assertNotSame([], $data, basename($path));
    }

    /**
     * Verifies that stream fixtures parse to terminal events.
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
