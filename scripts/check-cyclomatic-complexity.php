<?php

declare(strict_types=1);

/**
 * Lightweight cyclomatic complexity checker.
 *
 * Usage:
 *   php scripts/check-cyclomatic-complexity.php --path=src --max=20
 */

/** @var array{path?: string, max?: string, help?: false|string} $options */
$options = getopt('', ['path::', 'max::', 'help::']);

if (array_key_exists('help', $options)) {
    echo "Usage: php scripts/check-cyclomatic-complexity.php [--path=src] [--max=20]\n";
    exit(0);
}

$path = $options['path'] ?? 'src';
$maxComplexity = isset($options['max']) ? (int) $options['max'] : 20;

if (!is_string($path) || $path === '') {
    fwrite(STDERR, "Invalid --path value.\n");
    exit(2);
}

if ($maxComplexity < 1) {
    fwrite(STDERR, "Invalid --max value: expected integer >= 1.\n");
    exit(2);
}

if (!is_dir($path)) {
    fwrite(STDERR, "Path not found or not a directory: {$path}\n");
    exit(2);
}

/**
 * @return list<string>
 */
function findPhpFiles(string $path): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
            $files[] = $fileInfo->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 */
function nextSignificantTokenIndex(array $tokens, int $start): ?int
{
    $count = count($tokens);
    for ($i = $start; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_string($token)) {
            return $i;
        }
        if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $i;
        }
    }

    return null;
}

/**
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 */
function previousSignificantTokenIndex(array $tokens, int $start): ?int
{
    for ($i = $start; $i >= 0; $i--) {
        $token = $tokens[$i];
        if (is_string($token)) {
            return $i;
        }
        if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $i;
        }
    }

    return null;
}

/**
 * @return list<array{name:string, file:string, line:int, complexity:int}>
 */
