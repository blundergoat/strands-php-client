#!/usr/bin/env bash
# Install all project dependencies (composer + npm).
#
# Use this before local development or CI so the client, tests, and optional frontend tools are ready.
# Empty optional flags install both development dependency sets.
#
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

# Show the dependency step currently running.
info() { echo -e "${BLUE}▸${RESET} $*"; }
# Confirm a dependency step completed successfully.
ok()   { echo -e "  ${GREEN}✔${RESET} $*"; }
# Explain a skipped optional dependency step.
warn() { echo -e "  ${YELLOW}!${RESET} $*"; }
# Show an install error the developer must resolve.
err()  { echo -e "  ${RED}✘${RESET} $*"; }

# ── Args ─────────────────────────────────────────────────────────
PROD=false
RUN_COMPOSER=true
RUN_NPM=true

# Apply each install option so the developer gets the requested dependency sets only.
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
# Run Composer unless the developer explicitly requested npm only.
if [[ "$RUN_COMPOSER" == "true" ]]; then
    # Missing Composer prevents the PHP client and its test tools from being installed.
    if ! command -v composer &>/dev/null; then
        err "composer not found in PATH"
        exit 1
    fi
    # Production mode omits developer-only PHP tools from the deployed application.
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
# Run npm unless the developer explicitly requested Composer only.
if [[ "$RUN_NPM" == "true" ]]; then
    # Missing npm prevents installation only when the requested project includes frontend packages.
    if ! command -v npm &>/dev/null; then
        err "npm not found in PATH"
        exit 1
    fi
    # A project without package.json has no JavaScript dependencies, so the PHP install can still succeed.
    if [[ ! -f package.json ]]; then
        warn "No package.json found - skipping npm install"
    else
        # Production mode omits frontend developer tools from the deployed application.
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
