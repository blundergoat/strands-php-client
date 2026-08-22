<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

use StrandsPhpClient\Exceptions\StrandsException;

/**
 * Represents the complete result an app receives from invoke().
 *
 * Read it to render answer text, continue a session, show usage and tools, request interrupt input, or display citations and guardrail details.
 * Unrecognized top-level fields remain in $metadata for forward compatibility.
 *
 * Dedicated 1.5 properties are canonical, while their former metadata locations remain deprecated aliases throughout 1.x.
 */
class AgentResponse
{
    /** @var list<Citation\Citation>|null */
    private ?array $citationObjects = null;

    /**
     * Assembles every caller-visible part of a completed invoke() result.
     * Use fromArray() for agent JSON; direct construction is mainly for tests and app-owned fixtures.
     *
     * @param string  $text               Agent answer; empty means the turn returned no plain-text content.
     * @param string|null  $agent          Agent name; null means the wrapper did not identify one, while an empty string is preserved.
     * @param string|null  $sessionId      Conversation ID; null prevents session continuation, while an empty string is preserved.
     * @param Usage   $usage              Token usage statistics.
     * @param list<array{name: string, duration_ms?: int, input?: array<string, mixed>, result?: array<string, mixed>}> $toolsUsed
     *        Tools the agent called; empty means no tool activity to show.
     * @param bool    $hasObjective       Whether this agent had a secret objective active.
     * @param StopReason|null $stopReason  Known 1.x reason; null means absent or newer, when rawStopReason may still explain the outcome.
     * @param array<string, mixed>|null $structuredOutput Structured answer; null means none was returned; an empty array is a valid result.
     * @param array<string, mixed> $metadata Unrecognised fields plus deprecated aliases; empty means the wrapper sent no extensions.
     * @param list<InterruptDetail> $interrupts  User prompts raised by the agent; empty means the UI has nothing to answer.
     * @param GuardrailTrace|null $guardrailTrace  Intervention detail; null means no guardrail trace was returned.
     * @param list<array<string, mixed>> $citations  Raw citation blocks; empty means no sources are available to render.
     * @param Message|null $message  Normalized raw message for advanced displays; null means the wrapper omitted it or sent a malformed value.
     * @param array<string, mixed> $wrapperMetadata  Wrapper-owned metadata; empty means no wrapper metadata was supplied.
     * @param int|null $contextSize  Current context tokens; null means the UI should omit this capacity hint.
     * @param int|null $projectedContextSize  Projected next-turn context tokens; null means the UI should omit this forecast.
     * @param string|null $rawStopReason  Exact wire stop reason, including future values; null means the wrapper supplied none.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $agent = null,
        public readonly ?string $sessionId = null,
        public readonly Usage $usage = new Usage(),
        public readonly array $toolsUsed = [],
        public readonly bool $hasObjective = false,
        public readonly ?StopReason $stopReason = null,
        public readonly ?array $structuredOutput = null,
        public readonly array $metadata = [],
        public readonly array $interrupts = [],
        public readonly ?GuardrailTrace $guardrailTrace = null,
        public readonly array $citations = [],
        public readonly ?Message $message = null,
        public readonly array $wrapperMetadata = [],
        public readonly ?int $contextSize = null,
        public readonly ?int $projectedContextSize = null,
        public readonly ?string $rawStopReason = null,
    ) {
    }

    /**
     * Reports whether the agent paused and needs another user action.
     * Use it to decide whether the UI should render approval or follow-up controls from $interrupts.
     *
     * @return bool true when the agent paused and is waiting on the user.
     */
    public function isInterrupted(): bool
    {
        // An empty interrupt list means the answer needs no approval or follow-up input from the user.
        return $this->interrupts !== [];
    }

    /**
     * Returns cached typed citations ready for a source list below the answer.
     * Use it when the UI prefers Citation DTOs; an empty result means there are no sources to render.
     *
     * @return list<Citation\Citation> Typed citations to render under the answer; empty when nothing was cited.
     */
    public function getCitationObjects(): array
    {
        // Build the typed list once, then reuse it — the UI may ask on every redraw.
        if ($this->citationObjects !== null) {
            return $this->citationObjects;
        }

        $this->citationObjects = [];
        // Turn each raw citation into a DTO the app can render as a source footnote.
        foreach ($this->citations as $citationData) {
            $this->citationObjects[] = Citation\Citation::fromArray($citationData);
        }

        return $this->citationObjects;
    }

