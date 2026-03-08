<?php

declare(strict_types=1);

namespace StrandsPhpClient\Context;

/**
 * Immutable builder for rich agent input with content blocks.
 *
 * Supports text, images (base64), documents (base64 and S3),
 * and structured output control. Uses the same clone-and-mutate
 * pattern as AgentContext.
 *
 * Usage:
 *   $input = AgentInput::text("What's in this image?")
 *       ->withImage($base64, 'image/png');
 */
class AgentInput
{
    private string $text;

    /** @var list<array<string, mixed>> */
    private array $contentBlocks = [];

    private ?string $structuredOutputPrompt = null;

    private function __construct(string $text)
    {
        $this->text = $text;
    }

    /**
     * Create an input starting with a text message.
     */
    public static function text(string $text): self
    {
        return new self($text);
    }

    /**
     * Create an interrupt response to resume after an interrupt.
     *
     * @param string $interruptId  The interrupt ID from InterruptDetail.
     * @param mixed  $response     The approval/denial response value.
     */
    public static function interruptResponse(string $interruptId, mixed $response): self
    {
        $input = new self('');
        $input->contentBlocks[] = [
            'type' => 'interrupt_response',
            'interrupt_id' => $interruptId,
            'response' => $response,
        ];

        return $input;
    }

    /**
     * Add a base64-encoded image content block.
     *
     * @param string $base64Data  Base64-encoded image data.
     * @param string $mediaType   MIME type (e.g. 'image/png', 'image/jpeg').
     *
     * @return self  A new instance with the image added.
     */
    public function withImage(string $base64Data, string $mediaType): self
    {
        $clone = clone $this;
        $clone->contentBlocks[] = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $base64Data,
            ],
        ];

        return $clone;
    }

    /**
     * Add a base64-encoded document content block.
     *
     * @param string $base64Data  Base64-encoded document data.
     * @param string $format      Document format (e.g. 'pdf', 'txt', 'docx').
     * @param string $name        Document name.
     *
     * @return self  A new instance with the document added.
     */
    public function withDocument(string $base64Data, string $format, string $name): self
    {
        $clone = clone $this;
        $clone->contentBlocks[] = [
            'type' => 'document',
            'source' => [
                'type' => 'base64',
                'media_type' => self::formatToMimeType($format),
                'data' => $base64Data,
            ],
            'name' => $name,
        ];

        return $clone;
    }

    /**
     * Add a document from S3 location.
     *
     * @param string      $s3Uri        S3 URI (e.g. 's3://my-bucket/report.pdf').
     * @param string      $format       Document format (e.g. 'pdf').
     * @param string      $name         Document name.
     * @param string|null $bucketOwner  Optional bucket owner account ID.
     *
     * @return self  A new instance with the S3 document added.
     */
    public function withDocumentFromS3(string $s3Uri, string $format, string $name, ?string $bucketOwner = null): self
    {
        $clone = clone $this;
        /** @var array<string, mixed> $source */
        $source = [
            'type' => 's3_location',
            'uri' => $s3Uri,
        ];

        if ($bucketOwner !== null) {
            $source['bucket_owner'] = $bucketOwner;
        }

        $clone->contentBlocks[] = [
            'type' => 'document',
            'source' => $source,
            'format' => $format,
            'name' => $name,
        ];

        return $clone;
    }

    /**
     * Add a video from S3 location.
     *
     * @param string      $s3Uri        S3 URI.
     * @param string      $format       Video format (e.g. 'mp4').
     * @param string|null $bucketOwner  Optional bucket owner account ID.
     *
     * @return self  A new instance with the S3 video added.
     */
    public function withVideoFromS3(string $s3Uri, string $format, ?string $bucketOwner = null): self
    {
        $clone = clone $this;
        /** @var array<string, mixed> $source */
        $source = [
            'type' => 's3_location',
            'uri' => $s3Uri,
        ];

        if ($bucketOwner !== null) {
            $source['bucket_owner'] = $bucketOwner;
        }

        $clone->contentBlocks[] = [
            'type' => 'video',
            'source' => $source,
            'format' => $format,
        ];

        return $clone;
    }

    /**
     * Set a structured output prompt to control output format.
     *
     * @return self  A new instance with the structured output prompt set.
     */
    public function withStructuredOutputPrompt(string $prompt): self
    {
        $clone = clone $this;
        $clone->structuredOutputPrompt = $prompt;

        return $clone;
    }

    /**
     * Get the text portion of this input.
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Serialize to the payload format the Strands API expects.
     *
     * If no content blocks are attached, returns just the text string
     * for backward compatibility. Otherwise returns the content block array.
     *
     * @return string|array<string, mixed>
     */
    public function toPayloadValue(): string|array
    {
        if ($this->contentBlocks === [] && $this->structuredOutputPrompt === null) {
            return $this->text;
        }

        /** @var list<array<string, mixed>> $content */
        $content = [];

        if ($this->text !== '') {
            $content[] = [
                'type' => 'text',
                'text' => $this->text,
            ];
        }

        foreach ($this->contentBlocks as $block) {
            $content[] = $block;
        }

        /** @var array<string, mixed> $payload */
        $payload = ['content' => $content];

        if ($this->structuredOutputPrompt !== null) {
            $payload['structured_output_prompt'] = $this->structuredOutputPrompt;
        }

        return $payload;
    }

    /**
     * Map a document format string to its MIME type.
     *
     * Handles common text, office, and document formats. Unknown formats
     * fall back to "application/{format}".
     */
    private static function formatToMimeType(string $format): string
    {
        return match ($format) {
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'html' => 'text/html',
            'md' => 'text/markdown',
            'xml' => 'application/xml',
            'json' => 'application/json',
            'yaml', 'yml' => 'application/yaml',
            'rtf' => 'application/rtf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/' . $format,
        };
    }
}
