<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Context\AgentContext;

class AgentContextTest extends TestCase
{
    /**
     * Verifies that create returns empty context.
     *
     * @return void
     */
    public function testCreateReturnsEmptyContext(): void
    {
        $context = AgentContext::create();

        $this->assertSame([], $context->toArray());
    }

    /**
     * Verifies that with metadata adds key value.
     *
     * @return void
     */
    public function testWithMetadataAddsKeyValue(): void
    {
        $context = AgentContext::create()
            ->withMetadata('persona', 'analyst')
            ->withMetadata('round', 1);

        $array = $context->toArray();

        $this->assertSame('analyst', $array['metadata']['persona']);
        $this->assertSame(1, $array['metadata']['round']);
    }

    /**
     * Verifies that with system prompt adds prompt.
     *
     * @return void
     */
    public function testWithSystemPromptAddsPrompt(): void
    {
        $context = AgentContext::create()
            ->withSystemPrompt('You are a helpful assistant.');

        $array = $context->toArray();

        $this->assertSame('You are a helpful assistant.', $array['system_prompt']);
    }

    /**
     * Verifies that withMetadata returns a new instance and leaves the original unchanged.
     *
     * @return void
     */
    public function testWithMetadataReturnsNewInstanceAndPreservesOriginal(): void
    {
        $original = AgentContext::create();
        $withMeta = $original->withMetadata('key', 'value');

        $this->assertSame([], $original->toArray());
        $this->assertSame(['metadata' => ['key' => 'value']], $withMeta->toArray());
    }

    /**
     * Verifies that full context.
     *
     * @return void
     */
    public function testFullContext(): void
    {
        $context = AgentContext::create()
            ->withSystemPrompt('Be concise.')
            ->withMetadata('persona', 'skeptic')
            ->withMetadata('user_role', 'admin');

        $expected = [
            'system_prompt' => 'Be concise.',
            'metadata' => [
                'persona' => 'skeptic',
                'user_role' => 'admin',
            ],
        ];

        $this->assertSame($expected, $context->toArray());
    }

    /**
     * Verifies that with permission adds token.
     *
     * @return void
     */
    public function testWithPermissionAddsToken(): void
    {
        $context = AgentContext::create()
            ->withPermission('read:patients')
            ->withPermission('write:notes');

        $array = $context->toArray();

        $this->assertSame(['read:patients', 'write:notes'], $array['permissions']);
    }

    /**
     * Verifies that with document adds document.
     *
     * @return void
     */
    public function testWithDocumentAddsDocument(): void
    {
        $content = base64_encode('Hello, world!');
        $context = AgentContext::create()
            ->withDocument('readme.txt', $content, 'text/plain');

        $array = $context->toArray();

        $this->assertCount(1, $array['documents']);
        $this->assertSame('readme.txt', $array['documents'][0]['name']);
        $this->assertSame($content, $array['documents'][0]['content']);
        $this->assertSame('text/plain', $array['documents'][0]['mime_type']);
    }

    /**
     * Verifies that with multiple documents.
     *
     * @return void
     */
    public function testWithMultipleDocuments(): void
    {
        $context = AgentContext::create()
            ->withDocument('a.txt', base64_encode('A'), 'text/plain')
            ->withDocument('b.json', base64_encode('{}'), 'application/json');

        $array = $context->toArray();

        $this->assertCount(2, $array['documents']);
        $this->assertSame('a.txt', $array['documents'][0]['name']);
        $this->assertSame('b.json', $array['documents'][1]['name']);
    }

    /**
     * Verifies that with structured data adds data.
     *
     * @return void
     */
    public function testWithStructuredDataAddsData(): void
    {
        $context = AgentContext::create()
            ->withStructuredData('patient', ['id' => 123, 'name' => 'Jane Doe'])
            ->withStructuredData('clinic', ['id' => 'CL-789']);

        $array = $context->toArray();

        $this->assertSame(['id' => 123, 'name' => 'Jane Doe'], $array['structured_data']['patient']);
        $this->assertSame(['id' => 'CL-789'], $array['structured_data']['clinic']);
    }

    /**
     * Verifies that full context with all fields.
     *
     * @return void
     */
    public function testFullContextWithAllFields(): void
    {
        $docContent = base64_encode('file contents');
        $context = AgentContext::create()
            ->withSystemPrompt('Be concise.')
            ->withMetadata('persona', 'analyst')
            ->withPermission('read:patients')
            ->withDocument('report.txt', $docContent, 'text/plain')
            ->withStructuredData('metrics', ['score' => 95]);

        $array = $context->toArray();

        $this->assertSame('Be concise.', $array['system_prompt']);
        $this->assertSame('analyst', $array['metadata']['persona']);
        $this->assertSame(['read:patients'], $array['permissions']);
        $this->assertCount(1, $array['documents']);
        $this->assertSame(['score' => 95], $array['structured_data']['metrics']);
    }

    /**
     * Verifies that system prompt immutability.
     *
     * @return void
     */
    public function testSystemPromptImmutability(): void
    {
        $original = AgentContext::create();
        $withPrompt = $original->withSystemPrompt('Be helpful');

        $this->assertSame([], $original->toArray());
        $this->assertSame(['system_prompt' => 'Be helpful'], $withPrompt->toArray());
    }

    /**
     * Verifies that permission immutability.
     *
     * @return void
     */
    public function testPermissionImmutability(): void
    {
        $original = AgentContext::create();
        $withPerm = $original->withPermission('admin');

        $this->assertSame([], $original->toArray());
        $this->assertSame(['permissions' => ['admin']], $withPerm->toArray());
    }

    /**
     * Verifies that document immutability.
     *
     * @return void
     */
    public function testDocumentImmutability(): void
    {
        $original = AgentContext::create();
        $withDoc = $original->withDocument('f.txt', base64_encode('x'), 'text/plain');

        $this->assertSame([], $original->toArray());
        $this->assertCount(1, $withDoc->toArray()['documents']);
    }

    /**
     * Verifies that structured data immutability.
     *
     * @return void
     */
    public function testStructuredDataImmutability(): void
    {
        $original = AgentContext::create();
        $withData = $original->withStructuredData('key', 'value');

        $this->assertSame([], $original->toArray());
        $this->assertSame(['structured_data' => ['key' => 'value']], $withData->toArray());
    }

    /**
     * Verifies that empty fields are omitted.
     *
     * @return void
     */
    public function testEmptyFieldsAreOmitted(): void
    {
        $context = AgentContext::create()
            ->withMetadata('key', 'value');

        $array = $context->toArray();

        $this->assertArrayNotHasKey('system_prompt', $array);
        $this->assertArrayNotHasKey('permissions', $array);
        $this->assertArrayNotHasKey('documents', $array);
        $this->assertArrayNotHasKey('structured_data', $array);
    }
}
