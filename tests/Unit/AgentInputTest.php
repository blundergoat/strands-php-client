<?php

declare(strict_types=1);

/**
 * Exercises the rich-input payloads an application builds from user text and attachments.
 *
 * It covers immutable chaining, media sources, cache points, and document options.
 * Failures here mean the wrapper could receive a different request than the UI assembled.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Context\AgentInput;
use StrandsPhpClient\Tests\Fixtures\Compatibility\V1AgentInputExtension;

/**
 * Verifies AgentInput preserves caller choices while producing the Wire Contract request shape.
 *
 * It protects users attaching images, documents, videos, or interrupt responses to a turn.
 * Use these scenarios when changing builder names, signatures, immutability, or serialization.
 */
class AgentInputTest extends TestCase
{
    /**
     * Protects "text only returns string" so the agent receives the request the user assembled.
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
     * Protects "with image returns content blocks" so the agent receives the request the user assembled.
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
     * Protects "with document returns content blocks" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentReturnsContentBlocks(): void
    {
        $input = AgentInput::text('Summarise this')
            ->withDocument('pdfdata', 'pdf', 'report.pdf');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('document', $payload['content'][1]['type']);
        $this->assertSame('base64', $payload['content'][1]['source']['type']);
        $this->assertSame('application/pdf', $payload['content'][1]['source']['media_type']);
        $this->assertSame('pdfdata', $payload['content'][1]['source']['data']);
        $this->assertSame('report.pdf', $payload['content'][1]['name']);
    }

    /**
     * Protects "with document from s3 bucket" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentFromS3Bucket(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocumentFromS3('s3://my-bucket/report.pdf', 'pdf', 'report');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('document', $payload['content'][1]['type']);
        $this->assertSame('s3_location', $payload['content'][1]['source']['type']);
        $this->assertSame('s3://my-bucket/report.pdf', $payload['content'][1]['source']['uri']);
        $this->assertSame('pdf', $payload['content'][1]['format']);
        $this->assertSame('report', $payload['content'][1]['name']);
        $this->assertArrayNotHasKey('bucket_owner', $payload['content'][1]['source']);
    }

    /**
     * Protects "with document from s3 with bucket owner" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentFromS3WithBucketOwner(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocumentFromS3('s3://bucket/doc.pdf', 'pdf', 'doc', '123456789');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('123456789', $payload['content'][1]['source']['bucket_owner']);
    }

    /**
     * Protects "with video from s3 bucket" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithVideoFromS3Bucket(): void
    {
        $input = AgentInput::text('What is this video about?')
            ->withVideoFromS3('s3://bucket/clip.mp4', 'mp4');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('video', $payload['content'][1]['type']);
        $this->assertSame('s3_location', $payload['content'][1]['source']['type']);
        $this->assertSame('s3://bucket/clip.mp4', $payload['content'][1]['source']['uri']);
        $this->assertSame('mp4', $payload['content'][1]['format']);
    }

    /**
     * Protects "with structured output prompt" so the agent receives the request the user assembled.
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
     * Protects "with image returns new instance and preserves original" so the agent receives the request the user assembled.
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
     * Protects "with document returns new instance" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withDoc = $original->withDocument('data', 'pdf', 'doc.pdf');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withDoc->toPayloadValue());
        $this->assertNotSame($original, $withDoc);
    }

    /**
     * Protects "with document from s3 returns new instance" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentFromS3ReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withS3 = $original->withDocumentFromS3('s3://b/k', 'pdf', 'doc');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withS3->toPayloadValue());
        $this->assertNotSame($original, $withS3);
    }

    /**
     * Protects "with video from s3 returns new instance" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithVideoFromS3ReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withVideo = $original->withVideoFromS3('s3://b/k', 'mp4');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withVideo->toPayloadValue());
        $this->assertNotSame($original, $withVideo);
    }

    /**
     * Protects "with structured output prompt returns new instance" so the agent receives the request the user assembled.
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
     * Protects "with document from s3 bucket owner condition" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentFromS3BucketOwnerCondition(): void
    {
        // A same-account S3 document omits the owner field the user did not need to provide.
        $sameAccountDocumentInput = AgentInput::text('Test')
            ->withDocumentFromS3('s3://b/k', 'pdf', 'doc', null);
        $sameAccountDocumentPayload = $sameAccountDocumentInput->toPayloadValue();
        $this->assertArrayNotHasKey('bucket_owner', $sameAccountDocumentPayload['content'][1]['source']);

        // A cross-account S3 document retains the owner ID collected by the upload flow.
        $crossAccountDocumentInput = AgentInput::text('Test')
            ->withDocumentFromS3('s3://b/k', 'pdf', 'doc', '123');
        $crossAccountDocumentPayload = $crossAccountDocumentInput->toPayloadValue();
        $this->assertSame('123', $crossAccountDocumentPayload['content'][1]['source']['bucket_owner']);
    }

    /**
     * Protects "with video from s3 bucket owner condition" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithVideoFromS3BucketOwnerCondition(): void
    {
        // A same-account S3 video omits the owner field the user did not need to provide.
        $sameAccountVideoInput = AgentInput::text('Test')
            ->withVideoFromS3('s3://b/clip.mp4', 'mp4');
        $sameAccountVideoPayload = $sameAccountVideoInput->toPayloadValue();
        $this->assertArrayNotHasKey('bucket_owner', $sameAccountVideoPayload['content'][1]['source']);

        // A cross-account S3 video retains the owner ID collected by the upload flow.
        $crossAccountVideoInput = AgentInput::text('Test')
            ->withVideoFromS3('s3://b/clip.mp4', 'mp4', '999');
        $crossAccountVideoPayload = $crossAccountVideoInput->toPayloadValue();
        $this->assertSame('999', $crossAccountVideoPayload['content'][1]['source']['bucket_owner']);
    }

    /**
     * Protects "with document resolves media type for format" so the agent receives the request the user assembled.
     *
     * @param string $extension Document file extension passed to withDocument().
     * @param string $filename Cosmetic filename argument (unused by media-type resolution).
     * @param string $expectedMediaType MIME type the payload must carry for this extension.
     * @return void
     */
    #[DataProvider('documentFormatProvider')]
    public function testWithDocumentResolvesMediaTypeForFormat(string $extension, string $filename, string $expectedMediaType): void
    {
        $input = AgentInput::text('Read this')
            ->withDocument('data', $extension, $filename);

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame($expectedMediaType, $payload['content'][1]['source']['media_type']);
    }

