---
category: integration
last_reviewed: 2026-05-08
---

## Footgun: Symfony DependencyInjection has its own StrandsClientFactory subclass

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED

`src/Integration/Symfony/DependencyInjection/StrandsClientFactory.php` extends `src/Integration/StrandsClientFactory.php`. Changes to the shared factory's constructor signature or `create()` method must be mirrored in the Symfony subclass. The Laravel integration uses the shared factory directly — only Symfony subclasses it.

## Footgun: StrandsClient + StreamResult co-change frequently

**Status:** active | **Created:** 2026-05-08 | **Evidence:** OBSERVED
**Source:** git history (auto-seeded)

`src/StrandsClient.php` and `tests/Unit/StrandsClientStreamTest.php` have both been modified in 8 of the last ~20 commits. Adding new streaming features requires coordinated changes across `StrandsClient` (accumulation logic), `StreamResult` (new fields), `StreamEvent` (new event types), and their respective tests.
