---
category: integration
last_reviewed: 2026-08-08
---

## Footgun: Symfony DependencyInjection has its own StrandsClientFactory subclass

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`src/Integration/Symfony/DependencyInjection/StrandsClientFactory.php` is an empty compatibility subclass of `src/Integration/StrandsClientFactory.php`. Keep implementation logic in the shared factory and preserve the Symfony namespace for existing service definitions. Do not duplicate the inherited constructor or `create()` method; shared-factory changes already apply through inheritance.

## Footgun: Streaming changes cross client, DTO, and test boundaries

**Status:** active | **Created:** 2026-05-08 | **Evidence:** ACTUAL_MEASURED
**Source:** 20-commit history ending at `a4e30ff`

In the 20 commits ending at `a4e30ff`, five modified both `src/StrandsClient.php` and `tests/Unit/StrandsClientStreamTest.php`. Streaming behavior is distributed across `StrandsClient` accumulation and cancellation, `StreamResult` fields, `StreamEvent` hydration and types, and the streaming tests. Inspect all four surfaces before changing streaming and edit only those required by the behavior.
