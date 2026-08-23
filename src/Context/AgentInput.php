<?php

declare(strict_types=1);

namespace StrandsPhpClient\Context;

/**
 * Builds immutable text, attachment, interrupt, and structured-output input for one user turn.
 *
 * Start with text() or interruptResponse(), then chain attachment helpers for caller-provided content.
 * Plain document methods preserve 1.x override signatures; the Options methods add context and citation controls.
 *
 * For example, an app can call text('Summarise this')->withDocumentOptions(...) without changing the original input.
 * toPayloadValue() creates the Wire Contract v1 message value sent by StrandsClient.
 */
class AgentInput
{
    /** Main text the user wants the agent to respond to. */
    private string $text;

    /** @var list<array<string, mixed>> */
    private array $contentBlocks = [];

    /** Prompt that asks the agent for a structured response shape. */
    private ?string $structuredOutputPrompt = null;

    /**
     * Starts an immutable input with the user's initial text.
     * Called by the public factories; app code starts with text() or interruptResponse().
     *
     * @param string $text Initial message; empty is valid only when another block, such as an interrupt response, carries the turn.
     */
    private function __construct(string $text)
    {
        $this->text = $text;
    }

    /**
     * Starts a new user turn with plain text that can later gain attachments.
     * Use it for chat input, including attachment-only calls that pass an empty prompt before adding a content block.
     *
     * @param string $text The message the user typed; empty must be followed by a content block or StrandsClient rejects the send.
     *
     * @return self New immutable input; never null.
     */
    public static function text(string $text): self
    {
        return new self($text);
    }

    /**
     * Builds the user's answer to an agent pause, such as approving a tool action.
     * Use it after AgentResponse::isInterrupted() when the caller submits an InterruptDetail response.
     *
     * @param string $interruptId The InterruptDetail ID being answered; an empty ID is forwarded and the wrapper may reject it.
     * @param mixed  $response    Approval, denial, form data, or null when the caller intentionally submits no value.
     *
     * @return self New immutable interrupt input; never null and valid without text.
     */
    public static function interruptResponse(string $interruptId, mixed $response): self
    {
        $interruptInput                  = new self('');
        $interruptInput->contentBlocks[] = [
            'type'         => 'interrupt_response',
            'interrupt_id' => $interruptId,
            'response'     => $response,
        ];

        return $interruptInput;
    }

    /**
     * Returns a copy with the image a user attached from their device.
     * Use it when the caller already has base64 bytes and a MIME type rather than an S3 or public URL.
     *
     * @param string $base64Data Base64-encoded image data.
     * @param string $mediaType  MIME type (e.g. 'image/png', 'image/jpeg').
     *
     * @return self New immutable input with the image added; never null.
     */
    public function withImage(string $base64Data, string $mediaType): self
    {
        $updatedInput                  = clone $this;
        $updatedInput->contentBlocks[] = [
            'type'   => 'image',
            'format' => self::deriveImageFormat($mediaType),
            'source' => [
                'type'       => 'base64',
                'media_type' => $mediaType,
                'data'       => $base64Data,
            ],
        ];

        return $updatedInput;
    }

    /**
     * Returns a copy with a plain base64 document while preserving the original 1.x signature.
     * Use withDocumentOptions() when the caller also supplies document context or citation controls.
     *
     * @param string $base64Data Base64-encoded document data.
     * @param string $format     Document format (e.g. 'pdf', 'txt', 'docx').
     * @param string $name       Document name.
     *
     * @return self New immutable input with the document added; never null.
     */
    public function withDocument(string $base64Data, string $format, string $name): self
    {
        return $this->withDocumentOptions($base64Data, $format, $name);
    }

    /**
     * Returns a copy with a base64 document and its optional wrapper instructions.
     * Use it when the caller adds per-document context or requests citations.
     *
     * @param string                    $base64Data Base64-encoded document data.
     * @param string                    $format     Document format (e.g. 'pdf', 'txt', 'docx').
     * @param string                    $name       Document name.
     * @param string|null               $context    Per-document guidance; null omits the field, while an empty string sends explicit empty guidance.
     * @param array<string, mixed>|null $citations Citation controls; null omits the field, while an empty array sends an explicit empty
     *                                             configuration.
     *
     * @return self New immutable input with the configured document; never null.
     */
    public function withDocumentOptions(
        string $base64Data,
        string $format,
        string $name,
        ?string $context = null,
        ?array $citations = null,
    ): self {
        $updatedInput                  = clone $this;
        $updatedInput->contentBlocks[] = self::documentBlock(
            format:    $format,
            name:      $name,
            source:    [
                           'type'       => 'base64',
                           'media_type' => self::formatToMimeType($format),
                           'data'       => $base64Data,
                       ],
            context:   $context,
            citations: $citations,
        );

        return $updatedInput;
    }

