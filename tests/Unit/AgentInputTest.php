<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Context\AgentInput;

class AgentInputTest extends TestCase
{
    public function testTextOnlyReturnsString(): void
    {
        $input = AgentInput::text('Hello, agent!');

        $this->assertSame('Hello, agent!', $input->toPayloadValue());
        $this->assertSame('Hello, agent!', $input->getText());
    }

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
        $this->assertSame('base64', $payload['content'][1]['source']['type']);
        $this->assertSame('image/png', $payload['content'][1]['source']['media_type']);
        $this->assertSame('base64data', $payload['content'][1]['source']['data']);
    }

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

    public function testWithDocumentFromS3WithBucketOwner(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocumentFromS3('s3://bucket/doc.pdf', 'pdf', 'doc', '123456789');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('123456789', $payload['content'][1]['source']['bucket_owner']);
    }

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

    public function testWithStructuredOutputPrompt(): void
    {
        $input = AgentInput::text('Extract entities')
            ->withStructuredOutputPrompt('Return JSON with key "entities"');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('Return JSON with key "entities"', $payload['structured_output_prompt']);
    }

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

    public function testWithDocumentReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withDoc = $original->withDocument('data', 'pdf', 'doc.pdf');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withDoc->toPayloadValue());
        $this->assertNotSame($original, $withDoc);
    }

    public function testWithDocumentFromS3ReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withS3 = $original->withDocumentFromS3('s3://b/k', 'pdf', 'doc');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withS3->toPayloadValue());
        $this->assertNotSame($original, $withS3);
    }

    public function testWithVideoFromS3ReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withVideo = $original->withVideoFromS3('s3://b/k', 'mp4');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withVideo->toPayloadValue());
        $this->assertNotSame($original, $withVideo);
    }

    public function testWithStructuredOutputPromptReturnsNewInstance(): void
    {
        $original = AgentInput::text('Hello');
        $withPrompt = $original->withStructuredOutputPrompt('JSON');

        $this->assertSame('Hello', $original->toPayloadValue());
        $this->assertIsArray($withPrompt->toPayloadValue());
        $this->assertNotSame($original, $withPrompt);
    }

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

    public function testWithDocumentTxtFormat(): void
    {
        $input = AgentInput::text('Read this')
            ->withDocument('data', 'txt', 'notes.txt');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('text/plain', $payload['content'][1]['source']['media_type']);
    }

    public function testWithDocumentCsvFormat(): void
    {
        $input = AgentInput::text('Analyse')
            ->withDocument('data', 'csv', 'data.csv');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('text/csv', $payload['content'][1]['source']['media_type']);
    }

    public function testWithDocumentHtmlFormat(): void
    {
        $input = AgentInput::text('Parse')
            ->withDocument('data', 'html', 'page.html');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertSame('text/html', $payload['content'][1]['source']['media_type']);
    }

    public function testWithDocumentDocxFormat(): void
    {
        $input = AgentInput::text('Summarise')
            ->withDocument('data', 'docx', 'report.docx');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        // docx uses application/docx (generic fallback)
        $this->assertSame('application/docx', $payload['content'][1]['source']['media_type']);
    }

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

    public function testStructuredOutputPromptOnlyMakesArray(): void
    {
        $input = AgentInput::text('Extract')
            ->withStructuredOutputPrompt('JSON only');

        $payload = $input->toPayloadValue();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('content', $payload);
        $this->assertArrayHasKey('structured_output_prompt', $payload);
    }
}