    /**
     * Supplies the input variants for the related request-building scenario.
     * An empty provider would leave a caller-visible edge case unverified.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}> Document format cases that keep rich user input encoded correctly.
     */
    public static function documentFormatProvider(): iterable
    {
        yield 'txt' => ['txt', 'notes.txt', 'text/plain'];
        yield 'csv' => ['csv', 'data.csv', 'text/csv'];
        yield 'html' => ['html', 'page.html', 'text/html'];
        yield 'docx' => ['docx', 'report.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        yield 'json' => ['json', 'schema.json', 'application/json'];
        yield 'yaml' => ['yaml', 'config.yaml', 'application/yaml'];
        yield 'yml' => ['yml', 'config.yml', 'application/yaml'];
        yield 'xlsx' => ['xlsx', 'data.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        yield 'xls' => ['xls', 'data.xls', 'application/vnd.ms-excel'];
        yield 'pptx' => ['pptx', 'deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        yield 'ppt' => ['ppt', 'deck.ppt', 'application/vnd.ms-powerpoint'];
        yield 'doc' => ['doc', 'letter.doc', 'application/msword'];
        yield 'rtf' => ['rtf', 'notes.rtf', 'application/rtf'];
        yield 'md' => ['md', 'readme.md', 'text/markdown'];
        yield 'xml' => ['xml', 'config.xml', 'application/xml'];
        yield 'unknown extension falls back to application/<ext>' => ['parquet', 'data.parquet', 'application/parquet'];
    }

    /**
     * Protects "multiple content blocks" so the agent receives the request the user assembled.
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
     * Protects "interrupt response" so the agent receives the request the user assembled.
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
     * Protects "structured output prompt only makes array" so the agent receives the request the user assembled.
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
     * Protects "with image from s3 bucket" so the agent receives the request the user assembled.
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
     * Protects "with image from s3 with bucket owner" so the agent receives the request the user assembled.
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
     * Protects "with image from s3 returns new instance" so the agent receives the request the user assembled.
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
     * Protects "with video" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithVideo(): void
    {
        $input = AgentInput::text('What is in this video?')
            ->withVideo('base64videodata', 'mp4');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('video', $payload['content'][1]['type']);
        $this->assertSame('base64', $payload['content'][1]['source']['type']);
        $this->assertSame('video/mp4', $payload['content'][1]['source']['media_type']);
        $this->assertSame('base64videodata', $payload['content'][1]['source']['data']);
        $this->assertSame('mp4', $payload['content'][1]['format']);
    }

    /**
     * Protects "with video returns new instance" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithVideoReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withVideo = $original->withVideo('data', 'mp4');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withVideo->toPayloadValue());
        $this->assertNotSame($original, $withVideo);
    }

    /**
     * Protects "with image from url" so the agent receives the request the user assembled.
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
     * Protects "with image strips mime parameters before deriving format" so the agent receives the request the user assembled.
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
     * Protects "with image normalizes mime casing and whitespace" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithImageNormalizesMimeCasingAndWhitespace(): void
    {
        $input = AgentInput::text('Describe this')
            ->withImage('base64data', ' IMAGE/JPEG ; charset=binary');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('jpeg', $payload['content'][1]['format']);
    }

    /**
     * Protects "with image accepts format without mime prefix" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithImageAcceptsFormatWithoutMimePrefix(): void
    {
        $input = AgentInput::text('Describe this')
            ->withImage('base64data', 'PNG');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('png', $payload['content'][1]['format']);
    }

    /**
     * Protects "with image preserves compound subtype format" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithImagePreservesCompoundSubtypeFormat(): void
    {
        $input = AgentInput::text('Analyse')
            ->withImage('base64data', 'image/svg+xml');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('svg+xml', $payload['content'][1]['format']);
    }

    /**
     * Protects "with image from url returns new instance" so the agent receives the request the user assembled.
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
     * Protects "with document from url" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentFromUrl(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocumentFromUrl('https://example.com/report.pdf', 'pdf', 'report');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('document', $payload['content'][1]['type']);
        $this->assertSame('url', $payload['content'][1]['source']['type']);
        $this->assertSame('https://example.com/report.pdf', $payload['content'][1]['source']['url']);
        $this->assertSame('application/pdf', $payload['content'][1]['source']['media_type']);
        $this->assertSame('pdf', $payload['content'][1]['format']);
        $this->assertSame('report', $payload['content'][1]['name']);
    }

    /**
     * Protects "with document from url returns new instance" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentFromUrlReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withUrl = $original->withDocumentFromUrl('https://x/doc.pdf', 'pdf', 'doc');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withUrl->toPayloadValue());
        $this->assertNotSame($original, $withUrl);
    }

    /**
     * Protects "with document supports context and citation options" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentSupportsContextAndCitationOptions(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocumentOptions('pdfdata', 'pdf', 'report.pdf', 'Referral context', ['enabled' => true]);

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('Referral context', $payload['content'][1]['context']);
        $this->assertSame(['enabled' => true], $payload['content'][1]['citations']);
    }

    /**
     * Protects "with document from s3 options supports context and citations" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentFromS3OptionsSupportsContextAndCitations(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocumentFromS3Options(
                s3Uri: 's3://bucket/report.pdf',
                format: 'pdf',
                name: 'report.pdf',
                bucketOwner: '123456789012',
                context: 'Referral context',
                citations: ['enabled' => true],
            );

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('123456789012', $payload['content'][1]['source']['bucket_owner']);
        $this->assertSame('Referral context', $payload['content'][1]['context']);
        $this->assertSame(['enabled' => true], $payload['content'][1]['citations']);
    }

    /**
     * Protects "v1 document builder signatures remain override compatible" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testV1DocumentBuilderSignaturesRemainOverrideCompatible(): void
    {
        $this->assertTrue(is_subclass_of(V1AgentInputExtension::class, AgentInput::class));
    }

    /**
     * Protects "with document from url supports context and citation options" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithDocumentFromUrlSupportsContextAndCitationOptions(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocumentFromUrl('https://example.com/report.pdf', 'pdf', 'report', 'URL context', ['enabled' => true]);

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('URL context', $payload['content'][1]['context']);
        $this->assertSame(['enabled' => true], $payload['content'][1]['citations']);
    }

    /**
     * Protects "with cache point adds cache point block" so the agent receives the request the user assembled.
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
     * Protects "with cache point returns new instance" so the agent receives the request the user assembled.
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
     * Protects "with video from url" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithVideoFromUrl(): void
    {
        $input = AgentInput::text('Describe')
            ->withVideoFromUrl('https://example.com/clip.mp4', 'mp4');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('video', $payload['content'][1]['type']);
        $this->assertSame('url', $payload['content'][1]['source']['type']);
        $this->assertSame('https://example.com/clip.mp4', $payload['content'][1]['source']['url']);
        $this->assertSame('video/mp4', $payload['content'][1]['source']['media_type']);
        $this->assertSame('mp4', $payload['content'][1]['format']);
    }

    /**
     * Protects "with video from url returns new instance" so the agent receives the request the user assembled.
     *
     * @return void
     */
    public function testWithVideoFromUrlReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withUrl = $original->withVideoFromUrl('https://x/clip.mp4', 'mp4');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withUrl->toPayloadValue());
        $this->assertNotSame($original, $withUrl);
    }

    /**
     * Protects "mixed media types chaining" so the agent receives the request the user assembled.
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
