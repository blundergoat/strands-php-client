#!/usr/bin/env bash
if [ "${STOP_HOOK_ACTIVE:-}" = "1" ]; then
  exit 0
fi
export STOP_HOOK_ACTIVE=1
ROOT=$(git rev-parse --show-toplevel 2>/dev/null)
[ -z "$ROOT" ] && exit 0
cd "$ROOT" || exit 0
ERRORS=""
CHANGED=$(git diff --name-only --diff-filter=ACMR HEAD 2>/dev/null || true)
CHANGED_SH=$(echo "$CHANGED" | grep '\.sh$' || true)
CHANGED_PHP=$(echo "$CHANGED" | grep '\.php$' || true)
if [ -z "$CHANGED_SH" ] && [ -z "$CHANGED_PHP" ]; then
  exit 0
fi
if [ -n "$CHANGED_SH" ]; then
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    [ -f "$f" ] || continue
    if ! bash -n "$f" 2>/dev/null; then
      ERRORS="${ERRORS}bash syntax error in $f
"
    fi
    if command -v shellcheck >/dev/null 2>&1; then
      if ! OUT=$(shellcheck "$f" 2>&1); then
        ERRORS="${ERRORS}shellcheck issues in $f:
${OUT}
"
      fi
    fi
  done <<< "$CHANGED_SH"
fi
if [ -n "$CHANGED_PHP" ]; then
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    [ -f "$f" ] || continue
    if ! OUT=$(php -l "$f" 2>&1); then
      ERRORS="${ERRORS}php -l failed for $f:
${OUT}
"
    fi
  done <<< "$CHANGED_PHP"
  if [ -x vendor/bin/phpstan ]; then
    # shellcheck disable=SC2086
    if ! OUT=$(vendor/bin/phpstan analyse --no-progress --error-format=raw $CHANGED_PHP 2>&1); then
      ERRORS="${ERRORS}phpstan analyse failed:
${OUT}
"
    fi
  fi
fi
if [ -n "$ERRORS" ]; then
  echo -e "Stop hook found issues:
$ERRORS" >&2
fi
exit 0
