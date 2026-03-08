#!/bin/bash
# Preflight check: Run all quality gates before committing
# Usage: ./scripts/preflight-checks.sh [--coverage-min=80] [--mutate]

cd "$(dirname "$0")/.."

# ── Colors & Symbols ──────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
DIM='\033[2m'
BOLD='\033[1m'
RESET='\033[0m'

PASS="${GREEN}✔${RESET}"
FAIL="${RED}✘${RESET}"
SKIP="${YELLOW}○${RESET}"
ARROW="${BLUE}▸${RESET}"

# ── State ─────────────────────────────────────────────────────────
TOTAL=0
PASSED=0
FAILED=0
FAILURES=()
# php hrtime works on macOS+Linux (date +%s%N is GNU-only)
now_ns() { php -r 'echo hrtime(true);'; }
START_TIME=$(now_ns)
MIN_COVERAGE=80
RUN_MUTATE=false

for arg in "$@"; do
    case "$arg" in
        --mutate)
            RUN_MUTATE=true
            ;;
        --coverage-min=*)
            MIN_COVERAGE="${arg#*=}"
            ;;
    esac
done

if ! [[ "$MIN_COVERAGE" =~ ^[0-9]+$ ]] || ((MIN_COVERAGE < 0 || MIN_COVERAGE > 100)); then
    echo "Invalid --coverage-min value: '$MIN_COVERAGE' (expected integer 0-100)"
    exit 1
fi

# ── Helpers ───────────────────────────────────────────────────────
header() {
    echo ""
    echo -e "${BOLD}  Preflight Check - strands-php-client${RESET}"
    echo -e "  ${DIM}$(date '+%Y-%m-%d %H:%M:%S')${RESET}"
    echo -e "  ${DIM}$(printf '─%.0s' {1..44})${RESET}"
    echo ""
}

step() {
    local label="$1"
    TOTAL=$((TOTAL + 1))
    printf "  ${ARROW} %-40s" "$label"
}

pass() {
    local detail="${1:-}"
    PASSED=$((PASSED + 1))
    if [[ -n "$detail" ]]; then
        echo -e "${PASS}  ${DIM}${detail}${RESET}"
    else
        echo -e "${PASS}"
    fi
}

fail() {
    local label="$1"
    FAILED=$((FAILED + 1))
    FAILURES+=("$label")
    echo -e "${FAIL}"
}

skip() {
    local reason="${1:-skipped}"
    echo -e "${SKIP}  ${DIM}${reason}${RESET}"
}

divider() {
    echo -e "  ${DIM}$(printf '─%.0s' {1..44})${RESET}"
}

elapsed_since() {
    local start=$1
    local end=$(now_ns)
    local ms=$(( (end - start) / 1000000 ))
    if [[ $ms -lt 1000 ]]; then
        echo "${ms}ms"
    else
        local secs=$((ms / 1000))
        local frac=$((ms % 1000 / 100))
        echo "${secs}.${frac}s"
    fi
}

summary() {
    local end_time=$(now_ns)
    local total_ms=$(( (end_time - START_TIME) / 1000000 ))
    local total_secs=$((total_ms / 1000))
    local total_frac=$((total_ms % 1000 / 100))

    echo ""
    divider

    if [[ $FAILED -eq 0 ]]; then
        echo ""
        echo -e "  ${GREEN}${BOLD}All ${PASSED}/${TOTAL} checks passed${RESET}  ${DIM}(${total_secs}.${total_frac}s)${RESET}"
        echo ""
    else
        echo ""
        echo -e "  ${RED}${BOLD}${FAILED}/${TOTAL} checks failed${RESET}  ${DIM}(${total_secs}.${total_frac}s)${RESET}"
        echo ""
        for f in "${FAILURES[@]}"; do
            echo -e "    ${FAIL}  ${f}"
        done
        echo ""
        exit 1
    fi
}

# ── Checks ────────────────────────────────────────────────────────
header