    /**
     * Returns a copy with a plain S3 document while preserving the original 1.x signature.
     * Use withDocumentFromS3Options() when the caller also supplies document context or citation controls.
     *
     * @param string      $s3Uri       S3 URI (e.g. 's3://my-bucket/report.pdf').
     * @param string      $format      Document format (e.g. 'pdf').
     * @param string      $name        Document name.
     * @param string|null $bucketOwner Cross-account owner ID; null omits it for a same-account bucket, while an empty string is sent as supplied.
     *
     * @return self New immutable input with the S3 document; never null.
     */
    public function withDocumentFromS3(
        string $s3Uri,
        string $format,
        string $name,
        ?string $bucketOwner = null,
    ): self {
        return $this->withDocumentFromS3Options($s3Uri, $format, $name, $bucketOwner);
    }

    /**
     * Returns a copy with an S3 document and optional wrapper instructions.
     * Use it when the file picker stores documents in S3 and also exposes context or citation settings.
     *
     * @param string                    $s3Uri       S3 URI (e.g. 's3://my-bucket/report.pdf').
     * @param string                    $format      Document format (e.g. 'pdf').
     * @param string                    $name        Document name.
     * @param string|null               $bucketOwner Cross-account owner ID; null omits it for same-account S3; an empty string is sent as supplied.
     * @param string|null               $context     Per-document guidance; null omits the field, while an empty string sends explicit empty guidance.
     * @param array<string, mixed>|null $citations   Citation controls; null omits the field; an empty array sends an explicit empty configuration.
     *
     * @return self New immutable input with the configured S3 document; never null.
     */
    public function withDocumentFromS3Options(
        string $s3Uri,
        string $format,
        string $name,
        ?string $bucketOwner = null,
        ?string $context = null,
        ?array $citations = null,
    ): self {
        $updatedInput = clone $this;
        /** @var array<string, mixed> $source validated before app code uses it. */
        $source = [
            'type' => 's3_location',
            'uri'  => $s3Uri,
        ];

        // Cross-account S3 needs the owner account id; same-account buckets omit it.
        if ($bucketOwner !== null) {
            $source['bucket_owner'] = $bucketOwner;
        }

        $updatedInput->contentBlocks[] = self::documentBlock($format, $name, $source, $context, $citations);

        return $updatedInput;
    }

    /**
     * Returns a copy with an image already stored in S3.
     * Use it when the upload flow supplies an S3 URI instead of moving image bytes through PHP.
     *
     * @param string      $s3Uri       S3 URI (e.g. 's3://my-bucket/image.png').
     * @param string      $format      Image format (e.g. 'png', 'jpeg').
     * @param string|null $bucketOwner Cross-account owner ID; null omits it for a same-account bucket, while an empty string is sent as supplied.
     *
     * @return self New immutable input with the S3 image; never null.
     */
    public function withImageFromS3(string $s3Uri, string $format, ?string $bucketOwner = null): self
    {
        $updatedInput = clone $this;
        /** @var array<string, mixed> $source validated before app code uses it. */
        $source = [
            'type' => 's3_location',
            'uri'  => $s3Uri,
        ];

        // Cross-account S3 needs the owner account id; same-account buckets omit it.
        if ($bucketOwner !== null) {
            $source['bucket_owner'] = $bucketOwner;
        }

        $updatedInput->contentBlocks[] = [
            'type'   => 'image',
            'source' => $source,
            'format' => $format,
        ];

        return $updatedInput;
    }

    /**
     * Returns a copy with video bytes the user attached from their device.
     * Use it when the caller already has base64 video rather than an S3 or public URL.
     *
     * @param string $base64Data Base64-encoded video data.
     * @param string $format     Video format (e.g. 'mp4', 'webm').
     *
     * @return self New immutable input with the video; never null.
     */
    public function withVideo(string $base64Data, string $format): self
    {
        $updatedInput                  = clone $this;
        $updatedInput->contentBlocks[] = [
            'type'   => 'video',
            'source' => [
                'type'       => 'base64',
                'media_type' => 'video/' . $format,
                'data'       => $base64Data,
            ],
            'format' => $format,
        ];

        return $updatedInput;
    }

