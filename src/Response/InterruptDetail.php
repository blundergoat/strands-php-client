<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Details of an interrupt raised by the agent (human-in-the-loop).
 *
 * When an agent's tool requires user approval before proceeding,
 * it returns an interrupt. The caller can inspect, approve/deny,
 * and resume the conversation.
 */
final readonly class InterruptDetail
{
    /**
     * @param string      $toolName     The tool that raised the interrupt.
     * @param array<string, mixed> $toolInput  The input/arguments the tool was called with.
     * @param string|null $toolUseId    Unique ID for the tool invocation (for resume).
     * @param string|null $interruptId  Server-assigned interrupt identifier (for resume).
     * @param string|null $reason       Human-readable reason for the interrupt.
     */
    public function __construct(
        public string $toolName,
        public array $toolInput = [],
        public ?string $toolUseId = null,
        public ?string $interruptId = null,
        public ?string $reason = null,
    ) {
    }

    /**
     * Build an AgentInput that resumes the conversation after this interrupt.
     *
     * Prefers interruptId; falls back to toolUseId. Throws if neither is set,
     * since sending an empty identifier would produce a confusing server error.
     *
     * @param mixed $response  The approval/denial value to send back (e.g. 'Approved', ['action' => 'allow']).
     *
     * @throws \LogicException If neither interruptId nor toolUseId is available.
     */
    public function toResumeInput(mixed $response): \StrandsPhpClient\Context\AgentInput
    {
        $id = $this->interruptId ?? $this->toolUseId;

        if ($id === null) {
            throw new \LogicException(
                'Cannot resume: InterruptDetail has neither interruptId nor toolUseId.',
            );
        }

        return \StrandsPhpClient\Context\AgentInput::interruptResponse($id, $response);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $toolInput */
        $toolInput = is_array($data['tool_input'] ?? null) ? $data['tool_input'] : [];

        return new self(
            toolName: is_string($data['tool_name'] ?? null) ? $data['tool_name'] : '',
            toolInput: $toolInput,
            toolUseId: is_string($data['tool_use_id'] ?? null) ? $data['tool_use_id'] : null,
            interruptId: is_string($data['interrupt_id'] ?? null) ? $data['interrupt_id'] : null,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
        );
    }
}
