<?php

declare(strict_types=1);

/**
 * Tests caller-visible Agent Input behavior for app integrations.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Context\AgentInput;

/**
 * Verifies Agent Input behavior that application users rely on.
 */
class AgentInputTest extends TestCase
{
    /**
     * Verifies that text only returns string.
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
     * Verifies that with image returns content blocks.
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
     * Verifies that with document returns content blocks.
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
     * Verifies that with document from s 3.
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
     * Verifies that with document from s 3 with bucket owner.
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
     * Verifies that with video from s 3.
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
     * Verifies that with structured output prompt.
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
     * Verifies that withImage returns a new instance and leaves the original unchanged.
     *
     * @return void
     */
    public function testWithImageReturnsNewInstanceAndPreservesOriginal(): void
    {
        $original = AgentInput::text('Hello');
        $withImage = $original->withImage('data', 'image/jpeg');

        // Original should still return plain string
        $this->assertSame('Hello', $original->toPayloadValue());
        // Modified should have content blocks
        $this->assertIsArray($withImage->toPayloadValue());

        // Clone must be a different instance
        $this->assertNotSame($original, $withImage);
    }

    /**
     * Verifies that with document returns new instance.
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
     * Verifies that with document from s 3 returns new instance.
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
     * Verifies that with video from s 3 returns new instance.
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
     * Verifies that with structured output prompt returns new instance.
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
     * Verifies that with document from s 3 bucket owner condition.
     *
     * @return void
     */
    public function testWithDocumentFromS3BucketOwnerCondition(): void
    {
        // Without bucket owner
        $input1 = AgentInput::text('Test')
            ->withDocumentFromS3('s3://b/k', 'pdf', 'doc', null);
        $payload1 = $input1->toPayloadValue();
        $this->assertArrayNotHasKey('bucket_owner', $payload1['content'][1]['source']);

        // With bucket owner
        $input2 = AgentInput::text('Test')
            ->withDocumentFromS3('s3://b/k', 'pdf', 'doc', '123');
        $payload2 = $input2->toPayloadValue();
        $this->assertSame('123', $payload2['content'][1]['source']['bucket_owner']);
    }

    /**
     * Verifies that with video from s 3 bucket owner condition.
     *
     * @return void
     */
    public function testWithVideoFromS3BucketOwnerCondition(): void
    {
        // Without bucket owner
        $input1 = AgentInput::text('Test')
            ->withVideoFromS3('s3://b/clip.mp4', 'mp4');
        $payload1 = $input1->toPayloadValue();
        $this->assertArrayNotHasKey('bucket_owner', $payload1['content'][1]['source']);

        // With bucket owner
        $input2 = AgentInput::text('Test')
            ->withVideoFromS3('s3://b/clip.mp4', 'mp4', '999');
        $payload2 = $input2->toPayloadValue();
        $this->assertSame('999', $payload2['content'][1]['source']['bucket_owner']);
    }

    /**
     * Verifies that withDocument() resolves the documented media type for each supported extension.
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
     * Document extension → expected MIME type cases for testWithDocumentResolvesMediaTypeForFormat().
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
     * Verifies that multiple content blocks.
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
        // 1 text + 2 images + 1 document = 4 blocks
        $this->assertCount(4, $payload['content']);
        $this->assertSame('text', $payload['content'][0]['type']);
        $this->assertSame('image', $payload['content'][1]['type']);
        $this->assertSame('image', $payload['content'][2]['type']);
        $this->assertSame('document', $payload['content'][3]['type']);
    }

    /**
     * Verifies that interrupt response.
     *
     * @return void
     */
    public function testInterruptResponse(): void
    {
        $input = AgentInput::interruptResponse('int-abc-123', 'Approved');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        // No text block (empty text), just the interrupt response block
        $this->assertCount(1, $payload['content']);
        $this->assertSame('interrupt_response', $payload['content'][0]['type']);
        $this->assertSame('int-abc-123', $payload['content'][0]['interrupt_id']);
        $this->assertSame('Approved', $payload['content'][0]['response']);
    }


    /**
     * Verifies that structured output prompt only makes array.
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
     * Verifies that with image from s 3.
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
     * Verifies that with image from s 3 with bucket owner.
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
     * Verifies that with image from s 3 returns new instance.
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
     * Verifies that with video.
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
     * Verifies that with video returns new instance.
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
     * Verifies that with image from URL.
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
     * Verifies that image MIME parameters are stripped before deriving format.
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
     * Verifies that image format derivation normalizes MIME casing and whitespace.
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
     * Verifies that a format-only image media value remains usable.
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
     * Verifies that image format derivation preserves compound subtypes.
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
     * Verifies that with image from URL returns new instance.
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
     * Verifies that with document from URL.
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
     * Verifies that with document from URL returns new instance.
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
     * Verifies that with document supports context and citation options.
     *
     * @return void
     */
    public function testWithDocumentSupportsContextAndCitationOptions(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocument('pdfdata', 'pdf', 'report.pdf', 'Referral context', ['enabled' => true]);

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('Referral context', $payload['content'][1]['context']);
        $this->assertSame(['enabled' => true], $payload['content'][1]['citations']);
    }

    /**
     * Verifies that with document from URL supports context and citation options.
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
     * Verifies that with cache point adds cache point block.
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
     * Verifies that with cache point returns new instance.
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
     * Verifies that with video from URL.
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
     * Verifies that with video from URL returns new instance.
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
     * Verifies that mixed media types chaining.
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
        // 1 text + 6 media blocks = 7
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