# 1. Composer validate
step "Composer validate"
t=$(now_ns)
if composer validate --strict 2>&1 | grep -q "is valid"; then
    pass "$(elapsed_since $t)"
else
    fail "Composer validate"
fi

# 2. Security audit
step "Security audit"
t=$(now_ns)
audit_output=$(composer audit 2>&1)
audit_exit=$?
if [[ $audit_exit -eq 0 ]]; then
    pass "$(elapsed_since $t)"
else
    vuln_count=$(echo "$audit_output" | grep -c "Advisory" || true)
    fail "Security audit (${vuln_count} advisories)"
    echo "$audit_output" | head -20 | while read -r line; do
        echo -e "    ${DIM}${line}${RESET}"
    done
fi

# 3. Code style (PHP-CS-Fixer)
step "Code style (PHP-CS-Fixer)"
t=$(now_ns)
if [[ -x vendor/bin/php-cs-fixer ]]; then
    cs_output=$(vendor/bin/php-cs-fixer fix --dry-run --diff --sequential 2>&1)
    cs_exit=$?
    if [[ $cs_exit -eq 0 ]]; then
        pass "$(elapsed_since $t)"
    else
        fix_count=$(echo "$cs_output" | grep -c "^   [0-9]*)" || true)
        fail "Code style (${fix_count} files need fixing - run composer cs:fix)"
    fi
else
    skip "php-cs-fixer not installed"
fi

# 4. Cyclomatic complexity
step "Cyclomatic complexity (max 20)"
t=$(now_ns)
complexity_script="$(dirname "$0")/check-cyclomatic-complexity.php"
if [[ -f "$complexity_script" ]]; then
    complexity_output=$(php "$complexity_script" --path=src --max=20 2>&1)
    complexity_exit=$?
    if [[ $complexity_exit -eq 0 ]]; then
        pass "$(elapsed_since $t)"
    else
        violation_count=$(echo "$complexity_output" | grep -c "^ - " || true)
        fail "Cyclomatic complexity (${violation_count} violations)"
        echo "$complexity_output" | head -20 | while read -r line; do
            echo -e "    ${DIM}${line}${RESET}"
        done
    fi
else
    skip "scripts/check-cyclomatic-complexity.php not found"
fi

# 5. Mess detector (PHPMD)
step "Mess detector (PHPMD)"
t=$(now_ns)
if [[ -x vendor/bin/phpmd ]]; then
    if [[ -f phpmd.xml ]]; then
        phpmd_output=$(vendor/bin/phpmd src text phpmd.xml 2>&1)
    else
        phpmd_output=$(vendor/bin/phpmd src text codesize,design,unusedcode 2>&1)
    fi
    phpmd_exit=$?
    if [[ $phpmd_exit -eq 0 ]]; then
        pass "$(elapsed_since $t)"
    else
        violation_count=$(echo "$phpmd_output" | grep -c "." || true)
        fail "Mess detector (${violation_count} violations)"
        echo "$phpmd_output" | head -10 | while read -r line; do
            echo -e "    ${DIM}${line}${RESET}"
        done
    fi
else
    skip "phpmd not installed"
fi

# 6. PHPStan
step "Static analysis (PHPStan L10)"
t=$(now_ns)
if [[ -x vendor/bin/phpstan ]]; then
    if [[ -f phpstan.neon || -f phpstan.neon.dist ]]; then
        stan_output=$(vendor/bin/phpstan analyse --no-progress --error-format=raw 2>&1)
    else
        stan_output=$(vendor/bin/phpstan analyse src --no-progress --error-format=raw 2>&1)
    fi
    stan_exit=$?

    if [[ $stan_exit -ne 0 ]] && echo "$stan_output" | grep -q 'Failed to listen on "tcp://127.0.0.1:0"'; then
        # Fallback for constrained environments where PHPStan worker sockets cannot bind.
        if [[ -f phpstan.neon || -f phpstan.neon.dist ]]; then
            stan_output=$(vendor/bin/phpstan analyse --no-progress --error-format=raw --debug 2>&1)
        else
            stan_output=$(vendor/bin/phpstan analyse src --no-progress --error-format=raw --debug 2>&1)
        fi
        stan_exit=$?
    fi

    if [[ $stan_exit -eq 0 ]]; then
        pass "$(elapsed_since $t)"
    else
        err_count=$(echo "$stan_output" | grep -cE "^/" || true)
        fail "Static analysis (${err_count} errors)"
        echo "$stan_output" | grep -E "^/" | head -10 | while read -r line; do
            echo -e "    ${DIM}${line}${RESET}"
        done
    fi
