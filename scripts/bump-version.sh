#!/usr/bin/env bash
# Prepares the project files for a versioned release.
#
# It dates the current CHANGELOG section and opens a fresh Unreleased section.
# It prints the tag command for the maintainer but never creates the tag itself.
#
# Usage examples:
# - ./scripts/bump-version.sh                   # release the currently-staged version
# - ./scripts/bump-version.sh 1.5.1             # override to an explicit version
# - ./scripts/bump-version.sh --patch           # bump staged version's patch
# - ./scripts/bump-version.sh --minor           # bump staged version's minor
# - ./scripts/bump-version.sh --major           # bump staged version's major
# - ./scripts/bump-version.sh --dry-run         # show changes without writing
#
# The CHANGELOG.md header is expected in one of these forms:
# - ## [X.Y.Z] - Unreleased      (staged version)
# - ## [Unreleased]              (no staged version - explicit version required)

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

# Show a neutral release step to the maintainer.
info() { echo -e "${BLUE}▸${RESET} $*"; }
# Confirm a release step completed successfully.
ok()   { echo -e "  ${GREEN}✔${RESET} $*"; }
# Highlight a recoverable release concern.
warn() { echo -e "  ${YELLOW}!${RESET} $*"; }
# Print a release error without mixing it into normal output.
err()  { echo -e "  ${RED}✘${RESET} $*" >&2; }
# Stop the release helper after showing the actionable error.
die()  { err "$*"; exit 1; }

CHANGELOG="CHANGELOG.md"
SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+$'

# ── Args ─────────────────────────────────────────────────────────
EXPLICIT_VERSION=""
BUMP_LEVEL=""
DRY_RUN=false

# Read each requested bump option so the maintainer sees one unambiguous release outcome.
for arg in "$@"; do
    case "$arg" in
        --patch|--minor|--major)
            # More than one bump level cannot describe a single version the user intends to release.
            [[ -n "$BUMP_LEVEL" ]] && die "Multiple bump levels passed"
            # An explicit version already decides the release number, so a bump flag would conflict with it.
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
            # A second explicit number would make the requested release version ambiguous.
            [[ -n "$EXPLICIT_VERSION" ]] && die "Multiple version arguments passed"
            # A bump flag and explicit number are two competing ways to choose the release version.
            [[ -n "$BUMP_LEVEL" ]] && die "Cannot combine explicit version with --$BUMP_LEVEL"
            # Reject malformed input before the script rewrites the changelog the maintainer will publish.
            [[ "$arg" =~ $SEMVER_RE ]] || die "Invalid version '$arg' (expected X.Y.Z)"
            EXPLICIT_VERSION="$arg"
            ;;
    esac
done

# A missing changelog leaves nowhere to publish the version users will install.
[[ -f "$CHANGELOG" ]] || die "$CHANGELOG not found"

# ── Locate the topmost Unreleased header ─────────────────────────
HEADER_LINE=$(grep -n -E '^## \[(Unreleased|[0-9]+\.[0-9]+\.[0-9]+)\]( - Unreleased)?$' "$CHANGELOG" | head -1 || true)
# An empty search result means the changelog has no safe release section to stamp.
[[ -n "$HEADER_LINE" ]] || die "No '## [Unreleased]' or '## [X.Y.Z] - Unreleased' header found in $CHANGELOG"

HEADER_LINENO="${HEADER_LINE%%:*}"
HEADER_TEXT="${HEADER_LINE#*:}"

STAGED_VERSION=""
# A staged numeric heading supplies the default version when the maintainer did not pass one.
if [[ "$HEADER_TEXT" =~ ^\#\#\ \[([0-9]+\.[0-9]+\.[0-9]+)\]\ -\ Unreleased$ ]]; then
    STAGED_VERSION="${BASH_REMATCH[1]}"
# A generic Unreleased heading means the maintainer must choose or calculate a version below.
elif [[ "$HEADER_TEXT" =~ ^\#\#\ \[Unreleased\]$ ]]; then
    STAGED_VERSION=""
else
    die "Topmost header at line $HEADER_LINENO is not a recognised Unreleased header: $HEADER_TEXT"
fi

# ── Decide the new version ───────────────────────────────────────
NEW_VERSION=""
# An explicit version is the clearest instruction and takes precedence over staged changelog state.
if [[ -n "$EXPLICIT_VERSION" ]]; then
    NEW_VERSION="$EXPLICIT_VERSION"
# A bump flag derives the next version from the staged version or latest release tag.
elif [[ -n "$BUMP_LEVEL" ]]; then
    BASE="$STAGED_VERSION"
    # An empty staged version falls back to the latest tag so --patch/--minor/--major still has a base.
    if [[ -z "$BASE" ]]; then
        # Fall back to latest git tag
        BASE=$(git tag --sort=-v:refname 2>/dev/null | head -1 | sed 's/^v//' || true)
        # A missing or malformed tag leaves no trustworthy release number to increment.
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
    # With no arguments, an empty staged version would leave the release number unknown.
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
# Preserve any changelog introduction above the release heading; line one has no prefix to copy.
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

# A dry run shows exactly what users would see in the changelog without writing the file.
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
