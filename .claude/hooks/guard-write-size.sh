#!/usr/bin/env bash
# =============================================================================
# guard-write-size.sh - PreToolUse hook: blocks writes that gut a file
# =============================================================================
# Event:  PreToolUse (Claude), BeforeTool (Gemini)
# Match:  Write tool calls only
# Blocks Write operations that would remove >80% of an existing file's content.
# This catches agents accidentally emptying files during refactors.
#
# Exit 0: allow the write
# Exit 2: block the write (stderr message shown as reason)
#
# Install (Claude): copy to .claude/hooks/guard-write-size.sh
# Register in .claude/settings.json:
#   "PreToolUse": [{ "matcher": "Write", "hooks": [{
#     "type": "command",
#     "command": "bash \"$(git rev-parse --show-toplevel)/.claude/hooks/guard-write-size.sh\""
#   }]}]
# =============================================================================
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"

# If jq is unavailable we can't safely parse the hook input, so fail open.
command -v jq >/dev/null 2>&1 || exit 0

# --- JSON Input Parsing ------------------------------------------------------
INPUT=$(cat)
FILE_PATH=$(printf '%s' "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null || true)

# Distinguish "content field absent" (parse failure -> allow) from
# "content is the empty string" (caller is asking to truncate -> still guard).
if printf '%s' "$INPUT" | jq -e '.tool_input | has("content")' >/dev/null 2>&1; then
  HAS_CONTENT=1
  NEW_CONTENT=$(printf '%s' "$INPUT" | jq -r '.tool_input.content // ""' 2>/dev/null || true)
else
  HAS_CONTENT=0
  NEW_CONTENT=""
fi

# If we can't identify the file or the content field is missing, allow (fail open).
[[ -z "$FILE_PATH" || "$HAS_CONTENT" -eq 0 ]] && exit 0

# Resolve relative paths to absolute
[[ "$FILE_PATH" != /* ]] && FILE_PATH="$ROOT/$FILE_PATH"

# --- Skip conditions ----------------------------------------------------------

# New files are always allowed (nothing to compare against)
[[ ! -f "$FILE_PATH" ]] && exit 0

OLD_SIZE=$(wc -c < "$FILE_PATH")
# Measure both sides in bytes so multibyte content isn't undercounted.
NEW_SIZE=$(printf '%s' "$NEW_CONTENT" | wc -c)

# Skip tiny files (under 100 bytes) - not worth guarding
(( OLD_SIZE < 100 )) && exit 0

# --- Size reduction check -----------------------------------------------------
REDUCTION=$(( (OLD_SIZE - NEW_SIZE) * 100 / OLD_SIZE ))

if (( REDUCTION > 80 )); then
  echo "BLOCKED: This write would remove ${REDUCTION}% of ${FILE_PATH##*/} (${OLD_SIZE} -> ${NEW_SIZE} bytes)." >&2
  echo "If this is intentional (e.g., replacing a file entirely), confirm with the human first." >&2
  exit 2
fi

exit 0