    /**
     * Returns a copy with an image the agent can fetch from a URL.
     * Use it when the user selects a hosted image and the wrapper can access that address.
     *
     * @param string $url       The image URL.
     * @param string $mediaType MIME type (e.g. 'image/png', 'image/jpeg').
     *
     * @return self New immutable input with the hosted image; never null.
     */
    public function withImageFromUrl(string $url, string $mediaType): self
    {
        $updatedInput                  = clone $this;
        $updatedInput->contentBlocks[] = [
            'type'   => 'image',
            'format' => self::deriveImageFormat($mediaType),
            'source' => [
                'type'       => 'url',
                'url'        => $url,
                'media_type' => $mediaType,
            ],
        ];

        return $updatedInput;
    }

    /**
     * Returns a copy with a hosted document and optional wrapper instructions.
     * Use it when the user supplies a reachable document URL, with context or citation controls when needed.
     *
     * @param string                    $url       The document URL.
     * @param string                    $format    Document format (e.g. 'pdf', 'txt').
     * @param string                    $name      Document name.
     * @param string|null               $context   Per-document guidance; null omits the field, while an empty string sends explicit empty guidance.
     * @param array<string, mixed>|null $citations Citation controls; null omits the field; an empty array sends an explicit empty configuration.
     *
     * @return self New immutable input with the hosted document; never null.
     */
    public function withDocumentFromUrl(
        string  $url,
        string  $format,
        string  $name,
        ?string $context = null,
        ?array  $citations = null,
    ): self {
        $updatedInput                  = clone $this;
        $updatedInput->contentBlocks[] = self::documentBlock(
            format:    $format,
            name:      $name,
            // The media type lets the wrapper validate the hosted document before fetching it, matching the contract's URL source shape.
            source:    [
                           'type'       => 'url',
                           'url'        => $url,
                           'media_type' => self::formatToMimeType($format),
                       ],
            context:   $context,
            citations: $citations,
        );

        return $updatedInput;
    }

    /**
     * Returns a copy with a video the agent can fetch from a URL.
     * Use it when the user selects a hosted video and the wrapper can access that address.
     *
     * @param string $url    The video URL.
     * @param string $format Video format (e.g. 'mp4', 'webm').
     *
     * @return self New immutable input with the hosted video; never null.
     */
    public function withVideoFromUrl(string $url, string $format): self
    {
        $updatedInput                  = clone $this;
        $updatedInput->contentBlocks[] = [
            'type'   => 'video',
            // The media type lets the wrapper validate the hosted video before fetching it, matching the contract's URL source shape.
            'source' => [
                'type'       => 'url',
                'url'        => $url,
                'media_type' => 'video/' . $format,
            ],
            'format' => $format,
        ];

        return $updatedInput;
    }

    /**
     * Returns a copy with a video already stored in S3.
     * Use it when the upload flow supplies an S3 URI instead of moving video bytes through PHP.
     *
     * @param string      $s3Uri       S3 URI.
     * @param string      $format      Video format (e.g. 'mp4').
     * @param string|null $bucketOwner Cross-account owner ID; null omits it for a same-account bucket, while an empty string is sent as supplied.
     *
     * @return self New immutable input with the S3 video; never null.
     */
    public function withVideoFromS3(string $s3Uri, string $format, ?string $bucketOwner = null): self
    {
        $updatedInput = clone $this;
        /** @var array<string, mixed> $source validated before app code uses it. */
        $source = [
            'type' => 's3_location',
            'uri'  => $s3Uri,
        ];

        // Cross-account S3 needs the owner account id; same-account buckets omit it.
        if ($bucketOwner !== null) {
            $source['bucket_owner'] = $bucketOwner;
        }

        $updatedInput->contentBlocks[] = [
            'type'   => 'video',
            'source' => $source,
            'format' => $format,
        ];

        return $updatedInput;
    }

    /**
     * Returns a copy with a cache boundary for reusable conversation content.
     * Use it when the caller sends a long reusable prefix and the wrapper supports prompt caching.
     *
     * @param string  $type Cache scope for this block (e.g. 'default').
     * @param ?string $ttl  Cache lifetime label; null omits it for wrapper defaults, while an empty string is sent as an explicit value.
     *
     * @return self New immutable input with the cache point; never null.
     */
    public function withCachePoint(string $type = 'default', ?string $ttl = null): self
    {
        $updatedInput = clone $this;
        $cachePointBlock = [
            'type'       => 'cache_point',
            'cache_type' => $type,
        ];

        // A TTL is optional; include it only when the app wants the cache to expire.
        if ($ttl !== null) {
            $cachePointBlock['ttl'] = $ttl;
        }

        $updatedInput->contentBlocks[] = $cachePointBlock;

        return $updatedInput;
    }