    /**
     * Converts the structured answer into the DTO used by an app form, card, or workflow.
     * Use it only after requesting structured output; a missing result or incompatible DTO raises StrandsException.
     *
     * @template T of object
     *
     * @param class-string<T> $class DTO class used to hydrate structured output; an empty or invalid class name fails with StrandsException.
     *
     * @return T The response hydrated into the app's DTO, ready to use.
     *
     * @throws StrandsException If no structured output is available or hydration fails.
     */
    public function structuredOutputAs(string $class): object
    {
        // The app asked to type the answer, but the agent returned no structured output, so fail clearly instead of handing back an empty object.
        if ($this->structuredOutput === null) {
            throw new StrandsException('No structured output in response');
        }

        try {
            $reflectionClass = new \ReflectionClass($class);

            // Prefer the DTO's own fromArray() factory when it exposes one.
            if ($reflectionClass->hasMethod('fromArray')) {
                $method = $reflectionClass->getMethod('fromArray');
                // Only a public static fromArray() is safe to call without an instance.
                if ($method->isStatic() && $method->isPublic()) {
                    /** @var T */
                    return $method->invoke(null, $this->structuredOutput);
                }
            }

            // Otherwise map the fields straight onto the constructor's named arguments.
            /** @var T */
            return new $class(...$this->structuredOutput);
        } catch (StrandsException $exception) {
            // For example, the target DTO's own fromArray() may reject a missing field with a deliberate app-facing StrandsException.
            throw $exception;
        } catch (\Throwable $exception) {
            // For example, a constructor may require a field the structured response omitted; wrap that reflection/type error for the caller.
            throw new StrandsException(
                sprintf('Failed to hydrate structured output into %s: %s', $class, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * Defensively converts decoded /invoke JSON into fields an answer screen can read safely.
     * Use it at the transport boundary; missing optional data becomes null, empty collections, or zero counters according to each public property.
     *
     * @param array<string, mixed> $data Raw decoded agent JSON; an empty map produces an empty answer with safe defaults.
     * @return self Hydrated response; never null.
     */
    public static function fromArray(array $data): self
    {
        $usage = self::parseUsage($data);

        $rawStopReason = $data['stop_reason'] ?? null;
        $stopReason = is_string($rawStopReason) ? StopReason::tryFrom($rawStopReason) : null;

        $rawStructuredOutput = $data['structured_output'] ?? null;
        // This is present when the user asked the app for a structured answer instead of plain text only.
        /** @var array<string, mixed>|null $structuredOutput validated before app code uses it. */
        $structuredOutput = is_array($rawStructuredOutput) ? $rawStructuredOutput : null;

        // Keep fields promoted in 1.5 out of this list during 1.x because older apps may still read them from this metadata bag.
        // The dedicated properties are canonical for new code; these deprecated aliases can be removed together in 2.0.
        $knownKeys = [
            'text', 'agent', 'session_id', 'usage', 'tools_used',
            'has_objective', 'stop_reason', 'structured_output',
            'interrupts', 'guardrail_trace', 'trace', 'message',
        ];
        /** @var array<string, mixed> $metadata validated before app code uses it. */
        $metadata = array_diff_key($data, array_flip($knownKeys));
        $rawWrapperMetadata = $data['metadata'] ?? null;
        /** @var array<string, mixed> $wrapperMetadata validated before app code uses it. */
        $wrapperMetadata = is_array($rawWrapperMetadata) ? $rawWrapperMetadata : [];

        return new self(
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            agent: is_string($data['agent'] ?? null) ? $data['agent'] : null,
            sessionId: is_string($data['session_id'] ?? null) ? $data['session_id'] : null,
            hasObjective: ($data['has_objective'] ?? false) === true,
            usage: $usage,
            toolsUsed: self::parseToolsUsed($data),
            stopReason: $stopReason,
            structuredOutput: $structuredOutput,
            metadata: $metadata,
            interrupts: self::parseInterrupts($data),
            guardrailTrace: self::parseGuardrailTrace($data),
            citations: self::parseCitations($data),
            message: self::parseMessage($data),
            wrapperMetadata: $wrapperMetadata,
            contextSize: self::nullableIntField($data, 'context_size'),
            projectedContextSize: self::nullableIntField($data, 'projected_context_size'),
            rawStopReason: is_string($rawStopReason) ? $rawStopReason : null,
        );
    }

    /**
     * Converts the optional wire usage block into counters safe for the app's usage display.
     * Use it during response hydration; an absent or malformed block produces a zeroed Usage object.
     *
     * @param array<string, mixed> $responseData Decoded response; a missing usage block gives the app zeroed usage counters.
     * @return Usage Token usage for the turn; zeroed when the response omitted it.
     */
    private static function parseUsage(array $responseData): Usage
    {
        /** @var array<string, mixed> $usageData validated before app code uses it. */
        $usageData = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        return Usage::fromArray($usageData);
    }

    /**
     * Filters raw tool activity into the small summaries an answer screen can safely display.
     * Use it during response hydration; missing or malformed entries are omitted without hiding valid tools around them.
     *
     * @param array<string, mixed> $responseData Decoded response; missing or malformed tool entries produce an empty or filtered activity trail.
     *
     * @return list<array{name: string, duration_ms?: int, input?: array<string, mixed>, result?: array<string, mixed>}> Safe tool summaries.
     */
    private static function parseToolsUsed(array $responseData): array
    {
        $toolsUsed = [];
        $rawTools = is_array($responseData['tools_used'] ?? null) ? $responseData['tools_used'] : [];

        // Each entry is one tool the agent called, which the app can show in a "used these tools" trail under the answer.
        foreach ($rawTools as $toolData) {
            // A tool with no usable name can't be displayed, so skip it.
            if (!is_array($toolData) || !isset($toolData['name']) || !is_string($toolData['name'])) {
                continue;
            }

            $toolSummary = ['name' => $toolData['name']];

            // Include how long the tool took when the server timed it (a latency hint).
            if (isset($toolData['duration_ms']) && is_int($toolData['duration_ms'])) {
                $toolSummary['duration_ms'] = $toolData['duration_ms'];
            }

            // Keep the arguments the tool was called with when present (for a details view).
            if (isset($toolData['input']) && is_array($toolData['input'])) {
                /** @var array<string, mixed> $toolInput validated before app code uses it. */
                $toolInput = $toolData['input'];
                $toolSummary['input'] = $toolInput;
            }

            // Keep what the tool returned when present, so the app can show its output.
            if (isset($toolData['result']) && is_array($toolData['result'])) {
                /** @var array<string, mixed> $toolResult validated before app code uses it. */
                $toolResult = $toolData['result'];
                $toolSummary['result'] = $toolResult;
            }

            /** @var array{name: string, duration_ms?: int, input?: array<string, mixed>, result?: array<string, mixed>} $toolSummary validated. */
            $toolsUsed[] = $toolSummary;
        }

        return $toolsUsed;
    }

    /**
     * Converts raw interrupt blocks into the prompts an app can show for human input.
     * Use it during hydration; no valid blocks means the turn can continue without an approval or follow-up screen.
     *
     * @param array<string, mixed> $responseData Decoded response; a missing interrupts field means the agent needs no user action.
     *
     * @return list<InterruptDetail> Interrupts to surface as prompts; empty when the agent needs nothing from the user.
     */
    private static function parseInterrupts(array $responseData): array
    {
        $rawInterrupts = $responseData['interrupts'] ?? null;
        // Most answers do not ask the user for approval or extra input.
        if (!is_array($rawInterrupts)) {
            return [];
        }

        $interrupts = [];
        // Each interrupt can become an approval card or follow-up question in the app.
        foreach ($rawInterrupts as $interruptData) {
            // Skip any malformed entry so one bad interrupt can't break the prompt.
            if (is_array($interruptData)) {
                /** @var array<string, mixed> $interruptData validated before app code uses it. */
                $interrupts[] = InterruptDetail::fromArray($interruptData);
            }
        }

        return $interrupts;
    }

    /**
     * Finds guardrail detail in either supported wrapper location and converts it for a safety notice.
     * Use it during hydration; null means the app has no intervention detail to display.
     *
     * @param array<string, mixed> $responseData Decoded response; missing guardrail data means the answer had no intervention details to show.
     * @return ?GuardrailTrace Guardrail trace, or null when the turn had no guardrail intervention.
     */
    private static function parseGuardrailTrace(array $responseData): ?GuardrailTrace
    {
        // Try top-level first; this is what the app inspects after a visible guardrail intervention.
        $guardrailData = $responseData['guardrail_trace'] ?? null;

        // Fall back to nested trace.guardrail for wrappers that keep trace data grouped.
        if (!is_array($guardrailData)) {
            $trace = $responseData['trace'] ?? null;
            // Some wrappers nest the guardrail block inside a broader trace object.
            if (is_array($trace)) {
                $guardrailData = $trace['guardrail'] ?? null;
            }
        }

        // Neither shape was present — this turn had no guardrail activity to show.
        if (!is_array($guardrailData)) {
            return null;
        }

        /** @var array<string, mixed> $guardrailData validated before app code uses it. */
        return GuardrailTrace::fromArray($guardrailData);
    }

    /**
     * Extracts citation blocks from the normalized message so an answer screen can render sources.
     * Use it during hydration; missing, empty, or malformed content produces an empty citation list.
     *
     * @param array<string, mixed> $responseData Decoded response; a missing message/content list means the answer has no citations to render.
     *
     * @return list<array<string, mixed>> Citation blocks the app can render with the answer.
     */
    private static function parseCitations(array $responseData): array
    {
        $message = $responseData['message'] ?? null;
        // No message envelope means there are no citations to pull out.
        if (!is_array($message)) {
            return [];
        }

        $content = $message['content'] ?? null;
        // No content blocks means nothing was cited in this answer.
        if (!is_array($content)) {
            return [];
        }

        $citations = [];
        // Scan the answer's blocks for the ones that carry citation data.
        foreach ($content as $contentBlock) {
            // Ignore any malformed block so it can't break citation rendering.
            if (!is_array($contentBlock)) {
                continue;
            }
            $contentBlockType = $contentBlock['type'] ?? null;
            // Keep only citation blocks; skip the plain text/tool blocks around them.
            if ($contentBlockType === 'citationsContent' || $contentBlockType === 'citation') {
                /** @var array<string, mixed> $contentBlock validated before app code uses it. */
                $citations[] = $contentBlock;
            }
        }

        return $citations;
    }

    /**
     * Converts the optional raw message envelope for advanced transcript or content-block displays.
     * Use it during hydration; null means the wrapper omitted the message or supplied a non-map value.
     *
     * @param array<string, mixed> $responseData Decoded response; a missing message field leaves advanced message details unavailable.
     * @return ?Message Parsed message envelope, or null when it is absent.
     */
    private static function parseMessage(array $responseData): ?Message
    {
        $message = $responseData['message'] ?? null;

        $messageData = self::stringKeyedArray($message);

        // Missing or malformed message metadata leaves advanced displays unavailable rather than creating an empty DTO.
        return $messageData !== null ? Message::fromArray($messageData) : null;
    }

    /**
     * Reads an optional context-size field without exposing unsafe numeric conversions to the UI.
     * Use it during hydration; missing, nonnumeric, non-finite, or out-of-range values become null so the hint can be omitted.
     *
     * @param array<string, mixed> $responseData Decoded response containing an optional context-size field.
     * @param string $responseFieldName Response field that may contain a token count; an empty name finds no field and returns null.
     * @return ?int Token count for UI hints, or null when unavailable.
     */
    private static function nullableIntField(array $responseData, string $responseFieldName): ?int
    {
        $wireValue = $responseData[$responseFieldName] ?? null;
        // Already a clean integer token count — hand it straight to the app.
        if (is_int($wireValue)) {
            return $wireValue;
        }

        // Some wrappers report the count as a float; accept it only when rounding cannot overflow PHP's integer range.
        if (is_float($wireValue)) {
            return self::roundedNullableInt($wireValue);
        }

        // Other wrappers send numeric strings; preserve exact integers such as PHP_INT_MAX before falling back to float parsing.
        if (is_string($wireValue) && is_numeric($wireValue)) {
            $integerContextSize = filter_var($wireValue, FILTER_VALIDATE_INT);

            // A valid integer string can go straight to the UI without a precision-losing float conversion.
            if ($integerContextSize !== false) {
                return $integerContextSize;
            }

            return self::roundedNullableInt((float) $wireValue);
        }

        return null;
    }

    /**
     * Rounds a context-size number only when it fits the nullable integer shown by app code.
     * Use it after parsing float or numeric-string wire values; unsafe values return null rather than a misleading token count.
     *
     * @param float $wireValue Context-size value supplied by a wrapper; non-finite or out-of-range values mean the size is unavailable.
     * @return int|null Rounded token count, or null so the UI can omit an unsafe or unusable context-size hint.
     */
    private static function roundedNullableInt(float $wireValue): ?int
    {
        $roundedContextSize = round($wireValue);

        // Do not turn NaN, infinity, or an overflowing float into an unrelated integer that the user could mistake for a real limit.
        if (!is_finite($roundedContextSize) || $roundedContextSize >= (float) PHP_INT_MAX || $roundedContextSize < (float) PHP_INT_MIN) {
            return null;
        }

        return (int) $roundedContextSize;
    }

    /**
     * Keeps only string-keyed metadata so advanced app views receive a predictable name-to-value map.
     * Use it at a metadata boundary; non-arrays become null and an empty or numeric-only array becomes an empty map.
     *
     * @param mixed $candidateMetadata Candidate wire metadata; null or another non-array value means no map is available.
     * @return array<string, mixed>|null String-keyed metadata, null for non-array input, or an empty map when no string keys survive.
     */
    private static function stringKeyedArray(mixed $candidateMetadata): ?array
    {
        // Not a map at all — there's no metadata here for the app to read.
        if (!is_array($candidateMetadata)) {
            return null;
        }

        $stringKeyedMetadata = [];
        // Keep only string keys so the app gets a predictable name => value map.
        foreach ($candidateMetadata as $metadataKey => $metadataValue) {
            // Drop any stray numeric keys the wrapper may have mixed in.
            if (is_string($metadataKey)) {
                $stringKeyedMetadata[$metadataKey] = $metadataValue;
            }
        }

        return $stringKeyedMetadata;
    }
}
