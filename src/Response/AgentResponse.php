<?php

declare(strict_types=1);

namespace StrandsPhpClient\Response;

use StrandsPhpClient\Exceptions\StrandsException;

/**
 * Represents the complete result an app receives from invoke().
 *
 * Read it for answer text, session continuation, usage, tools, interrupts, citations, and guardrail details.
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
     *        Tools the agent called; empty means no tool activity was reported.
     * @param bool    $hasObjective       Whether the wrapper reported an active objective.
     * @param StopReason|null $stopReason  Known 1.x reason; null means absent or newer, when rawStopReason may still explain the outcome.
     * @param array<string, mixed>|null $structuredOutput Structured answer; null means none was returned; an empty array is a valid result.
     * @param array<string, mixed> $metadata Unrecognised fields plus deprecated aliases; empty means the wrapper sent no extensions.
     * @param list<InterruptDetail> $interrupts  Paused actions raised by the agent; empty means the caller has nothing to answer.
     * @param GuardrailTrace|null $guardrailTrace  Intervention detail; null means no guardrail trace was returned.
     * @param list<array<string, mixed>> $citations  Raw citation blocks; empty means no sources were reported.
     * @param Message|null $message  Normalized raw message for advanced inspection; null means the wrapper omitted it or sent a malformed value.
     * @param array<string, mixed> $wrapperMetadata  Wrapper-owned metadata; empty means no wrapper metadata was supplied.
     * @param int|null $contextSize  Current context tokens; null means the wrapper supplied no capacity value.
     * @param int|null $projectedContextSize  Projected next-turn context tokens; null means the wrapper supplied no forecast.
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
     * Reports whether the agent paused with interrupt details to answer.
     * Use it to decide whether the caller should handle an item from $interrupts.
     *
     * @return bool true when at least one interrupt needs a response.
     */
    public function isInterrupted(): bool
    {
        return $this->interrupts !== [];
    }

    /**
     * Returns cached typed citations for caller-side source handling.
     * Use it when the caller prefers Citation DTOs; an empty result means there are no sources to inspect.
     *
     * @return list<Citation\Citation> Typed citations; empty when nothing was cited.
     */
    public function getCitationObjects(): array
    {
        // Cache the typed list so repeated calls do not hydrate the same citation blocks again.
        if ($this->citationObjects !== null) {
            return $this->citationObjects;
        }

        $this->citationObjects = [];
        // Hydrate each raw citation once; subsequent calls reuse the cached DTO list.
        foreach ($this->citations as $citationData) {
            $this->citationObjects[] = Citation\Citation::fromArray($citationData);
        }

        return $this->citationObjects;
    }

    /**
     * Converts the structured answer into a caller-selected DTO.
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
                $fromArrayMethod = $reflectionClass->getMethod('fromArray');
                // Only a public static fromArray() is safe to call without an instance.
                if ($fromArrayMethod->isStatic() && $fromArrayMethod->isPublic()) {
                    /** @var T */
                    return $fromArrayMethod->invoke(null, $this->structuredOutput);
                }
            }

            // Otherwise map the fields straight onto the constructor's named arguments.
            /** @var T */
            return new $class(...$this->structuredOutput);
        } catch (StrandsException $exception) {
            // The target DTO's own fromArray() may reject a missing field with a deliberate StrandsException.
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
     * Defensively converts decoded /invoke JSON into fields callers can read safely.
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
        // This is present when the caller requested a structured answer instead of plain text only.
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
            contextSize: WireNumber::optionalWholeNumber($data, 'context_size'),
            projectedContextSize: WireNumber::optionalWholeNumber($data, 'projected_context_size'),
            rawStopReason: is_string($rawStopReason) ? $rawStopReason : null,
        );
    }

    /**
     * Converts the optional wire usage block into normalized counters.
     * Use it during response hydration; an absent or malformed block produces a zeroed Usage object.
     *
     * @param array<string, mixed> $responseData Decoded response; a missing usage block produces zeroed counters.
     * @return Usage Token usage for the turn; zeroed when the response omitted it.
     */
    private static function parseUsage(array $responseData): Usage
    {
        /** @var array<string, mixed> $usageData validated before app code uses it. */
        $usageData = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        return Usage::fromArray($usageData);
    }

    /**
     * Filters raw tool activity into small summaries callers can safely inspect.
     * Use it during response hydration; missing or malformed entries are omitted without hiding valid tools around them.
     *
     * @param array<string, mixed> $responseData Decoded response; missing or malformed tool entries produce an empty or filtered activity trail.
     *
     * @return list<array{
     *     name: string,
     *     duration_ms?: int,
     *     input?: array<string, mixed>,
     *     result?: array<string, mixed>
     * }> Safe tool summaries; empty means no valid tool activity.
     */
    private static function parseToolsUsed(array $responseData): array
    {
        $toolsUsed = [];
        $rawTools = is_array($responseData['tools_used'] ?? null) ? $responseData['tools_used'] : [];

        foreach ($rawTools as $toolData) {
            // A tool with no usable name cannot form the required summary shape.
            if (!is_array($toolData) || !isset($toolData['name']) || !is_string($toolData['name'])) {
                continue;
            }

            $toolSummary = ['name' => $toolData['name']];

            // Include how long the tool took when the server timed it (a latency hint).
            if (isset($toolData['duration_ms']) && is_int($toolData['duration_ms'])) {
                $toolSummary['duration_ms'] = $toolData['duration_ms'];
            }

            // Preserve structured tool input when the wrapper reports it.
            if (isset($toolData['input']) && is_array($toolData['input'])) {
                /** @var array<string, mixed> $toolInput validated before app code uses it. */
                $toolInput = $toolData['input'];
                $toolSummary['input'] = $toolInput;
            }

            // Preserve structured tool output when the wrapper reports it.
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
     * Converts raw interrupt blocks into typed follow-up details.
     * Use it during hydration; no valid blocks means the turn needs no approval or follow-up input.
     *
     * @param array<string, mixed> $responseData Decoded response; a missing interrupts field means no follow-up was reported.
     *
     * @return list<InterruptDetail> Typed interrupts; empty when no valid follow-up details were reported.
     */
    private static function parseInterrupts(array $responseData): array
    {
        $rawInterrupts = $responseData['interrupts'] ?? null;
        if (!is_array($rawInterrupts)) {
            return [];
        }

        $interrupts = [];
        foreach ($rawInterrupts as $interruptData) {
            // Skip malformed entries without discarding valid interrupts around them.
            if (is_array($interruptData)) {
                /** @var array<string, mixed> $interruptData validated before app code uses it. */
                $interrupts[] = InterruptDetail::fromArray($interruptData);
            }
        }

        return $interrupts;
    }

    /**
     * Finds guardrail detail in either supported wrapper location and converts it to a DTO.
     * Use it during hydration; null means no intervention detail was reported.
     *
     * @param array<string, mixed> $responseData Decoded response; missing guardrail data means no intervention detail was reported.
     * @return ?GuardrailTrace Guardrail trace, or null when the turn had no guardrail intervention.
     */
    private static function parseGuardrailTrace(array $responseData): ?GuardrailTrace
    {
        // Prefer the top-level Wire Contract field when present.
        $guardrailData = $responseData['guardrail_trace'] ?? null;

        // Fall back to nested trace.guardrail for wrappers that keep trace data grouped.
        if (!is_array($guardrailData)) {
            $trace = $responseData['trace'] ?? null;
            // Some wrappers nest the guardrail block inside a broader trace object.
            if (is_array($trace)) {
                $guardrailData = $trace['guardrail'] ?? null;
            }
        }

        // Neither supported shape contained a guardrail object.
        if (!is_array($guardrailData)) {
            return null;
        }

        /** @var array<string, mixed> $guardrailData validated before app code uses it. */
        return GuardrailTrace::fromArray($guardrailData);
    }

    /**
     * Extracts citation blocks from the normalized message so callers can inspect sources.
     * Use it during hydration; missing, empty, or malformed content produces an empty citation list.
     *
     * @param array<string, mixed> $responseData Decoded response; a missing message/content list means no citations were reported.
     *
     * @return list<array<string, mixed>> Raw citation blocks in message order.
     */
    private static function parseCitations(array $responseData): array
    {
        $rawMessage = $responseData['message'] ?? null;
        // No message envelope means there are no citations to pull out.
        if (!is_array($rawMessage)) {
            return [];
        }

        $rawContent = $rawMessage['content'] ?? null;
        // No content blocks means nothing was cited in this answer.
        if (!is_array($rawContent)) {
            return [];
        }

        $citations = [];
        // Scan the answer's blocks for the ones that carry citation data.
        foreach ($rawContent as $contentBlock) {
            // Ignore malformed blocks without discarding valid citations around them.
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
     * Converts the optional raw message envelope for advanced inspection.
     * Use it during hydration; null means the wrapper omitted the message or supplied a non-map value.
     *
     * @param array<string, mixed> $responseData Decoded response; a missing message field leaves advanced message details unavailable.
     * @return ?Message Parsed message envelope, or null when it is absent.
     */
    private static function parseMessage(array $responseData): ?Message
    {
        $rawMessage = $responseData['message'] ?? null;

        $messageData = self::stringKeyedArray($rawMessage);

        // A missing or malformed message envelope stays null rather than becoming an empty DTO.
        return $messageData !== null ? Message::fromArray($messageData) : null;
    }

    /**
     * Keeps only string-keyed metadata so callers receive a predictable name-to-value map.
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
