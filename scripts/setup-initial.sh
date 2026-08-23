#!/bin/bash
# Prepares a development machine to run the project's preflight checks.
#
# Use this once on a new machine so local checks match CI.
# It installs missing tools and packages but never configures app credentials.
#
# Usage: ./scripts/setup-initial.sh
#
# Detects OS and installs:
#   - shellcheck for shell-script validation in preflight
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

# Show the setup step currently running.
info()  { echo -e "${BLUE}▸${RESET} $*"; }
# Confirm a setup step completed successfully.
ok()    { echo -e "  ${GREEN}✔${RESET} $*"; }
# Explain a recoverable setup concern.
warn()  { echo -e "  ${YELLOW}!${RESET} $*"; }
# Show a setup failure the developer must resolve.
err()   { echo -e "  ${RED}✘${RESET} $*"; }

cd "$(dirname "$0")/.." || exit 1

echo ""
echo -e "${BOLD}  Setup - strands-php-client${RESET}"
echo -e "  ${DIM}$(printf '─%.0s' {1..44})${RESET}"
echo ""

# ── Detect OS ────────────────────────────────────────────────────
# Identify the package manager needed to install local quality tools.
detect_os() {
    # Linux distributions expose a stable ID here, which selects the matching installer below.
    if [[ -f /etc/os-release ]]; then
        # shellcheck source=/dev/null
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
    # macOS developers use Homebrew rather than a Linux package manager.
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

# PHP is required to run the client, Composer, and every application-facing test.
if ! command -v php &>/dev/null; then
    MISSING+=("php")
fi

# Composer installs the library's runtime and development packages.
if ! command -v composer &>/dev/null; then
    MISSING+=("composer")
fi

# Git is required by package installation and the contributor workflow.
if ! command -v git &>/dev/null; then
    MISSING+=("git")
fi

# Any missing prerequisite blocks a reliable setup, so list all of them before stopping.
if [[ ${#MISSING[@]} -gt 0 ]]; then
    err "Missing required tools: ${MISSING[*]}"
    echo ""
    echo -e "  ${DIM}Install them first, then re-run this script.${RESET}"
    exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
info "PHP version: ${BOLD}${PHP_VERSION}${RESET}"

# Install ShellCheck only when the developer's machine does not already provide it.
install_shellcheck() {
    # An existing executable is ready for preflight, so avoid modifying system packages.
    if command -v shellcheck &>/dev/null; then
        ok "shellcheck already installed"
        return 0
    fi

    info "Installing shellcheck..."

    case "$OS" in
        debian)
            sudo apt-get update -qq
            sudo apt-get install -y shellcheck
            ;;
        redhat)
            # Current Red Hat-family systems prefer dnf; older ones fall back to yum.
            if command -v dnf &>/dev/null; then
                sudo dnf install -y ShellCheck || sudo dnf install -y shellcheck
            else
                sudo yum install -y ShellCheck || sudo yum install -y shellcheck
            fi
            ;;
        alpine)
            sudo apk add --no-cache shellcheck
            ;;
        arch)
            sudo pacman -S --needed shellcheck
            ;;
        macos)
            # Homebrew supplies ShellCheck on macOS when the developer has installed it.
            if command -v brew &>/dev/null; then
                brew install shellcheck
            else
                err "Homebrew not found. Install shellcheck manually from https://www.shellcheck.net"
                return 1
            fi
            ;;
        *)
            err "Cannot auto-install shellcheck on this OS."
            echo -e "    ${DIM}Install it manually, then re-run this script.${RESET}"
            return 1
            ;;
    esac

    # Re-check the command so the developer gets a clear result if installation did not update PATH.
    if command -v shellcheck &>/dev/null; then
        ok "shellcheck installed successfully"
    else
        err "shellcheck installed but is not on PATH"
        return 1
    fi
}

install_shellcheck

# ── Install PHP extensions ───────────────────────────────────────
# Install one PHP extension and confirm the runtime can load it before preflight uses it.
install_php_ext() {
    local ext="$1"

    # Already loaded?
    # A loaded extension already supports the requested check, so leave the developer's PHP setup unchanged.
    if php -m 2>/dev/null | grep -qi "^${ext}$"; then
        ok "${ext} already installed"
        return 0
    fi

    info "Installing php-${ext}..."

    case "$OS" in
        debian)
            # Try versioned package first (sury/ondrej PPA), fall back to unversioned
            # Prefer the package matching the active PHP version so the extension loads in the same runtime.
            if apt-cache show "php${PHP_VERSION}-${ext}" &>/dev/null; then
                sudo apt-get install -y "php${PHP_VERSION}-${ext}"
            # The distribution's generic package is the fallback when no versioned package exists.
            elif apt-cache show "php-${ext}" &>/dev/null; then
                sudo apt-get install -y "php-${ext}"
            else
                err "Package php-${ext} not found. Add the ondrej/php PPA:"
                echo -e "    ${DIM}sudo add-apt-repository ppa:ondrej/php && sudo apt-get update${RESET}"
                return 1
            fi
            ;;
        redhat)
            # Current Red Hat-family systems prefer dnf; older ones fall back to yum.
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
            # Arch packages this extension through PECL, so missing PECL requires a manual prerequisite first.
            if ! command -v pecl &>/dev/null; then
                err "pecl not found. Install php-pear first: sudo pacman -S php-pear"
                return 1
            fi
            sudo pecl install "${ext}"
            echo "extension=${ext}.so" | sudo tee "$(php -d 'display_errors=stderr' -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/20-${ext}.ini"
            ;;
        macos)
            # Homebrew PHP includes PECL, which installs the coverage extension on macOS.
            if command -v brew &>/dev/null; then
                # Homebrew doesn't package pcov — use pecl (ships with Homebrew PHP)
                # A missing PECL command means Homebrew PHP is not ready to add the coverage extension.
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
            # Unknown systems can still self-install when they expose the portable PECL command.
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
    # Verify the active PHP process can load the extension that coverage checks will use.
    if php -m 2>/dev/null | grep -qi "^${ext}$"; then
        ok "${ext} installed successfully"
    else
        err "${ext} installed but not loading — check your PHP config"
        return 1
    fi
}

# Check if any extensions need installing via apt
# Debian refreshes package metadata once before looking for a missing PCOV package.
if [[ "$OS" == "debian" ]]; then
    # A loaded PCOV extension needs no package lookup or system update.
    if ! php -m 2>/dev/null | grep -qi "^pcov$"; then
        info "Updating apt package list..."
        sudo apt-get update -qq
    fi
fi

# pcov is the recommended lightweight coverage driver
install_php_ext "pcov"

# ── Composer dependencies ────────────────────────────────────────
info "Installing Composer dependencies..."
# A lockfile gives contributors the exact tested dependency versions; without one, Composer resolves the declared ranges.
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
