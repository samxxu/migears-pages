<?php

declare(strict_types=1);

/**
 * Advisory: the error sites of this package that no test reproduces.
 *
 * Line coverage says a line ran, not which condition inside it ran:
 * "must be a valid variable name" fires for a non-string and for a bad
 * identifier, on one line. Messages are one per condition, so a site whose
 * wording appears nowhere in the tests is a condition nothing exercises.
 *
 * A site counts as covered when any three-word window of its message appears in
 * the tests. That is a heuristic, and it fails in both directions: a case that
 * asserts a short fragment ("root element" for "XML root element must be
 * <page>") or a fragment crossing an interpolation reads as a lead, and a
 * coincidental match can hide a real one. Every lead therefore has to be opened
 * and read, which is why this script is not part of the test run: a gate that
 * has to be judged by hand only teaches people to ignore it.
 *
 * Reads src/Compiler.php, where every package in this family keeps its
 * validation, and the package's own tests/*.php.
 *
 * Usage:
 *   composer message-coverage
 *   php tools/message-coverage.php ../migears-yaml-pages
 */

$pkg = rtrim($argv[1] ?? dirname(__DIR__), '/');

if (! is_file($pkg . '/src/Compiler.php')) {
    fwrite(STDERR, "no src/Compiler.php in {$pkg}; pass the directory of a pages package\n");
    exit(1);
}

$src = (string) file_get_contents($pkg . '/src/Compiler.php');

$tests = '';
foreach (glob($pkg . '/tests/*.php') ?: [] as $file) {
    $tests .= file_get_contents($file) . "\n";
}

$lines = explode("\n", $src);
$matched = 0;
$leads = [];

foreach ($lines as $i => $line) {
    if (! str_contains($line, 'error(') && ! str_contains($line, 'Exception(')) {
        continue;
    }
    // The statement plus the lines it may continue on, so a concatenated
    // message is read whole while the opening line is the one reported.
    $statement = implode(' ', array_slice($lines, $i, 4));

    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'/', $statement, $m, PREG_SET_ORDER);

    $literals = [];
    foreach ($m as $match) {
        $literal = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        $literal = str_replace(['\\n', '\\"', "\\'"], [' ', '"', "'"], $literal);
        // "{...}" interpolations are not wording a test can quote.
        $literal = preg_replace('/\{[^{}]*\}/', ' ', $literal);
        $words = preg_split('/\s+/', trim((string) $literal)) ?: [];
        if (count($words) >= 3) {
            $literals[] = $words;
        }
    }

    if ($literals === []) {
        continue;
    }

    $found = false;
    foreach ($literals as $words) {
        for ($w = 0; $w + 3 <= count($words); $w++) {
            $window = array_slice($words, $w, 3);
            // A test may quote the message without its punctuation: "no page
            // content" for "no page content; provide …".
            $plain = array_map(static fn (string $word): string => trim($word, '.,;:!?()[]"\'/'), $window);
            foreach ([implode(' ', $window), implode(' ', $plain)] as $candidate) {
                if (str_contains($tests, trim($candidate))) {
                    $found = true;
                    break 3;
                }
            }
        }
    }

    if ($found) {
        $matched++;
        continue;
    }

    $leads[] = [$i + 1, trim(implode(' ', $literals[0])) . ' …'];
}

$total = $matched + count($leads);
$count = count($leads);
echo basename($pkg) . ": {$total} error sites, {$matched} with a matching test, {$count} lead" . ($count === 1 ? '' : 's') . "\n";
foreach ($leads as [$at, $text]) {
    echo "  :{$at}  {$text}\n";
}

if ($leads !== []) {
    echo "\nEach lead is a pointer, not a verdict: open it and check the case by hand.\n";
}