    /**
     * Returns a copy that asks the agent for a predictable structured answer.
     * Use it when the caller needs fields it can hydrate into a form, card, or DTO instead of free text alone.
     *
     * @param string $prompt Structured-output instruction sent to the agent.
     *
     * @return self New immutable input with the structured-output request; never null.
     */
    public function withStructuredOutputPrompt(string $prompt): self
    {
        $updatedInput                         = clone $this;
        $updatedInput->structuredOutputPrompt = $prompt;

        return $updatedInput;
    }

    /**
     * Returns the text the user entered before any attachment blocks.
     * StrandsClient uses it to reject a truly empty send while allowing attachment-only and interrupt turns.
     *
     * @return string The user's plain text; empty means another content block must carry the turn.
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Converts the builder into the Wire Contract v1 message value used by StrandsClient.
     *
     * A chat-only input returns its original string; attachments, interrupts, or structured output return a content map.
     * App code normally calls invoke() or stream(), which invokes this conversion automatically.
     *
     * @return string|array<string, mixed> Wire message value; an empty string means the builder has no text or content and the client rejects it.
     */
    public function toPayloadValue(): string|array
    {
        // A simple chat prompt with no attachments keeps the original string payload.
        if ($this->contentBlocks === [] && $this->structuredOutputPrompt === null) {
            return $this->text;
        }

        /** @var list<array<string, mixed>> $messageContent validated before app code uses it. */
        $messageContent = [];

        // Rich requests still include the user's typed prompt before attachments.
        if ($this->text !== '') {
            $messageContent[] = [
                'type' => 'text',
                'text' => $this->text,
            ];
        }

        foreach ($this->contentBlocks as $contentBlock) {
            $messageContent[] = $contentBlock;
        }

        /** @var array<string, mixed> $messagePayload validated before app code uses it. */
        $messagePayload = ['content' => $messageContent];

        // A structured-output prompt asks the wrapper for a predictable result alongside the content.
        if ($this->structuredOutputPrompt !== null) {
            $messagePayload['structured_output_prompt'] = $this->structuredOutputPrompt;
        }

        return $messagePayload;
    }

    /**
     * Derives the image format expected by the wire contract from the attachment MIME type.
     * Use it while building base64 or URL image blocks; for example, image/png becomes png and an unknown value remains unchanged.
     *
     * @param string $mediaType MIME type used to describe the attachment.
     *
     * @return string Wire-contract image format; empty when the supplied media type is empty.
     */
    private static function deriveImageFormat(string $mediaType): string
    {
        $normalizedMediaType = strtolower(trim(explode(';', $mediaType)[0]));
        $slashPosition = strpos($normalizedMediaType, '/');

        // A media type without a slash is already the best format label available; an empty value therefore remains empty.
        return $slashPosition === false
            ? $normalizedMediaType
            : substr($normalizedMediaType, $slashPosition + 1);
    }

    /**
     * Maps the supplied file extension to the MIME type the wrapper expects.
     * Use it for URL and base64 documents; unknown formats become application/{format}, including application/ for an empty format.
     *
     * @param string $format Attachment format sent with the user message.
     *
     * @return string MIME type the agent uses to interpret the attachment.
     */
    private static function formatToMimeType(string $format): string
    {
        return match ($format) {
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'html' => 'text/html',
            'md' => 'text/markdown',
            'yml' => 'application/yaml',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/' . $format,
        };
    }

    /**
     * Builds the shared wire block used by every document attachment path.
     * Use it after the caller has chosen the document source and any optional context or citation controls.
     *
     * @param array<string, mixed>      $source    Attachment source sent in the request payload.
     * @param array<string, mixed>|null $citations Citation controls; null omits the field; an empty array sends an explicit empty configuration.
     * @param string                    $format    Document format shown to the agent; empty is forwarded unchanged.
     * @param string                    $name      Document name shown in citations and agent context; empty is forwarded unchanged.
     * @param ?string                   $context   Per-document guidance; null omits the field, while an empty string sends explicit empty guidance.
     *
     * @return array<string, mixed> Document content block sent with the user message.
     */
    private static function documentBlock(
        string  $format,
        string  $name,
        array   $source,
        ?string $context = null,
        ?array  $citations = null,
    ): array {
        $documentBlock = [
            'type'   => 'document',
            'source' => $source,
            'format' => $format,
            'name'   => $name,
        ];

        if ($context !== null) {
            $documentBlock['context'] = $context;
        }

        if ($citations !== null) {
            $documentBlock['citations'] = $citations;
        }

        return $documentBlock;
    }
}
