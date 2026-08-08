---
category: ci
last_reviewed: 2026-08-08
---

# Footguns — CI and dependency matrix

Traps in `.github/workflows/ci.yml` and the dependency matrix it resolves. Read before widening a matrix leg or changing a quality threshold.

## Footgun: Symfony 8 cannot be added to the CI matrix, and the blocker is dev tooling

**Status:** active | **Created:** 2026-08-08 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Do not add a `symfony: '^8.0'` matrix leg to close the gap between `composer.json` and CI. It fails on `composer update`, not on this library, and the red is misleading.
**Trigger phase:** ACT
**hallucination-risk:** high

**Symptoms:** `composer.json` allows `symfony/*: ^6.4 || ^7.0 || ^8.0` and the README advertises it, but `.github/workflows/ci.yml` (search: `symfony: ['^6.4', '^7.0']`) never tests Symfony 8. The obvious fix — an `include:` leg pairing PHP 8.4 with `^8.0` — looks safe and is not.

**Why it happens:** `phpmd/phpmd` 2.15.0 is the newest stable release and requires `pdepend/pdepend ^2.16.1`. PDepend 2.16.2, also the newest stable, still declares `symfony/config ^2.3.0|^3|^4|^5|^6.0|^7.0`. Resolving Symfony 8 alongside the dev tooling is therefore impossible, and the failure surfaces during dependency installation with a PDepend conflict message that names neither Symfony 8 nor this library.

**Evidence:** `composer update --dry-run --ignore-platform-req=php --with symfony/config:^8.0 ...` fails with `pdepend/pdepend[2.16.1, ..., 2.16.2] require symfony/config ^2.3.0|^3|^4|^5|^6.0|^7.0 ... but it conflicts with your temporary update constraint (symfony/config:^8.0)`. Separately, `symfony/http-client` v8.0.x requires `php >=8.4` and v8.1.x requires `php >=8.4.1`, so any such leg must also pin PHP 8.4.

**Prevention:** Leave the matrix at `^6.4` and `^7.0` until PDepend ships Symfony 8 support. The constraint is dev-only: consumers installing this library into a Symfony 8 application never pull `phpmd`, so the `^8.0` allowance in `composer.json` is correct and should not be narrowed. If Symfony 8 coverage becomes urgent before upstream moves, add a leg that installs without the static-analysis tooling and runs only the test suite, rather than deleting the constraint or the claim.

## Resolved Entries

## Footgun: CI flags can silently override `infection.json5` thresholds

**Status:** resolved | **Created:** 2026-08-08 | **Evidence:** ACTUAL_MEASURED
**Resolution:** The mutation step now runs `vendor/bin/infection --threads=4` with no threshold flags, so `infection.json5` is the single source of truth.

The mutation job previously passed `--min-msi=80 --min-covered-msi=80` while `infection.json5` declared `minMsi` and `minCoveredMsi` of 90, and `CONTRIBUTING.md` documented 90. Command-line flags win over the config file, so CI enforced a threshold ten points weaker than both the committed config and the contributor docs, and nothing reported the divergence. The job also carries `continue-on-error: true`, so the weaker gate could not even fail the build.

**Evidence:** `.github/workflows/ci.yml` (search: `Run mutation testing`) and `infection.json5` (search: `minCoveredMsi`).
