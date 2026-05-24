#!/usr/bin/env bash
# Update all project dependencies (composer + npm) within their version constraints.
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

info() { echo -e "${BLUE}▸${RESET} $*"; }
ok()   { echo -e "  ${GREEN}✔${RESET} $*"; }
warn() { echo -e "  ${YELLOW}!${RESET} $*"; }
err()  { echo -e "  ${RED}✘${RESET} $*"; }

# ── Args ─────────────────────────────────────────────────────────
RUN_COMPOSER=true
RUN_NPM=true
DRY_RUN=false

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
if [[ "$RUN_COMPOSER" == "true" ]]; then
    if ! command -v composer &>/dev/null; then
        err "composer not found in PATH"
        exit 1
    fi
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
if [[ "$RUN_NPM" == "true" ]]; then
    if ! command -v npm &>/dev/null; then
        err "npm not found in PATH"
        exit 1
    fi
    if [[ ! -f package.json ]]; then
        warn "No package.json found - skipping npm update"
    else
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
if [[ "$DRY_RUN" == "true" ]]; then
    ok "Dry-run complete (no changes written)"
else
    ok "All dependencies updated"
fi
