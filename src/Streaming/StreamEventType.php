<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * All possible types of streaming events from the agent.
 */
enum StreamEventType: string
{
    /** A chunk of generated text. */
    case Text = 'text';

    /** The agent is calling a tool. */
    case ToolUse = 'tool_use';

    /** A tool returned its result. */
    case ToolResult = 'tool_result';

    /** The agent's reasoning/thinking process. */
    case Thinking = 'thinking';

    /** Stream completed successfully (terminal). */
    case Complete = 'complete';

    /** An error occurred during the stream (terminal). */
    case Error = 'error';

    /** Source citation data from the model. */
    case Citation = 'citation';

    /** Reasoning verification signature. */
    case ReasoningSignature = 'reasoning_signature';

    /** Redacted reasoning content block. */
    case ReasoningRedacted = 'reasoning_redacted';
}
