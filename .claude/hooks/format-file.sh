#!/usr/bin/env bash
# =============================================================================
# format-file.sh - PostToolUse hook: auto-format files after Edit/Write
# =============================================================================
# Event:  PostToolUse (Claude), AfterTool (Gemini), AfterToolUse (Codex)
# Match:  Edit|Write tool calls
# Always exits 0 - formatting failures should never block the agent.
#
# Install (Claude): copy to .claude/hooks/format-file.sh
# Register in .claude/settings.json:
#   "PostToolUse": [{ "matcher": "Edit|Write", "hooks": [{
#     "type": "command",
#     "command": "bash \"$(git rev-parse --show-toplevel)/.claude/hooks/format-file.sh\""
#   }]}]
#
# SKIP THIS HOOK if your project has no formatter configured. Do NOT reuse
# this hook to re-run the linter - that duplicates the Stop hook.
# =============================================================================
set -uo pipefail

# --- JSON Input Parsing ------------------------------------------------------
# PostToolUse payloads use .file_path at the top level (NOT .tool_input.command).
INPUT=$(cat)

if command -v jq >/dev/null 2>&1; then
  FILE_PATH=$(echo "$INPUT" | jq -r '.file_path // empty' 2>/dev/null)
else
  FILE_PATH=$(echo "$INPUT" | sed -n 's/.*"file_path"\s*:\s*"\([^"]*\)".*/\1/p' | head -1)
fi

[[ -z "$FILE_PATH" ]] && exit 0

# --- Skip agent config directories -------------------------------------------
# Formatters (prettier, php-cs-fixer) rewrite skill files and hook configs,
# collapsing numbered lists and changing shell syntax. Always skip these dirs.
case "$FILE_PATH" in
  */.claude/*|.claude/*) exit 0 ;;
  */.gemini/*|.gemini/*) exit 0 ;;
  */.codex/*|.codex/*) exit 0 ;;
  */.agents/*|.agents/*) exit 0 ;;
  */.github/skills/*|.github/skills/*) exit 0 ;;
esac

# --- Extension-based formatter routing ----------------------------------------
# CUSTOMIZE: replace these commands with your project's formatter.
# Each block checks that the tool exists before running (command -v).
case "$FILE_PATH" in
  *.ts|*.tsx|*.js|*.jsx|*.json|*.css|*.scss)
    # Prettier (via npx to use project-local version)
    if command -v npx >/dev/null 2>&1; then
      npx prettier --write "$FILE_PATH" 2>/dev/null || true
    fi
    ;;
  *.py)
    # Black (Python formatter)
    if command -v black >/dev/null 2>&1; then
      black -q "$FILE_PATH" 2>/dev/null || true
    fi
    ;;
  *.sh)
    # shfmt (shell formatter)
    if command -v shfmt >/dev/null 2>&1; then
      shfmt -w "$FILE_PATH" 2>/dev/null || true
    fi
    ;;
  *.php)
    # php-cs-fixer (PHP formatter) - prefer project-local vendor binary
    if [ -x vendor/bin/php-cs-fixer ]; then
      vendor/bin/php-cs-fixer fix "$FILE_PATH" --quiet 2>/dev/null || true
    elif command -v php-cs-fixer >/dev/null 2>&1; then
      php-cs-fixer fix "$FILE_PATH" --quiet 2>/dev/null || true
    fi
    ;;
  # CUSTOMIZE: add more extensions here
  # *.go)
  #   command -v gofmt >/dev/null 2>&1 && gofmt -w "$FILE_PATH" 2>/dev/null || true
  #   ;;
  # *.rs)
  #   command -v rustfmt >/dev/null 2>&1 && rustfmt "$FILE_PATH" 2>/dev/null || true
  #   ;;
esac

# --- Always exit 0 ------------------------------------------------------------
exit 0
