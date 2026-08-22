<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Fixtures\Compatibility;

use StrandsPhpClient\Context\AgentInput;

/**
 * Mimics a consumer subclass compiled against the AgentInput methods published in 1.4.
 *
 * It protects applications that override the document builders with the original parameter list.
 * Use it only as a compile-time compatibility fixture; production input objects come from AgentInput factories.
 */
final class V1AgentInputExtension extends AgentInput
{
    /**
     * Mimics a consumer app overriding the base64 document builder with the signature published in 1.4.
     * Use it to prove a 1.x upload integration can still extend AgentInput after upgrading.
     */
    public function withDocument(string $base64Data, string $format, string $name): self
    {
        return $this;
    }

    /**
     * Mimics a consumer app overriding the S3 document builder with the signature published in 1.4.
     * Use it to prove a 1.x S3 upload integration can still extend AgentInput after upgrading.
     */
    public function withDocumentFromS3(
        string $s3Uri,
        string $format,
        string $name,
        ?string $bucketOwner = null,
    ): self {
        return $this;
    }
}
