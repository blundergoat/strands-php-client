<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * The kinds of events that arrive while an answer is streaming in.
 *
 * Apps map them to visible text, tool activity, reasoning, citations, or terminal state as the agent works.
 * Unknown future types are skipped so a newer wrapper does not break an older UI.
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
