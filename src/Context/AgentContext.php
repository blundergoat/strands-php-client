<?php

declare(strict_types=1);

namespace StrandsPhpClient\Context;

/**
 * Immutable builder for the background an app attaches to an agent turn.
 *
 * It collects instructions, permissions, documents, metadata, and structured data alongside the user's message.
 * Each with* method returns a new instance, so apps can safely reuse and specialize a base context.
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
     * Attach a custom key/value the agent can see (e.g. the signed-in user's plan tier).
     *
     * @param string $key Metadata field name the agent will receive.
     * @param mixed $value Metadata value stored for this agent call.
     * @return self  A new instance with the metadata added.
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->metadata[$key] = $value;

        return $clone;
    }

    /**
     * Set the system instruction that steers how the agent answers this turn.
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
     * Attach a document the agent can read as background for this turn.
     *
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
     * Attach structured data (e.g. a cart or user profile) for the agent to reason over.
     *
     * @param string $key Structured-data field name the agent will receive.
     * @param mixed $value Structured-data value stored for this agent call.
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

        // Send only the sections the app actually set, so the agent payload stays lean.
        // A custom system prompt overrides the agent's default persona for this turn.
        if ($this->systemPrompt !== null) {
            $context['system_prompt'] = $this->systemPrompt;
        }

        // Extra metadata the app tagged the request with (feature flags, user tier, ...).
        if ($this->metadata !== []) {
            $context['metadata'] = $this->metadata;
        }

        // Informational permission labels the agent may mention but must not rely on.
        if ($this->permissions !== []) {
            $context['permissions'] = $this->permissions;
        }

        // Documents the user supplied as background reading for this turn.
        if ($this->documents !== []) {
            $context['documents'] = $this->documents;
        }

        // Structured records (cart, profile, ...) the agent can reason over.
        if ($this->structuredData !== []) {
            $context['structured_data'] = $this->structuredData;
        }

        return $context;
    }
}
