<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Why the agent stopped generating output on a given turn.
 *
 * The app reads this to decide what to show after a response: a finished answer,
 * a "continue" affordance when the model ran out of tokens, a safety notice, or
 * a prompt for the user when the agent paused for human input. Unknown future
 * values are preserved separately as the response's raw stop reason.
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

    /** Agent or wrapper reported a terminal error. */
    case Error = 'error';

    /** The operation was cancelled before completing. */
    case Cancelled = 'cancelled';

    /** A mid-run checkpoint was reached. */
    case Checkpoint = 'checkpoint';
}
