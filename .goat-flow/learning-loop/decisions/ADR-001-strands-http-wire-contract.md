# ADR-001: Strands HTTP Wire Contract

**Status:** Accepted
**Date:** 2026-05-24

## Decision

`strands-php-client` targets the Strands HTTP Wire Contract v1 emitted by HTTP wrapper services built on top of `strands-agents/sdk-python`.

The PHP client is not a raw sdk-python type mirror. The wrapper contract is the compatibility boundary between PHP applications and Python agents. sdk-python remains the implementation substrate beneath that boundary.

The contract is documented in `docs/wire-contract.md`, with canonical examples in `tests/Fixtures/wire-contract/` and active-wrapper notes in `docs/wire-contract-audit.md`.

## Context

The earlier 1.5.0 planning work framed several features as sdk-python parity. A later audit found that the deployed consumers do not expose raw sdk-python `TypedDict` shapes to PHP. They expose a wrapper-owned JSON/SSE contract.

Durable evidence:

- `src/StrandsClient.php` (search: `buildRequest`) sends the standard wrapper envelope: `message`, optional `session_id`, and optional `context`.
- `src/Context/AgentInput.php` (search: `toPayloadValue`) emits PHP-facing rich content blocks, including URL source helpers that raw sdk-python media types do not model directly.
- `src/Response/AgentResponse.php` (search: `parseCitations`) parses flattened citation blocks under `message.content`.
- `src/Response/GuardrailAssessment.php` and the existing guardrail fixtures parse flattened guardrail assessment records, not raw sdk-python guardrail traces.
- `/home/devgoat/projects/the-summit-chatroom/strands_agents/api/server.py` (search: `InvokeRequest`) exposes a FastAPI wrapper contract and translates PHP request data into sdk-python agent calls.
- `/home/devgoat/projects/healthkit/strands_agents/api/helpers.py` (search: `_extract_usage`) normalizes sdk-python usage counters into snake_case response fields.
- `/home/devgoat/projects/halaxy-agents-lab/strands_agents/api/schemas/shared.py` (search: `AgentUsage`) declares the snake_case usage shape consumed by PHP applications.
- `/home/devgoat/projects/ambient-scribe/docs/workflow.md` (search: `contracts are tightly coupled`) documents the operational coupling between PHP controllers and the Python wrapper API.

The main architectural debt was not a missing PHP DTO. It was that the wrapper contract lived implicitly in several Python services and consumer assumptions.

## Failure Mode Comparison

| Option | Failure mode |
| --- | --- |
| Mirror raw sdk-python types | Breaks existing deployed wrappers and PHP consumers because citations, guardrails, usage, URL media, and stream events are already wrapper-normalized. |
| Treat every wrapper and sdk-python shape as compatible | Pushes shape ambiguity into every DTO and parser, hides server drift, and makes tests unable to prove the contract. |
| Keep the wrapper contract implicit in app services | Recreates the current drift risk: copied helpers can evolve differently and silently break one PHP consumer. |
| Extract a shared Python package immediately | May be the right next step, but premature until the current contract is written down and audited against all active wrappers. |
| Define Strands HTTP Wire Contract v1 | Gives PHP and Python wrappers a single compatibility target while preserving the existing deployed shape. |

## Consequences

- PHP docs should describe the client as a client for Strands HTTP Wire Contract v1, not as a direct mirror of sdk-python `TypedDict` objects.
- New PHP DTOs or builders should be added only after the wrapper contract adopts the corresponding field or block.
- Upstream sdk-python changes are inputs to wrapper evolution, not automatic PHP-client gaps.
- Existing flattened citation and guardrail DTOs are valid for v1 of the wrapper contract.
- URL media helpers are wrapper extensions. Wrappers that accept URL sources must fetch, validate, and translate them before invoking sdk-python or a model provider.
- Usage counters should be emitted in snake_case by wrappers. Wrapper tests should catch regressions where raw sdk-python camelCase leaks through.

## Reversibility

This decision is reversible if an official Strands HTTP gateway becomes the target and exposes raw sdk-python-compatible wire shapes.

Reversal would require a new ADR, a v2 contract, migration fixtures, and compatibility guidance for existing wrapper-based consumers. Until then, v1 remains the PHP-facing contract.
