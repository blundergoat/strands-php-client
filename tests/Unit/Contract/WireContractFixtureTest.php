<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Streaming\StreamEventType;
use StrandsPhpClient\Streaming\StreamParser;

final class WireContractFixtureTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/wire-contract';

    /**
     * @return iterable<string, array{string}>
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
     * @return iterable<string, array{string}>
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
     * @return iterable<string, array{string}>
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
     * @return iterable<string, array{string}>
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

    #[DataProvider('invokeResponseFixtureProvider')]
    public function testInvokeResponseFixturesParseAsAgentResponses(string $path): void
    {
        $data = self::jsonFixture($path);

        $response = AgentResponse::fromArray($data);

        $this->assertArrayHasKey('text', $data, basename($path));
        $this->assertSame($data['text'], $response->text, basename($path));
    }

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

    #[DataProvider('nonInvokeJsonFixtureProvider')]
    public function testOtherJsonFixturesAreStructuredObjects(string $path): void
    {
        $data = self::jsonFixture($path);

        $this->assertNotSame([], $data, basename($path));
    }

    #[DataProvider('streamFixtureProvider')]
    public function testStreamFixturesParseToTerminalEvents(string $path): void
    {
        $parser = new StreamParser();
        $events = $parser->feed(self::textFixture($path));

        $this->assertNotSame([], $events, basename($path));
        $this->assertSame(0, $parser->getSkippedEvents(), basename($path));

        $lastEvent = $events[array_key_last($events)];
        $this->assertTrue($lastEvent->isTerminal(), basename($path));
        $this->assertContains(
            $lastEvent->type,
            [StreamEventType::Complete, StreamEventType::Error],
            basename($path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonFixture(string $path): array
    {
        $raw = self::textFixture($path);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded, basename($path));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function textFixture(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw, basename($path));

        return $raw;
    }
}
