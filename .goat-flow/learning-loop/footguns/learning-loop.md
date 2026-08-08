---
category: learning-loop
last_reviewed: 2026-08-08
---

# Footguns — Learning-loop tooling

Traps in how `goat-flow index` and `goat-flow stats --check` read this directory. Read before adding, restructuring, or auditing learning-loop entries.

## Footgun: `stats --check` validates only one semantic-anchor form

**Status:** active | **Created:** 2026-08-08 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Do not read `totalStaleRefs: 0` as proof that every cited path resolves. Spot-check evidence paths by hand, and write new anchors in the validated form so the checker can actually see them.
**Trigger phase:** VERIFY
**hallucination-risk:** high

**Symptoms:** `goat-flow stats --check` returns `"status": "pass"` and `"totalStaleRefs": 0` while entries in the same bucket cite files that do not exist on disk.

**Why it happens:** The stale-ref scanner resolves a path only when the path sits in its own backtick span and the `(search: ...)` clause follows *outside* that span — the two-span form. When the path and the search clause are wrapped together inside a *single* backtick span, the scanner does not treat it as a file reference at all, so the path is never resolved and never flagged. The two forms render almost identically to a human reader, which is why the gap survives review.

**Evidence:** on 2026-08-08 six entries across the `gruff-php` buckets cited `vendor/blundergoat/gruff-php/src/Rule/...` paths in the single-span form while `vendor/blundergoat/` did not exist at all, and `stats --check` still returned `"status": "pass"` with `totalStaleRefs: 0`. Adding one entry to `.goat-flow/learning-loop/lessons/gruff-test-quality.md` in the two-span form made the same class of dead path fail on the very next run — that asymmetry is the whole finding. The dead paths have since been repaired (the analyzer is installed and the citations re-pointed at v0.5.1), so the *symptom* is gone from this repo while the *scanner gap* remains.

**Prevention:** Write every new evidence citation in the two-span form so the checker can resolve it — see any entry in `.goat-flow/learning-loop/footguns/transport-and-streaming.md` for the shape. When auditing a bucket, resolve its paths directly with `ls` or `grep -F` rather than trusting the aggregate stale-ref count. When an entry documents a tool that is not installed, say so in prose and cite something inside this repo instead of an unreachable dependency path.

## Footgun: a bucket with no entry headings is counted but never indexed

**Status:** active | **Created:** 2026-08-08 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** After adding or restructuring a learning-loop file, confirm it produced an `INDEX.md` row — not just that `stats` counts it.
**Trigger phase:** VERIFY

**Symptoms:** A learning-loop file is present, well written, and passes `stats --check`, but no agent following INDEX-first retrieval ever finds it.

**Why it happens:** `goat-flow stats` counts a file with valid frontmatter as at least one entry, while `goat-flow index` emits rows only for `## Lesson:`, `## Footgun:`, or `## Pattern:` headings — and, for footguns, only for entries whose `**Status:**` is `active`. A long narrative document with its own heading scheme therefore reports as "1 entry" and generates zero index rows. `.goat-flow/learning-loop/lessons/gruff-test-quality.md` sat in that state at 32 KB, roughly half the lessons corpus by size, invisible to the documented retrieval path.

**Evidence:** `goat-flow stats` reported 21 lesson entries while `goat-flow index` wrote 20 rows; adding one conforming `## Lesson:` block to that file closed the gap to 21/21. The parallel footgun gap is benign and intended: `stats` counts resolved entries, `index` correctly excludes them below `## Resolved Entries`.

**Prevention:** Every bucket needs at least one conforming entry heading. For a long reference document, add one indexable entry that states the decision it changes and points into the numbered sections, rather than splitting it into many rows that would crowd the INDEX.
