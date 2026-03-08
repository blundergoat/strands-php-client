#!/bin/bash
# Setup script: installs everything needed for preflight-checks.sh
# Usage: ./scripts/setup-initial.sh
#
# Detects OS and installs:
#   - PHP extensions required by the project (pcov for coverage)
#   - Composer dependencies

set -euo pipefail

# ── Colors ───────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
DIM='\033[2m'
BOLD='\033[1m'
RESET='\033[0m'

info()  { echo -e "${BLUE}▸${RESET} $*"; }
ok()    { echo -e "  ${GREEN}✔${RESET} $*"; }
warn()  { echo -e "  ${YELLOW}!${RESET} $*"; }
err()   { echo -e "  ${RED}✘${RESET} $*"; }

cd "$(dirname "$0")/.."

echo ""
echo -e "${BOLD}  Setup - strands-php-client${RESET}"
echo -e "  ${DIM}$(printf '─%.0s' {1..44})${RESET}"
echo ""

# ── Detect OS ────────────────────────────────────────────────────
detect_os() {
    if [[ -f /etc/os-release ]]; then
        . /etc/os-release
        case "$ID" in
            ubuntu|debian|pop|linuxmint|elementary)
                echo "debian"
                ;;
            fedora|rhel|centos|rocky|alma)
                echo "redhat"
                ;;
            alpine)
                echo "alpine"
                ;;
            arch|manjaro)
                echo "arch"
                ;;
            *)
                echo "unknown-linux"
                ;;
        esac
    elif [[ "$(uname)" == "Darwin" ]]; then
        echo "macos"
    else
        echo "unknown"
    fi
}

OS=$(detect_os)
info "Detected OS: ${BOLD}${OS}${RESET}"

# ── Check prerequisites ─────────────────────────────────────────
MISSING=()

if ! command -v php &>/dev/null; then
    MISSING+=("php")
fi

if ! command -v composer &>/dev/null; then
    MISSING+=("composer")
fi

if ! command -v git &>/dev/null; then
    MISSING+=("git")
fi

if [[ ${#MISSING[@]} -gt 0 ]]; then
    err "Missing required tools: ${MISSING[*]}"
    echo ""
    echo -e "  ${DIM}Install them first, then re-run this script.${RESET}"
    exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
info "PHP version: ${BOLD}${PHP_VERSION}${RESET}"

# ── Install PHP extensions ───────────────────────────────────────
install_php_ext() {
    local ext="$1"

    # Already loaded?
    if php -m 2>/dev/null | grep -qi "^${ext}$"; then
        ok "${ext} already installed"
        return 0
    fi

    info "Installing php-${ext}..."

    case "$OS" in
        debian)
            # Try versioned package first (sury/ondrej PPA), fall back to unversioned
            if apt-cache show "php${PHP_VERSION}-${ext}" &>/dev/null; then
                sudo apt-get install -y "php${PHP_VERSION}-${ext}"
            elif apt-cache show "php-${ext}" &>/dev/null; then
                sudo apt-get install -y "php-${ext}"
            else
                err "Package php-${ext} not found. Add the ondrej/php PPA:"
                echo -e "    ${DIM}sudo add-apt-repository ppa:ondrej/php && sudo apt-get update${RESET}"
                return 1
            fi
            ;;
        redhat)
            if command -v dnf &>/dev/null; then
                sudo dnf install -y "php-${ext}" || sudo dnf install -y "php${PHP_VERSION/./}-php-${ext}"
            else
                sudo yum install -y "php-${ext}" || sudo yum install -y "php${PHP_VERSION/./}-php-${ext}"
            fi
            ;;
        alpine)
            sudo apk add --no-cache "php${PHP_VERSION/./}-${ext}" || sudo apk add --no-cache "php-${ext}"
            ;;
        arch)
            # Arch doesn't package pcov — use pecl
            if ! command -v pecl &>/dev/null; then
                err "pecl not found. Install php-pear first: sudo pacman -S php-pear"
                return 1
            fi
            sudo pecl install "${ext}"
            echo "extension=${ext}.so" | sudo tee "$(php -d 'display_errors=stderr' -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/20-${ext}.ini"
            ;;
        macos)
            if command -v brew &>/dev/null; then
                # Homebrew doesn't package pcov — use pecl (ships with Homebrew PHP)
                if ! command -v pecl &>/dev/null; then
                    err "pecl not found. Install with: brew install php"
                    return 1
                fi
                pecl install "${ext}"
            else
                err "Homebrew not found. Install it from https://brew.sh"
                return 1
            fi
            ;;
        *)
            # Generic fallback: try pecl
            if command -v pecl &>/dev/null; then
                sudo pecl install "${ext}"
                echo "extension=${ext}.so" | sudo tee "$(php -d 'display_errors=stderr' -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/20-${ext}.ini"
            else
                err "Cannot auto-install php-${ext} on this OS."
                echo -e "    ${DIM}Install it manually, then re-run this script.${RESET}"
                return 1
            fi
            ;;
    esac

    # Verify it loaded
    if php -m 2>/dev/null | grep -qi "^${ext}$"; then
        ok "${ext} installed successfully"
    else
        err "${ext} installed but not loading — check your PHP config"
        return 1
    fi
}

# Check if any extensions need installing via apt
if [[ "$OS" == "debian" ]]; then
    if ! php -m 2>/dev/null | grep -qi "^pcov$"; then
        info "Updating apt package list..."
        sudo apt-get update -qq
    fi
fi

# pcov is the recommended lightweight coverage driver
install_php_ext "pcov"

# ── Composer dependencies ────────────────────────────────────────
info "Installing Composer dependencies..."
if [[ -f composer.lock ]]; then
    composer install --no-interaction --quiet
else
    composer install --no-interaction
fi
ok "Composer dependencies installed"

# ── Summary ──────────────────────────────────────────────────────
echo ""
echo -e "  ${DIM}$(printf '─%.0s' {1..44})${RESET}"
echo ""
echo -e "  ${GREEN}${BOLD}Setup complete${RESET}"
echo -e "  ${DIM}Run: composer preflight${RESET}"
echo ""
