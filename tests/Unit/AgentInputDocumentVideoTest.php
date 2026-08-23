<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Context\AgentInput;
use StrandsPhpClient\Tests\Fixtures\Compatibility\V1AgentInputExtension;

/**
 * Verifies document and video builders preserve the source, format, and options selected by the calling app.
 *
 * Use these tests when changing rich attachment serialization or immutable builder behavior.
 * They protect uploads and S3 or URL references before the request reaches the agent.
 */
class AgentInputDocumentVideoTest extends TestCase
{
    /**
     * Verifies withDocument() returns content blocks, preserving the request content selected by the caller.
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
     * Verifies withDocumentFromS3() keeps the selected bucket, preserving the request content selected by the caller.
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
     * Verifies withDocumentFromS3() keeps the selected bucket owner, preserving the request content selected by the caller.
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
     * Verifies withVideoFromS3() keeps the selected bucket, preserving the request content selected by the caller.
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
     * Verifies withDocument() returns a new input without mutating the original, preserving the request content selected by the caller.
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
     * Verifies withDocumentFromS3() returns a new input without mutating the original, preserving the request content selected by the caller.
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
     * Verifies withVideoFromS3() returns a new input without mutating the original, preserving the request content selected by the caller.
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
     * Verifies withDocumentFromS3() adds the bucket-owner condition only when supplied, preserving the request content selected by the caller.
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
     * Verifies withVideoFromS3() adds the bucket-owner condition only when supplied, preserving the request content selected by the caller.
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
     * Verifies withDocument() derives the document format from its media type, preserving the request content selected by the caller.
     *
     * @param string $extension Non-empty document extension passed to withDocument().
     * @param string $filename Non-empty display filename; media-type resolution does not inspect it.
     * @param string $expectedMediaType Non-empty MIME type the agent payload must carry.
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
     * Lists document extensions and the media types callers expect in the request payload.
     * An empty provider would leave uploaded document formats unverified.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}> Non-empty document-format cases for caller uploads.
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
     * Verifies withVideo() serializes a video content block, preserving the request content selected by the caller.
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
     * Verifies withVideo() returns a new input without mutating the original, preserving the request content selected by the caller.
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
     * Verifies withDocumentFromUrl() keeps the selected URL, preserving the request content selected by the caller.
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
     * Verifies withDocumentFromUrl() returns a new input without mutating the original, preserving the request content selected by the caller.
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
     * Verifies withDocument() preserves context and citation options, preserving the request content selected by the caller.
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
     * Verifies withDocumentFromS3() preserves context and citation options, preserving the request content selected by the caller.
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
     * Verifies the 1.x document-builder signatures remain override compatible, preserving the request content selected by the caller.
     *
     * @return void
     */
    public function testV1DocumentBuilderSignaturesRemainOverrideCompatible(): void
    {
        $this->assertTrue(is_subclass_of(V1AgentInputExtension::class, AgentInput::class));
    }

    /**
     * Verifies withDocumentFromUrl() preserves context and citation options, preserving the request content selected by the caller.
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
     * Verifies withVideoFromUrl() keeps the selected URL, preserving the request content selected by the caller.
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
     * Verifies withVideoFromUrl() returns a new input without mutating the original, preserving the request content selected by the caller.
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
}
