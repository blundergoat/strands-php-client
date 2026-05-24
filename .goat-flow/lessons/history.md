---
category: history
last_reviewed: 2026-05-24
---

# Lessons — History

Behavioural lessons drawn from incidents in git history. Format: what happened, what the right move was.

## Lesson: Pin library dependency constraints across multiple major versions

**Created:** 2026-05-24 | **Source:** git history (commit `dda477d`)

The `psr/log` constraint was originally too narrow (one major version), which immediately blocked installation in projects that had already bumped to a newer PSR-3 major. The fix (`dda477d fix: update psr/log version constraint to support multiple major versions`) widened it to `^1.0 || ^2.0 || ^3.0`. **Lesson:** for a library (not an app), PSR-shaped dependencies should accept all currently-supported majors of the stable PSR interface by default. Narrow them only when you actually use a feature that requires a specific major. The default narrow-pin habit from application development is the wrong instinct here.

**How to apply:** when adding or bumping any `psr/*` or other widely-adopted interface package in `composer.json`, default to an OR-of-majors constraint. Justify any single-major pin with the specific incompatible feature relied on.

## Lesson: Treat a release as the moment to harden — not the moment to ship and move on

**Created:** 2026-05-24 | **Source:** git history (commit `918587a`)

The `918587a Harden v1.4.0: interrupt fail-fast, MIME fixes, auth docs, regression tests` commit bundled four post-feature-cut fixes immediately after the v1.4.0 feature work landed: interrupt handling now fails fast on malformed payloads, MIME types were corrected, auth docs were filled in, and regression tests were added. **Lesson:** the first commits after a feature release are disproportionately bug fixes from real consumer exposure. Schedule a hardening pass after every minor release — interrupt paths, content-type handling, and developer-facing docs are the recurring weak spots in this codebase.

**How to apply:** when reviewing a feature PR, ask explicitly: "what fails when input is malformed?", "are MIME types pinned in every direction?", "is the doc visible to new consumers, not just in the changelog?". When merging a release, open a follow-up issue for the hardening pass before closing the milestone.
