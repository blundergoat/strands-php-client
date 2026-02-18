<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

/**
 * Why the agent stopped generating output.
 */
enum StopReason: string
{
    /** Normal completion — the agent finished its turn. */
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

    /** Human-in-the-loop pause — agent needs user input. */
    case Interrupt = 'interrupt';
}
