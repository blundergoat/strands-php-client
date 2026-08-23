<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Context\AgentInput;

/**
 * Verifies AgentInput preserves caller choices while producing the Wire Contract request shape.
 *
 * It protects users attaching images, documents, videos, or interrupt responses to a turn.
 * Use these scenarios when changing builder names, signatures, immutability, or serialization.
 */
class AgentInputTest extends TestCase
{
    /**
     * Verifies text-only input serializes as a string, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testTextOnlyReturnsString(): void
    {
        $input = AgentInput::text('Hello, agent!');

        $this->assertSame('Hello, agent!', $input->toPayloadValue());
        $this->assertSame('Hello, agent!', $input->getText());
    }

    /**
     * Verifies withImage() returns content blocks, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithImageReturnsContentBlocks(): void
    {
        $input = AgentInput::text("What's in this image?")
            ->withImage('base64data', 'image/png');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('content', $payload);
        $this->assertCount(2, $payload['content']);

        $this->assertSame('text', $payload['content'][0]['type']);
        $this->assertSame("What's in this image?", $payload['content'][0]['text']);

        $this->assertSame('image', $payload['content'][1]['type']);
        $this->assertSame('png', $payload['content'][1]['format']);
        $this->assertSame('base64', $payload['content'][1]['source']['type']);
        $this->assertSame('image/png', $payload['content'][1]['source']['media_type']);
        $this->assertSame('base64data', $payload['content'][1]['source']['data']);
    }

    /**
     * Verifies withStructuredOutputPrompt() appends the requested schema prompt, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithStructuredOutputPrompt(): void
    {
        $input = AgentInput::text('Extract entities')
            ->withStructuredOutputPrompt('Return JSON with key "entities"');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('Return JSON with key "entities"', $payload['structured_output_prompt']);
    }

    /**
     * Verifies withImage() returns a new input without mutating the original, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithImageReturnsNewInstanceAndPreservesOriginal(): void
    {
        $original = AgentInput::text('Hello');
        $withImage = $original->withImage('data', 'image/jpeg');

        // The original builder still represents the user's untouched text-only request.
        $this->assertSame('Hello', $original->toPayloadValue());
        // The returned copy carries the image the user added without changing that original request.
        $this->assertIsArray($withImage->toPayloadValue());

        // A separate instance lets UI code safely retain either step of an immutable form-building flow.
        $this->assertNotSame($original, $withImage);
    }

    /**
     * Verifies withStructuredOutputPrompt() returns a new input without mutating the original, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithStructuredOutputPromptReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withPrompt = $original->withStructuredOutputPrompt('JSON');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withPrompt->toPayloadValue());
        $this->assertNotSame($original, $withPrompt);
    }

    /**
     * Verifies multiple content blocks preserve their order, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testMultipleContentBlocks(): void
    {
        $input = AgentInput::text('Analyse all of these')
            ->withImage('img1', 'image/png')
            ->withImage('img2', 'image/jpeg')
            ->withDocument('doc1', 'pdf', 'report.pdf');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        // The final request preserves the user's text, two images, and document as four ordered blocks.
        $this->assertCount(4, $payload['content']);
        $this->assertSame('text', $payload['content'][0]['type']);
        $this->assertSame('image', $payload['content'][1]['type']);
        $this->assertSame('image', $payload['content'][2]['type']);
        $this->assertSame('document', $payload['content'][3]['type']);
    }

    /**
     * Verifies an interrupt response serializes for the next agent turn, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testInterruptResponse(): void
    {
        $input = AgentInput::interruptResponse('int-abc-123', 'Approved');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        // An approval click can resume the agent with only an interrupt-response block and no chat text.
        $this->assertCount(1, $payload['content']);
        $this->assertSame('interrupt_response', $payload['content'][0]['type']);
        $this->assertSame('int-abc-123', $payload['content'][0]['interrupt_id']);
        $this->assertSame('Approved', $payload['content'][0]['response']);
    }

    /**
     * Verifies a structured-output-only input serializes as an array, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testStructuredOutputPromptOnlyMakesArray(): void
    {
        $input = AgentInput::text('Extract')
            ->withStructuredOutputPrompt('JSON only');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('content', $payload);
        $this->assertArrayHasKey('structured_output_prompt', $payload);
    }

    /**
     * Verifies withImageFromS3() keeps the selected bucket, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithImageFromS3Bucket(): void
    {
        $input = AgentInput::text('Analyse this image')
            ->withImageFromS3('s3://my-bucket/photo.png', 'png');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('image', $payload['content'][1]['type']);
        $this->assertSame('s3_location', $payload['content'][1]['source']['type']);
        $this->assertSame('s3://my-bucket/photo.png', $payload['content'][1]['source']['uri']);
        $this->assertSame('png', $payload['content'][1]['format']);
        $this->assertArrayNotHasKey('bucket_owner', $payload['content'][1]['source']);
    }

    /**
     * Verifies withImageFromS3() keeps the selected bucket owner, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithImageFromS3WithBucketOwner(): void
    {
        $input = AgentInput::text('Test')
            ->withImageFromS3('s3://b/img.jpg', 'jpeg', '111222333');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('111222333', $payload['content'][1]['source']['bucket_owner']);
    }

    /**
     * Verifies withImageFromS3() returns a new input without mutating the original, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithImageFromS3ReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withS3 = $original->withImageFromS3('s3://b/k.png', 'png');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withS3->toPayloadValue());
        $this->assertNotSame($original, $withS3);
    }

    /**
     * Verifies withImageFromUrl() keeps the selected URL, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithImageFromUrl(): void
    {
        $input = AgentInput::text('Analyse')
            ->withImageFromUrl('https://example.com/photo.png', 'image/png');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('image', $payload['content'][1]['type']);
        $this->assertSame('png', $payload['content'][1]['format']);
        $this->assertSame('url', $payload['content'][1]['source']['type']);
        $this->assertSame('https://example.com/photo.png', $payload['content'][1]['source']['url']);
        $this->assertSame('image/png', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies withImage() strips MIME parameters before deriving format, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithImageStripsMimeParametersBeforeDerivingFormat(): void
    {
        $input = AgentInput::text('Analyse')
            ->withImage('base64data', 'image/jpeg; charset=binary');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('jpeg', $payload['content'][1]['format']);
        $this->assertSame('image/jpeg; charset=binary', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies image format derivation sends each upload MIME variant in the wire format the agent expects.
     *
     * @param string $mediaType Non-empty MIME type or format supplied by the upload flow.
     * @param string $expectedFormat Non-empty normalized image format sent to the agent.
     * @return void
     */
    #[DataProvider('imageFormatProvider')]
    public function testWithImageDerivesFormat(string $mediaType, string $expectedFormat): void
    {
        $input = AgentInput::text('Describe this')
            ->withImage('base64data', $mediaType);

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame($expectedFormat, $payload['content'][1]['format']);
    }

