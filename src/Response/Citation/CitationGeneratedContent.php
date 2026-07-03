<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response\Citation;

/**
 * The slice of the agent's answer that a citation backs up.
 *
 * Pairs with a source so the app can highlight exactly which sentence in the
 * response the citation supports. Both fields stay null when the wrapper didn't
 * spell out the generated side of the link.
 */
final readonly class CitationGeneratedContent
{
    /**
     * Hold the slice of the answer a citation backs.
     *
     * Usually built by fromArray() from a response citation block.
     *
     * @param string|null $type  Content type identifier.
     * @param string|null $text  The generated text that references the citation.
     */
    public function __construct(
        public ?string $type = null,
        public ?string $text = null,
    ) {
    }

    /**
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : null,
            text: is_string($data['text'] ?? null) ? $data['text'] : null,
        );
    }
}