function analyzeFile(string $filePath): array
{
    $code = file_get_contents($filePath);
    if ($code === false) {
        return [];
    }

    $tokens = token_get_all($code);
    $tokenCount = count($tokens);

    $projectRoot = getcwd();
    $relativePath = $filePath;
    if ($projectRoot !== false && str_starts_with($filePath, $projectRoot . DIRECTORY_SEPARATOR)) {
        $relativePath = substr($filePath, strlen($projectRoot) + 1);
    }

    $namespace = '';
    $braceDepth = 0;
    $stringInterpolationDepth = 0;

    /** @var list<array{name:string, depth:int}> $classStack */
    $classStack = [];
    $pendingClassName = null;

    /** @var array{name:string, line:int, file:string, class:string|null, complexity:int, depth:int|null}|null $pendingFunction */
    $pendingFunction = null;

    /** @var list<array{name:string, line:int, file:string, class:string|null, complexity:int, depth:int}> $activeFunctions */
    $activeFunctions = [];

    /** @var list<array{name:string, file:string, line:int, complexity:int}> $completed */
    $completed = [];

    $decisionTokens = [
        T_IF,
        T_ELSEIF,
        T_FOR,
        T_FOREACH,
        T_WHILE,
        T_DO,
        T_CASE,
        T_CATCH,
        T_BOOLEAN_AND,
        T_BOOLEAN_OR,
        T_LOGICAL_AND,
        T_LOGICAL_OR,
        T_LOGICAL_XOR,
        T_COALESCE,
    ];

    if (defined('T_MATCH')) {
        $decisionTokens[] = T_MATCH;
    }

    for ($i = 0; $i < $tokenCount; $i++) {
        $token = $tokens[$i];

        if ($activeFunctions !== []) {
            $activeIndex = array_key_last($activeFunctions);
            if ($activeIndex !== null) {
                if (is_array($token) && in_array($token[0], $decisionTokens, true)) {
                    $activeFunctions[$activeIndex]['complexity']++;
                } elseif ($token === '?') {
                    // Ternary operator decision point.
                    $activeFunctions[$activeIndex]['complexity']++;
                }
            }
        }

        if (is_array($token)) {
            $tokenId = $token[0];

            if ($tokenId === T_NAMESPACE) {
                $namespaceText = '';
                for ($j = $i + 1; $j < $tokenCount; $j++) {
                    $part = $tokens[$j];
                    if (is_string($part)) {
                        if ($part === ';' || $part === '{') {
                            break;
                        }
                        continue;
                    }
                    if (in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $namespaceText .= $part[1];
                }
                $namespace = trim($namespaceText, " \t\n\r\0\x0B\\");
                continue;
            }

            if ($tokenId === T_CURLY_OPEN || $tokenId === T_DOLLAR_OPEN_CURLY_BRACES) {
                $stringInterpolationDepth++;
                continue;
            }

            $isClassLike = $tokenId === T_CLASS
                || $tokenId === T_INTERFACE
                || $tokenId === T_TRAIT
                || (defined('T_ENUM') && $tokenId === T_ENUM);

            if ($isClassLike) {
                $previousIndex = previousSignificantTokenIndex($tokens, $i - 1);
                $isAnonymousClass = false;
                $isClassConstant = false;
                if ($previousIndex !== null) {
                    $previousToken = $tokens[$previousIndex];
                    if (is_array($previousToken) && $previousToken[0] === T_NEW) {
                        $isAnonymousClass = true;
                    } elseif (is_array($previousToken) && $previousToken[0] === T_DOUBLE_COLON) {
                        $isClassConstant = true;
                    }
                }

                if (!$isAnonymousClass && !$isClassConstant) {
                    $nextIndex = nextSignificantTokenIndex($tokens, $i + 1);
                    if ($nextIndex !== null) {
                        $nextToken = $tokens[$nextIndex];
                        if (is_array($nextToken) && $nextToken[0] === T_STRING) {
                            $className = $nextToken[1];
                            $pendingClassName = $namespace !== ''
                                ? $namespace . '\\' . $className
                                : $className;
                        }
                    }
                }

                continue;
            }

            if ($tokenId === T_FUNCTION) {
                $nextIndex = nextSignificantTokenIndex($tokens, $i + 1);
                while ($nextIndex !== null && isset($tokens[$nextIndex]) && $tokens[$nextIndex] === '&') {
                    $nextIndex = nextSignificantTokenIndex($tokens, $nextIndex + 1);
                }

                if ($nextIndex !== null) {
                    $nextToken = $tokens[$nextIndex];
                    if (is_array($nextToken) && $nextToken[0] === T_STRING) {
                        $currentClass = null;
                        if ($classStack !== []) {
                            $topClass = $classStack[array_key_last($classStack)];
                            $currentClass = $topClass['name'];
                        }

                        $pendingFunction = [
                            'name' => $nextToken[1],
                            'line' => $nextToken[2],
                            'file' => $relativePath,
                            'class' => $currentClass,
                            'complexity' => 1,
                            'depth' => null,
                        ];
                    }
                }

                continue;
            }
        } else {
            if ($token === '}' && $stringInterpolationDepth > 0) {
                $stringInterpolationDepth--;
                continue;
            }

            if ($token === ';' && $pendingFunction !== null) {
                // Interface/abstract method with no body.
                $pendingFunction = null;
                continue;
            }

            if ($token === '{') {
                $braceDepth++;

                if ($pendingClassName !== null) {
                    $classStack[] = [
                        'name' => $pendingClassName,
                        'depth' => $braceDepth,
                    ];
                    $pendingClassName = null;
                    continue;
                }

                if ($pendingFunction !== null) {
                    $pendingFunction['depth'] = $braceDepth;
                    /** @var array{name:string, line:int, file:string, class:string|null, complexity:int, depth:int} $started */
                    $started = $pendingFunction;
                    $activeFunctions[] = $started;
                    $pendingFunction = null;
                    continue;
                }

                continue;
            }

            if ($token === '}') {
                if ($activeFunctions !== []) {
                    $activeIndex = array_key_last($activeFunctions);
                    if ($activeIndex !== null && $activeFunctions[$activeIndex]['depth'] === $braceDepth) {
                        $finished = array_pop($activeFunctions);
                        if ($finished !== null) {
                            $name = $finished['class'] !== null
                                ? $finished['class'] . '::' . $finished['name']
                                : $finished['name'];
                            $completed[] = [
                                'name' => $name,
                                'file' => $finished['file'],
                                'line' => $finished['line'],
                                'complexity' => $finished['complexity'],
                            ];
                        }
                    }
                }

                if ($classStack !== []) {
                    $classIndex = array_key_last($classStack);
                    if ($classIndex !== null && $classStack[$classIndex]['depth'] === $braceDepth) {
                        array_pop($classStack);
                    }
                }

                $braceDepth--;
            }
        }
    }

    return $completed;
}

$allMethods = [];
foreach (findPhpFiles($path) as $filePath) {
    $allMethods = array_merge($allMethods, analyzeFile($filePath));
}

if ($allMethods === []) {
    echo "No functions/methods found under {$path}.\n";
    exit(0);
}

usort($allMethods, static function (array $left, array $right): int {
    if ($left['complexity'] === $right['complexity']) {
        return strcmp($left['name'], $right['name']);
    }

    return $right['complexity'] <=> $left['complexity'];
});

$violations = array_values(array_filter(
    $allMethods,
    static fn(array $entry): bool => $entry['complexity'] > $maxComplexity,
));

if ($violations === []) {
    $worst = $allMethods[0];
    echo sprintf(
        "Cyclomatic complexity check passed (%d methods analyzed, threshold %d, max observed %d at %s).\n",
        count($allMethods),
        $maxComplexity,
        $worst['complexity'],
        $worst['name'],
    );
    exit(0);
}

echo sprintf(
    "Cyclomatic complexity violations (threshold %d):\n",
    $maxComplexity,
);

foreach ($violations as $entry) {
    echo sprintf(
        " - %s: %d (%s:%d)\n",
        $entry['name'],
        $entry['complexity'],
        $entry['file'],
        $entry['line'],
    );
}

exit(1);
