#!/usr/bin/env bash
# Update all project dependencies (composer + npm) within their version constraints.
#
# Use this during maintenance to preview or refresh the lockfiles consumed by developers and CI.
# Empty optional flags update both dependency sets and write their lockfiles.
#
# Usage: ./scripts/dependencies-update.sh [--composer-only] [--npm-only] [--dry-run]
#   --composer-only Skip the npm step
#   --npm-only      Skip the composer step
#   --dry-run       Show what would change without writing lockfiles

set -euo pipefail

cd "$(dirname "$0")/.."

# ── Colors ───────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
DIM='\033[2m'
BOLD='\033[1m'
RESET='\033[0m'

# Show the dependency update currently running.
info() { echo -e "${BLUE}▸${RESET} $*"; }
# Confirm a dependency update completed successfully.
ok()   { echo -e "  ${GREEN}✔${RESET} $*"; }
# Explain a skipped optional dependency update.
warn() { echo -e "  ${YELLOW}!${RESET} $*"; }
# Show an update error the developer must resolve.
err()  { echo -e "  ${RED}✘${RESET} $*"; }

# ── Args ─────────────────────────────────────────────────────────
RUN_COMPOSER=true
RUN_NPM=true
DRY_RUN=false

# Apply each update option so the developer gets the requested dependency sets and preview mode.
for arg in "$@"; do
    case "$arg" in
        --composer-only) RUN_NPM=false ;;
        --npm-only)      RUN_COMPOSER=false ;;
        --dry-run)       DRY_RUN=true ;;
        -h|--help)
            sed -n '2,6p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            err "Unknown argument: $arg"
            exit 1
            ;;
    esac
done

echo ""
echo -e "${BOLD}  Update dependencies - strands-php-client${RESET}"
echo -e "  ${DIM}$(printf '─%.0s' {1..44})${RESET}"
echo ""

# ── Composer ─────────────────────────────────────────────────────
# Update Composer packages unless the developer explicitly requested npm only.
if [[ "$RUN_COMPOSER" == "true" ]]; then
    # Missing Composer prevents PHP dependency changes from being calculated safely.
    if ! command -v composer &>/dev/null; then
        err "composer not found in PATH"
        exit 1
    fi
    # Dry-run mode previews PHP package changes without writing composer.lock.
    if [[ "$DRY_RUN" == "true" ]]; then
        info "composer update --dry-run"
        composer update --dry-run
    else
        info "composer update"
        composer update
        ok "composer dependencies updated"
    fi
fi

# ── npm ──────────────────────────────────────────────────────────
# Update npm packages unless the developer explicitly requested Composer only.
if [[ "$RUN_NPM" == "true" ]]; then
    # A project without package.json has no JavaScript lockfile to update.
    if [[ ! -f package.json ]]; then
        warn "No package.json found - skipping npm update"
    else
        # npm must be available before the script can preview or update frontend packages.
        if ! command -v npm &>/dev/null; then
            err "npm not found in PATH"
            exit 1
        fi
        # Dry-run mode lists available frontend updates without writing package-lock.json.
        if [[ "$DRY_RUN" == "true" ]]; then
            info "npm outdated (preview of available updates)"
            npm outdated || true
        else
            info "npm update"
            npm update
            ok "npm dependencies updated"
        fi
    fi
fi

echo ""
# The final message tells the developer whether dependency files were only inspected or actually updated.
if [[ "$DRY_RUN" == "true" ]]; then
    ok "Dry-run complete (no changes written)"
else
    ok "All dependencies updated"
fi
