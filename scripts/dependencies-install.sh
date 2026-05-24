#!/usr/bin/env bash
# Install all project dependencies (composer + npm).
# Usage: ./scripts/dependencies-install.sh [--prod] [--composer-only] [--npm-only]
#   --prod          Install without dev dependencies (composer --no-dev, npm --omit=dev)
#   --composer-only Skip the npm step
#   --npm-only      Skip the composer step

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
PROD=false
RUN_COMPOSER=true
RUN_NPM=true

for arg in "$@"; do
    case "$arg" in
        --prod)          PROD=true ;;
        --composer-only) RUN_NPM=false ;;
        --npm-only)      RUN_COMPOSER=false ;;
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
echo -e "${BOLD}  Install dependencies - strands-php-client${RESET}"
echo -e "  ${DIM}$(printf '─%.0s' {1..44})${RESET}"
echo ""

# ── Composer ─────────────────────────────────────────────────────
if [[ "$RUN_COMPOSER" == "true" ]]; then
    if ! command -v composer &>/dev/null; then
        err "composer not found in PATH"
        exit 1
    fi
    if [[ "$PROD" == "true" ]]; then
        info "composer install --no-dev --optimize-autoloader"
        composer install --no-dev --optimize-autoloader
    else
        info "composer install"
        composer install
    fi
    ok "composer dependencies installed"
fi

# ── npm ──────────────────────────────────────────────────────────
if [[ "$RUN_NPM" == "true" ]]; then
    if ! command -v npm &>/dev/null; then
        err "npm not found in PATH"
        exit 1
    fi
    if [[ ! -f package.json ]]; then
        warn "No package.json found - skipping npm install"
    else
        if [[ "$PROD" == "true" ]]; then
            info "npm install --omit=dev"
            npm install --omit=dev
        else
            info "npm install"
            npm install
        fi
        ok "npm dependencies installed"
    fi
fi

echo ""
ok "All dependencies installed"
