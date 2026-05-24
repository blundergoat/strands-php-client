#!/usr/bin/env bash
# Bump the project version: stamp the CHANGELOG "Unreleased" section with
# today's date and insert a fresh "Unreleased" block above it. Prints the
# `git tag` command for the user to run (the script never tags itself).
#
# Usage:
#   ./scripts/bump-version.sh                   # release the currently-staged version
#   ./scripts/bump-version.sh 1.5.1             # override to an explicit version
#   ./scripts/bump-version.sh --patch           # bump staged version's patch
#   ./scripts/bump-version.sh --minor           # bump staged version's minor
#   ./scripts/bump-version.sh --major           # bump staged version's major
#   ./scripts/bump-version.sh --dry-run         # show changes without writing
#
# The CHANGELOG.md header is expected in one of these forms:
#   ## [X.Y.Z] - Unreleased      (staged version)
#   ## [Unreleased]              (no staged version - explicit version required)

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
err()  { echo -e "  ${RED}✘${RESET} $*" >&2; }
die()  { err "$*"; exit 1; }

CHANGELOG="CHANGELOG.md"
SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+$'

# ── Args ─────────────────────────────────────────────────────────
EXPLICIT_VERSION=""
BUMP_LEVEL=""
DRY_RUN=false

for arg in "$@"; do
    case "$arg" in
        --patch|--minor|--major)
            [[ -n "$BUMP_LEVEL" ]] && die "Multiple bump levels passed"
            [[ -n "$EXPLICIT_VERSION" ]] && die "Cannot combine explicit version with --${arg#--}"
            BUMP_LEVEL="${arg#--}"
            ;;
        --dry-run)
            DRY_RUN=true
            ;;
        -h|--help)
            sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        --*)
            die "Unknown flag: $arg"
            ;;
        *)
            [[ -n "$EXPLICIT_VERSION" ]] && die "Multiple version arguments passed"
            [[ -n "$BUMP_LEVEL" ]] && die "Cannot combine explicit version with --$BUMP_LEVEL"
            [[ "$arg" =~ $SEMVER_RE ]] || die "Invalid version '$arg' (expected X.Y.Z)"
            EXPLICIT_VERSION="$arg"
            ;;
    esac
done

[[ -f "$CHANGELOG" ]] || die "$CHANGELOG not found"

# ── Locate the topmost Unreleased header ─────────────────────────
HEADER_LINE=$(grep -n -E '^## \[(Unreleased|[0-9]+\.[0-9]+\.[0-9]+)\]( - Unreleased)?$' "$CHANGELOG" | head -1 || true)
[[ -n "$HEADER_LINE" ]] || die "No '## [Unreleased]' or '## [X.Y.Z] - Unreleased' header found in $CHANGELOG"

HEADER_LINENO="${HEADER_LINE%%:*}"
HEADER_TEXT="${HEADER_LINE#*:}"

STAGED_VERSION=""
if [[ "$HEADER_TEXT" =~ ^\#\#\ \[([0-9]+\.[0-9]+\.[0-9]+)\]\ -\ Unreleased$ ]]; then
    STAGED_VERSION="${BASH_REMATCH[1]}"
elif [[ "$HEADER_TEXT" =~ ^\#\#\ \[Unreleased\]$ ]]; then
    STAGED_VERSION=""
else
    die "Topmost header at line $HEADER_LINENO is not a recognised Unreleased header: $HEADER_TEXT"
fi

# ── Decide the new version ───────────────────────────────────────
NEW_VERSION=""
if [[ -n "$EXPLICIT_VERSION" ]]; then
    NEW_VERSION="$EXPLICIT_VERSION"
elif [[ -n "$BUMP_LEVEL" ]]; then
    BASE="$STAGED_VERSION"
    if [[ -z "$BASE" ]]; then
        # Fall back to latest git tag
        BASE=$(git tag --sort=-v:refname 2>/dev/null | head -1 | sed 's/^v//' || true)
        [[ "$BASE" =~ $SEMVER_RE ]] || die "Cannot $BUMP_LEVEL-bump: no staged version and no semver git tag found"
        info "No staged version; bumping from latest tag v$BASE"
    fi
    IFS='.' read -r MAJ MIN PAT <<<"$BASE"
    case "$BUMP_LEVEL" in
        patch) PAT=$((PAT + 1)) ;;
        minor) MIN=$((MIN + 1)); PAT=0 ;;
        major) MAJ=$((MAJ + 1)); MIN=0; PAT=0 ;;
    esac
    NEW_VERSION="$MAJ.$MIN.$PAT"
else
    [[ -n "$STAGED_VERSION" ]] || die "No staged version in $CHANGELOG; pass an explicit version or --patch/--minor/--major"
    NEW_VERSION="$STAGED_VERSION"
fi

TODAY=$(date '+%Y-%m-%d')
NEW_HEADER="## [$NEW_VERSION] - $TODAY"
UNRELEASED_BLOCK=$'## [Unreleased]\n\n### Added\n\n### Changed\n\n### Fixed\n'

echo ""
echo -e "${BOLD}  Bump version - strands-php-client${RESET}"
echo -e "  ${DIM}$(printf '─%.0s' {1..44})${RESET}"
echo ""
info "Staged version:    ${STAGED_VERSION:-<none>}"
info "New version:       $NEW_VERSION"
info "Today:             $TODAY"
info "CHANGELOG header:  line $HEADER_LINENO -> $NEW_HEADER"

# ── Rewrite CHANGELOG ────────────────────────────────────────────
TMPFILE=$(mktemp)
trap 'rm -f "$TMPFILE"' EXIT

# Lines before the existing Unreleased header
if (( HEADER_LINENO > 1 )); then
    head -n $((HEADER_LINENO - 1)) "$CHANGELOG" > "$TMPFILE"
else
    : > "$TMPFILE"
fi

# Insert new Unreleased block + blank line + stamped header
{
    printf '%s\n' "$UNRELEASED_BLOCK"
    printf '%s\n' "$NEW_HEADER"
} >> "$TMPFILE"

# Everything after the existing Unreleased header line
tail -n +$((HEADER_LINENO + 1)) "$CHANGELOG" >> "$TMPFILE"

if [[ "$DRY_RUN" == "true" ]]; then
    echo ""
    info "Dry-run diff:"
    diff -u "$CHANGELOG" "$TMPFILE" || true
    echo ""
    ok "Dry-run complete (no changes written)"
else
    mv "$TMPFILE" "$CHANGELOG"
    trap - EXIT
    ok "Rewrote $CHANGELOG"
fi

# ── Print git tag command for user to run ────────────────────────
echo ""
echo -e "${BOLD}  Next step (run yourself):${RESET}"
echo ""
echo "    git tag -a v$NEW_VERSION -m \"Release v$NEW_VERSION\""
echo "    git push origin v$NEW_VERSION"
echo ""
