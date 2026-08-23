<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Details of an interrupt raised by the agent (human-in-the-loop).
 *
 * It describes a tool action that needs user approval before the agent can continue.
 * Callers can inspect the reason, collect a decision, and use the interrupt identifier to resume the conversation.
 */
final readonly class InterruptDetail
{
    /**
     * Hold one interrupt the agent raised while awaiting a response.
     *
     * Usually built by fromArray(); call toResumeInput() to send the user's answer back.
     *
     * @param string      $toolName     The tool that raised the interrupt.
     * @param array<string, mixed> $toolInput Tool arguments; empty means the paused action reported no input.
     * @param string|null $toolUseId Tool invocation ID; null means resume must use interruptId instead.
     * @param string|null $interruptId Interrupt ID; null means resume must use toolUseId instead.
     * @param string|null $reason User-facing explanation; null means unavailable, while an empty string is preserved.
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
     * @return \StrandsPhpClient\Context\AgentInput Input that resumes the paused turn with the user's answer.
     * @throws \LogicException If neither interruptId nor toolUseId is available.
     */
    public function toResumeInput(mixed $response): \StrandsPhpClient\Context\AgentInput
    {
        $resumeInterruptId = $this->interruptId ?? $this->toolUseId;

        // Without an ID, the wrapper cannot associate the response with a paused action.
        if ($resumeInterruptId === null) {
            throw new \LogicException(
                'Cannot resume: InterruptDetail has neither interruptId nor toolUseId.',
            );
        }

        return \StrandsPhpClient\Context\AgentInput::interruptResponse($resumeInterruptId, $response);
    }

    /**
     * Build this object from the agent's raw JSON.
     *
     * @param array<string, mixed> $data raw decoded JSON from the agent.
     * @return self New instance ready for app code.
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $toolInput validated before app code uses it. */
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
