<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Context\AgentInput;

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
    public function testWithDocumentFromS3(): void
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
    public function testWithVideoFromS3(): void
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
     * Verifies that immutability.
     *
     * @return void
     */
    public function testImmutability(): void
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
     * Verifies that with document txt format.
     *
     * @return void
     */
    public function testWithDocumentTxtFormat(): void
    {
        $input = AgentInput::text('Read this')
            ->withDocument('data', 'txt', 'notes.txt');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('text/plain', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document csv format.
     *
     * @return void
     */
    public function testWithDocumentCsvFormat(): void
    {
        $input = AgentInput::text('Analyse')
            ->withDocument('data', 'csv', 'data.csv');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('text/csv', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document html format.
     *
     * @return void
     */
    public function testWithDocumentHtmlFormat(): void
    {
        $input = AgentInput::text('Parse')
            ->withDocument('data', 'html', 'page.html');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('text/html', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document docx format.
     *
     * @return void
     */
    public function testWithDocumentDocxFormat(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocument('data', 'docx', 'report.docx');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $payload['content'][1]['source']['media_type'],
        );
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
     * Verifies that with document JSON format.
     *
     * @return void
     */
    public function testWithDocumentJsonFormat(): void
    {
        $input = AgentInput::text('Parse')
            ->withDocument('data', 'json', 'schema.json');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/json', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document yaml format.
     *
     * @return void
     */
    public function testWithDocumentYamlFormat(): void
    {
        $input = AgentInput::text('Parse')
            ->withDocument('data', 'yaml', 'config.yaml');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/yaml', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document yml format.
     *
     * @return void
     */
    public function testWithDocumentYmlFormat(): void
    {
        $input = AgentInput::text('Parse')
            ->withDocument('data', 'yml', 'config.yml');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/yaml', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document xlsx format.
     *
     * @return void
     */
    public function testWithDocumentXlsxFormat(): void
    {
        $input = AgentInput::text('Analyse')
            ->withDocument('data', 'xlsx', 'data.xlsx');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $payload['content'][1]['source']['media_type'],
        );
    }

    /**
     * Verifies that with document xls format.
     *
     * @return void
     */
    public function testWithDocumentXlsFormat(): void
    {
        $input = AgentInput::text('Analyse')
            ->withDocument('data', 'xls', 'data.xls');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/vnd.ms-excel', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document pptx format.
     *
     * @return void
     */
    public function testWithDocumentPptxFormat(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocument('data', 'pptx', 'deck.pptx');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            $payload['content'][1]['source']['media_type'],
        );
    }

    /**
     * Verifies that with document ppt format.
     *
     * @return void
     */
    public function testWithDocumentPptFormat(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocument('data', 'ppt', 'deck.ppt');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/vnd.ms-powerpoint', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document doc format.
     *
     * @return void
     */
    public function testWithDocumentDocFormat(): void
    {
        $input = AgentInput::text('Read')
            ->withDocument('data', 'doc', 'letter.doc');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/msword', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document rtf format.
     *
     * @return void
     */
    public function testWithDocumentRtfFormat(): void
    {
        $input = AgentInput::text('Read')
            ->withDocument('data', 'rtf', 'notes.rtf');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/rtf', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document md format.
     *
     * @return void
     */
    public function testWithDocumentMdFormat(): void
    {
        $input = AgentInput::text('Read')
            ->withDocument('data', 'md', 'readme.md');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('text/markdown', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that with document XML format.
     *
     * @return void
     */
    public function testWithDocumentXmlFormat(): void
    {
        $input = AgentInput::text('Parse')
            ->withDocument('data', 'xml', 'config.xml');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/xml', $payload['content'][1]['source']['media_type']);
    }

    /**
     * Verifies that unknown format falls back to application prefix.
     *
     * @return void
     */
    public function testUnknownFormatFallsBackToApplicationPrefix(): void
    {
        $input = AgentInput::text('Process')
            ->withDocument('data', 'parquet', 'data.parquet');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('application/parquet', $payload['content'][1]['source']['media_type']);
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
    public function testWithImageFromS3(): void
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