else
    skip "phpstan not installed"
fi

# 7. PHPUnit
step "Tests (PHPUnit)"
t=$(now_ns)
if [[ -x vendor/bin/phpunit ]]; then
    test_output=$(vendor/bin/phpunit 2>&1)
    test_exit=$?
    if [[ $test_exit -eq 0 ]]; then
        test_summary=$(echo "$test_output" | grep -oE '[0-9]+ tests, [0-9]+ assertions' || echo "")
        pass "${test_summary:+$test_summary }$(elapsed_since $t)"
    else
        fail_count=$(echo "$test_output" | grep -oE '[0-9]+ failure' | grep -oE '[0-9]+' || echo "?")
        fail "Tests (${fail_count} failures)"
        echo "$test_output" | grep -A2 "^[0-9]*)" | head -15 | while read -r line; do
            echo -e "    ${DIM}${line}${RESET}"
        done
    fi
else
    skip "phpunit not installed"
fi

# 8. Coverage
step "Coverage (PHPUnit)"
t=$(now_ns)
if ! php -m 2>/dev/null | grep -qi "xdebug\|pcov"; then
    skip "no coverage driver — install one with: scripts/setup-initial.sh"
elif [[ ! -x vendor/bin/phpunit ]]; then
    skip "phpunit not installed"
