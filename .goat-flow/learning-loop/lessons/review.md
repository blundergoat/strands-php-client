---
category: review
last_reviewed: 2026-08-08
---

## Lesson: A Bot Finding's Code Shape Can Be Real While Its Failure Scenario Is Not

**Created:** 2026-08-08
**Decision changed:** Before agreeing with an automated review finding, reproduce the failure it describes, not just the code shape it points at. Confirming the shape and assuming the consequence produces confident, wrong agreement.
**Trigger phase:** VERIFY
**What happened:** Triaging 34 bot comments on an external PR, five findings against the shared goat-flow hooks read as clearly correct after reading the cited code. Two did not survive an attempt to reproduce them.

A finding claimed the Bash 3 secret scanner misses `EXPORT TOKEN="ghp_..."` because its assignment regex accepts only lowercase `export`. The regex asymmetry is real and verifiable. The scenario is not: that payload is caught on both paths, because the GitHub token pattern matches the raw line before the assignment classifier is consulted. No constructed input made the two paths disagree.

A second finding claimed a liveness marker is written before a bail, so a session can go permanently silent. The write does precede the bail. The bail is unreachable: both callers populate the path list through a predicate that already requires a resolvable analyzer, so the sampled path always has one.

**Evidence:** `.goat-flow/hooks/post-turn-safety.sh` (search: `GITHUB_LEGACY_TOKEN_RE`) matches the raw line independently of the assignment path; `.goat-flow/hooks/gruff-code-quality.sh` (search: `supported_candidate_path`) requires a non-empty variant before a path reaches the announcement.

**Prevention:** Treat a bot's cited code as a lead and its stated consequence as a claim. Build the input it describes and run it. When the reproduction fails, report the finding as a code-shape or maintainability observation rather than the defect it was filed as, and say which half survived. A partly-true finding accepted whole is worse than a rejected one, because it ships a fix for a problem that does not exist.

## Lesson: Reproduce Against The Code Path The Defect Lives In

**Created:** 2026-08-08
**Decision changed:** Before treating a reproduction as evidence, confirm it exercised the branch under test; a passing or failing result from an adjacent path proves nothing about the one in question.
**Trigger phase:** VERIFY
**What happened:** Testing a claimed diff-parsing vulnerability, the harness used a repository with an unborn HEAD so no commit was needed. The payload was detected, which looked like a refutation. It was not: with an unborn HEAD the hook content-scans files instead of reading a diff, so the diff parser under test never ran. The finding remained neither confirmed nor refuted by that experiment, and only structural reading supported it.

**Evidence:** `.goat-flow/hooks/post-turn-safety.sh` (search: `collect_z git ls-files -z`) selects the content path when HEAD is absent, while tracked modifications against an existing HEAD route to the diff reader.

**Prevention:** Name the target branch before building the harness, then assert the harness reached it — via a distinguishing message, a trace, or a deliberate control that only that branch can produce. When a required precondition is unavailable (here, creating a commit), say the claim stands on static evidence rather than presenting an adjacent-path result as a verdict.