    /**
     * Supplies image MIME variants that exercise normalization without duplicating test structure.
     *
     * @return iterable<string, array{0: string, 1: string}> Non-empty MIME and wire-format cases for image uploads.
     */
    public static function imageFormatProvider(): iterable
    {
        yield 'normalizes MIME casing and whitespace' => [' IMAGE/JPEG ; charset=binary', 'jpeg'];
        yield 'accepts format without MIME prefix' => ['PNG', 'png'];
        yield 'preserves compound subtype format' => ['image/svg+xml', 'svg+xml'];
    }

    /**
     * Verifies withImageFromUrl() returns a new input without mutating the original, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithImageFromUrlReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withUrl = $original->withImageFromUrl('https://x/img.png', 'image/png');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withUrl->toPayloadValue());
        $this->assertNotSame($original, $withUrl);
    }

    /**
     * Verifies withCachePoint() adds a cache-point content block, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithCachePointAddsCachePointBlock(): void
    {
        $input = AgentInput::text('Remember this')
            ->withCachePoint(ttl: '5m');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('cache_point', $payload['content'][1]['type']);
        $this->assertSame('default', $payload['content'][1]['cache_type']);
        $this->assertSame('5m', $payload['content'][1]['ttl']);
    }

    /**
     * Verifies withCachePoint() returns a new input without mutating the original, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testWithCachePointReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $originalPayload = $original->toPayloadValue();
        $withCache = $original->withCachePoint(ttl: '5m');

        $this->assertNotSame($original, $withCache);
        $this->assertSame($originalPayload, $original->toPayloadValue());
        $this->assertSame('Hello', $original->toPayloadValue());

        $cachedPayload = $withCache->toPayloadValue();
        $this->assertIsArray($cachedPayload);
        $this->assertCount(2, $cachedPayload['content']);
        $this->assertSame('cache_point', $cachedPayload['content'][1]['type']);
    }

    /**
     * Verifies chained media builders preserve every content block, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testMixedMediaTypesChaining(): void
    {
        $input = AgentInput::text('Analyse all')
            ->withImage('img1', 'image/png')
            ->withImageFromS3('s3://b/img2.jpg', 'jpeg')
            ->withImageFromUrl('https://x/img3.png', 'image/png')
            ->withVideo('vid1', 'mp4')
            ->withVideoFromUrl('https://x/clip.mp4', 'mp4')
            ->withDocumentFromUrl('https://x/doc.pdf', 'pdf', 'doc');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        // The mixed-media request keeps the user's text plus all six attachments in one ordered content list.
        $this->assertCount(7, $payload['content']);
        $this->assertSame('text', $payload['content'][0]['type']);
        $this->assertSame('image', $payload['content'][1]['type']);
        $this->assertSame('image', $payload['content'][2]['type']);
        $this->assertSame('image', $payload['content'][3]['type']);
        $this->assertSame('video', $payload['content'][4]['type']);
        $this->assertSame('video', $payload['content'][5]['type']);
        $this->assertSame('document', $payload['content'][6]['type']);
    }
}