else
    coverage_output=$(XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-clover=coverage.xml 2>&1)
    coverage_exit=$?

    if [[ $coverage_exit -ne 0 ]]; then
        fail "Coverage run failed"
        echo "$coverage_output" | tail -20 | while read -r line; do
            echo -e "    ${DIM}${line}${RESET}"
        done
    else
        coverage_stats=$(php -r '
            $xml = @simplexml_load_file("coverage.xml");
            if ($xml === false || !isset($xml->project->metrics)) {
                exit(1);
            }
            $metrics = $xml->project->metrics;
            $statements = (float) ($metrics["statements"] ?? 0);
            $covered = (float) ($metrics["coveredstatements"] ?? 0);
            if ($statements <= 0.0) {
                echo "0.00|0|0";
                exit(0);
            }
            echo number_format(($covered / $statements) * 100, 2, ".", "") . "|" . (int) $covered . "|" . (int) $statements;
        ' 2>/dev/null)
        parse_exit=$?
        IFS='|' read -r coverage_pct covered_lines total_lines <<< "$coverage_stats"

        if [[ $parse_exit -ne 0 || -z "$coverage_pct" ]]; then
            fail "Coverage parse failed (coverage.xml)"
            echo "$coverage_output" | tail -10 | while read -r line; do
                echo -e "    ${DIM}${line}${RESET}"
            done
        elif awk "BEGIN {exit !($coverage_pct >= $MIN_COVERAGE)}"; then
            pass "${coverage_pct}% line coverage (${covered_lines}/${total_lines}, min ${MIN_COVERAGE}%) $(elapsed_since $t)"
        else
            fail "Coverage ${coverage_pct}% < ${MIN_COVERAGE}% (${covered_lines}/${total_lines} lines)"
            echo -e "    ${DIM}coverage.xml analyzed successfully; threshold not met${RESET}"
            echo -e "    ${DIM}Tip: run 'composer test:coverage' and inspect lowest-covered classes${RESET}"
        fi
    fi
fi

# 9. Mutation testing (optional)
if [[ "$RUN_MUTATE" == true ]]; then
    step "Mutation testing (Infection)"
    t=$(now_ns)
    if [[ ! -x vendor/bin/infection ]]; then
        fail "Mutation testing (infection not installed)"
    elif ! php -m 2>/dev/null | grep -qi "xdebug\|pcov"; then
        fail "Mutation testing (no coverage driver - install xdebug or pcov)"
    else
        mutate_output=$(XDEBUG_MODE=coverage vendor/bin/infection --threads=4 --show-mutations=0 2>&1)
        mutate_exit=$?
        if [[ $mutate_exit -eq 0 ]]; then
            msi=$(echo "$mutate_output" | grep -oE 'Covered Code MSI: [0-9]+%' | grep -oE '[0-9]+%' || echo "")
            killed=$(echo "$mutate_output" | grep -oE '[0-9]+ mutants were killed' | grep -oE '[0-9]+' || echo "")
            total_m=$(echo "$mutate_output" | grep -oE '[0-9]+ mutations were generated' | grep -oE '[0-9]+' || echo "")
            pass "${killed:+${killed}/${total_m} killed }${msi:+(${msi} MSI) }$(elapsed_since $t)"
        else
            msi=$(echo "$mutate_output" | grep -oE 'MSI:[[:space:]]*[0-9]+%' | head -1 | grep -oE '[0-9]+%' || echo "")
            covered_msi=$(echo "$mutate_output" | grep -oE 'Covered Code MSI:[[:space:]]*[0-9]+%' | head -1 | grep -oE '[0-9]+%' || echo "")
            killed=$(echo "$mutate_output" | grep -oE '[0-9]+ mutants were killed' | head -1 | grep -oE '[0-9]+' || echo "")
            total_m=$(echo "$mutate_output" | grep -oE '[0-9]+ mutations were generated' | head -1 | grep -oE '[0-9]+' || echo "")
            not_covered=$(echo "$mutate_output" | grep -oE '[0-9]+ (mutants )?were not covered by tests' | head -1 | grep -oE '[0-9]+' || echo "")
            not_detected=$(echo "$mutate_output" | grep -oE '[0-9]+ .*mutants were not detected' | head -1 | grep -oE '[0-9]+' || echo "")

            detail_parts=()
            [[ -n "$killed" && -n "$total_m" ]] && detail_parts+=("${killed}/${total_m} killed")
            [[ -n "$covered_msi" ]] && detail_parts+=("Covered MSI ${covered_msi}")
            [[ -n "$msi" && "$msi" != "$covered_msi" ]] && detail_parts+=("MSI ${msi}")
            [[ -n "$not_covered" && "$not_covered" != "0" ]] && detail_parts+=("${not_covered} not covered")
            [[ -n "$not_detected" && "$not_detected" != "0" ]] && detail_parts+=("${not_detected} escaped")

            detail_text=$(IFS=', '; echo "${detail_parts[*]}")
            if [[ -n "$detail_text" ]]; then
                fail "Mutation testing (${detail_text})"
            else
                fail "Mutation testing"
            fi

            mutate_context=$(echo "$mutate_output" | grep -E 'MSI|mutations were generated|mutants? were killed|not covered|not detected|Escaped|Fatal|Exception|minimum|Min' | head -12)
            if [[ -n "$mutate_context" ]]; then
                echo "$mutate_context" | while read -r line; do
                    echo -e "    ${DIM}${line}${RESET}"
                done
            else
                echo "$mutate_output" | tail -20 | while read -r line; do
                    echo -e "    ${DIM}${line}${RESET}"
                done
            fi
        fi
    fi
else
    TOTAL=$((TOTAL + 1))
    printf "  ${ARROW} %-40s" "Mutation testing (Infection)"
    skip "use --mutate to enable"
fi

summary
