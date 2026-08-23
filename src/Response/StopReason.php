<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Names the 1.x reasons an agent can stop generating a response.
 *
 * Read it to choose a completed state, continue action, safety notice, or human-input prompt after invoke() or stream().
 * Unknown future wire values deliberately stay out of this enum so 1.x switches remain exhaustive; read rawStopReason to inspect or log them.
 */
enum StopReason: string
{
    /** Normal completion - the agent finished its turn. */
    case EndTurn = 'end_turn';

    /** Paused to call a tool. */
    case ToolUse = 'tool_use';

    /** Output token limit reached. */
    case MaxTokens = 'max_tokens';

    /** Hit a configured stop sequence. */
    case StopSequence = 'stop_sequence';

    /** Content safety filter triggered. */
    case ContentFiltered = 'content_filtered';

    /** Bedrock guardrail blocked the response. */
    case GuardrailIntervened = 'guardrail_intervened';

    /** Human-in-the-loop pause - agent needs user input. */
    case Interrupt = 'interrupt';
}
