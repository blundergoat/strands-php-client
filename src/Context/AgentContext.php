<?php

declare(strict_types=1);

namespace StrandsPhpClient\Context;

/**
 * Immutable builder for application context sent to agents.
 *
 * All mutation methods return a new instance (clone-and-mutate pattern).
 */
class AgentContext
{
    /** @var array<string, mixed> */
    private array $metadata = [];

    /** System instruction sent with the user-visible agent turn. */
    private ?string $systemPrompt = null;

    /** @var list<string> */
    private array $permissions = [];

    /** @var list<array{name: string, content: string, mime_type: string}> */
    private array $documents = [];

    /** @var array<string, mixed> */
    private array $structuredData = [];

    /**
     * Create an empty immutable context builder.
     */
    private function __construct()
    {
    }

    /**
     * Create an empty immutable context builder.
     *
     * @return self New empty context builder.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Supports the with metadata step in the app-facing flow.
     *
     * @param string $key Payload field name being read or written.
     * @param mixed $value Payload value stored for the agent call.
     * @return self  A new instance with the metadata added.
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->metadata[$key] = $value;

        return $clone;
    }

    /**
     * Supports the with system prompt step in the app-facing flow.
     *
     * @param string $systemPrompt System instruction sent with the agent turn.
     * @return self  A new instance with the system prompt set.
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        $clone = clone $this;
        $clone->systemPrompt = $systemPrompt;

        return $clone;
    }

    /**
     * Add an informational permission token the agent can reference in reasoning.
     * Not an enforcement mechanism - authorization belongs in your API Gateway.
     *
     * @param string $permission Permission label sent as agent context.
     * @return self  A new instance with the permission added.
     */
    public function withPermission(string $permission): self
    {
        $clone = clone $this;
        $clone->permissions[] = $permission;

        return $clone;
    }

    /**
     * @param string $name           Document name (e.g. 'report.pdf').
     * @param string $base64Content  Base64-encoded content.
     * @param string $mimeType       MIME type (e.g. 'application/pdf').
     *
     * @return self  A new instance with the document added.
     */
    public function withDocument(string $name, string $base64Content, string $mimeType): self
    {
        $clone = clone $this;
        $clone->documents[] = [
            'name' => $name,
            'content' => $base64Content,
            'mime_type' => $mimeType,
        ];

        return $clone;
    }

    /**
     * Supports the with structured data step in the app-facing flow.
     *
     * @param string $key Payload field name being read or written.
     * @param mixed $value Payload value stored for the agent call.
     * @return self  A new instance with the structured data added.
     */
    public function withStructuredData(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->structuredData[$key] = $value;

        return $clone;
    }

    /**
     * Serialize to the API contract schema. Empty fields are omitted.
     *
     * @return array<string, mixed> Context payload sent beside the user message.
     */
    public function toArray(): array
    {
        $context = [];

        if ($this->systemPrompt !== null) {
            $context['system_prompt'] = $this->systemPrompt;
        }

        if ($this->metadata !== []) {
            $context['metadata'] = $this->metadata;
        }

        if ($this->permissions !== []) {
            $context['permissions'] = $this->permissions;
        }

        if ($this->documents !== []) {
            $context['documents'] = $this->documents;
        }

        if ($this->structuredData !== []) {
            $context['structured_data'] = $this->structuredData;
        }

        return $context;
    }
}
