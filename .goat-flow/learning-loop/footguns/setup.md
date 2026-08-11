---
category: setup
last_reviewed: 2026-08-11
---

# Setup Footguns

## Footgun: `goat-flow install --force` rewrites project-owned policy that the dry-run preview never lists

**Status:** active | **Created:** 2026-08-11 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Before any `goat-flow install --force`, copy the project-owned policy and settings files somewhere outside the repository, then diff them afterwards. Do not treat a clean `--dry-run` preview as the blast radius.
**Trigger phase:** SCOPE

**Symptoms:** A forced install reports success and the expected managed-file count, but hand-written security calibration is gone, the agent permission deny-list is shorter than it was, and a previously enabled hook is disabled. Nothing in the install output names these files.

**Why it happens:** `--dry-run` covers source-backed `system-owned` manifest records and the selected agent's skill mirror. The CLI reference states plainly that it does not simulate config migrations, deprecated cleanup, generated commit guidance, or generated indexes. Apply does more than preview describes, and `--force` is documented as a broad override that "may also replace user-owned settings, config, policies, or seeded guidance". The preview and the apply have different scopes, so a preview listing only `system-owned` entries is not evidence that user-owned content is safe.

**Evidence:** measured on 2026-08-11 upgrading 1.15.0 to 1.15.1 across four agents. The preview listed 64 paths, every one `system-owned`, and no settings, config, or policy file appeared in it. After `install . --agent <id> --force`:

- `.goat-flow/security-policy.md` lost its 64-line repository calibration (approved crypto, `AuthStrategy` assumptions, secret classes, deployment boundaries, untrusted input surfaces) and was replaced by the 21-line "no project-specific security overrides" template. The template also added a genuine new section, `## Default Local Tool and MCP Trust`, so the correct repair is a merge, not a straight restore.
- `.claude/settings.json` `permissions.deny` fell from 81 entries to 46. Dropped rules included `Read(**/.env.prod)`, `Read(**/.env.bak)`, `Read(**/*.p12)`, `Read(**/*.jks)`, `Read(**/*.keystore)`, `Read(**/.netrc)`, `Read(**/.pgpass)`, `Read(**/.git-credentials)`, and the `Edit(**/.env*)` catch-all. `permissions.allow` gained `Edit(.env.example)` and `Edit(**/.env.example)`, which the removed catch-all had previously denied.
- `.goat-flow/config.yaml` had `gruff-code-quality` flipped from `enabled: true` to `enabled: false`, and its explanatory comment block was dropped.

The same run's legitimate upgrades sat in the same files, so a wholesale revert was not an option: `.claude/settings.json` also gained the 1.15.1 launcher command with registration-path verification, the `Stop` post-turn entries, and the bounded gruff response mode.

**Prevention:** Snapshot `.claude/settings.json`, `.goat-flow/config.yaml`, `.goat-flow/security-policy.md`, and any other hand-authored policy file to a path outside the repository before forcing, then diff each one afterwards and merge rather than restore. Commit first where possible, so `git diff` shows the damage directly. For the hook-state file specifically, `goat-flow hooks enable <hook-id>` rewrites `.goat-flow/config.yaml` back to its documented shape, comment block included, and syncs every agent's hook config in the same step — prefer it over editing the YAML by hand.
