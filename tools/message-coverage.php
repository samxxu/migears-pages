<?php

declare(strict_types=1);

/**
 * Advisory: the error sites of this package that no test asserts.
 *
 * Line coverage says a line ran, not which condition inside it ran: "must be a
 * valid variable name" fires for a non-string and for a bad identifier, on one
 * line. A condition that carries wording of its own is visible from that
 * wording, so this script pairs every error site with the texts the tests
 * assert and reports the sites nothing asserts.
 *
 * How a site is counted as asserted: the texts are the fragments the tests hand
 * to `expectError()`, `assertStringContainsString()`, `assertStringStartsWith()`
 * and `expectExceptionMessage()`. A fragment counts when it aligns with a run of
 * the message's words, an interpolation standing for whatever value the test
 * used — so `the value "maybe" for required is not a boolean` matches a message
 * built from `the value "{$raw}" for required is not a boolean`. Words are
 * compared without their surrounding punctuation, which is what lets
 * `no page content` match `no page content; provide …`.
 *
 * Three limits are worth knowing before reading the output. A fragment that
 * lives in a data provider, or in a heredoc, is not seen and reads as a lead. A
 * condition that only alters part of an already-asserted message cannot be seen
 * at all: the empty document leaving out the ": <parser message>" half of "XML
 * syntax error" looks exactly like a case that asserts the whole message. And a
 * fragment can align by coincidence, hiding a real lead.
 *
 * Every lead prints how many words the closest fragment shares, so the
 * borderline case — a case asserting "root element" for "XML root element must
 * be <page>" — can be judged at a glance. MIN_RUN is where that line is drawn.
 *
 * Scans src/ recursively, and the package's own tests/*.php.
 *
 * Usage:
 *   composer message-coverage
 *   php tools/message-coverage.php ../migears-yaml-pages
 */

/** A word the message does not spell: an interpolated value the test supplied. */
const GAP = "\x00";

/**
 * How many consecutive words a fragment and a message have to share before the
 * site counts as asserted. Lower finds more, at the price of calling a site
 * asserted because a common word ("required", "must") happens to be in both.
 */
const MIN_RUN = 3;

$pkg = rtrim($argv[1] ?? dirname(__DIR__), '/');

if (! is_dir($pkg . '/src')) {
    fwrite(STDERR, "no src/ in {$pkg}; pass the directory of a pages package\n");
    exit(1);
}

$asserted = needles($pkg . '/tests');

$sites = 0;
$matched = 0;
$leads = [];

foreach (phpFiles($pkg . '/src') as $file) {
    $lines = explode("\n", (string) file_get_contents($file));
    foreach ($lines as $i => $line) {
        if (! str_contains($line, 'error(') && ! str_contains($line, 'Exception(')) {
            continue;
        }
        // The statement plus the lines it may continue on, so a concatenated
        // message is read whole while the opening line is the one reported.
        $message = messageTokens(implode(' ', array_slice($lines, $i, 4)));
        if ($message === []) {
            continue;
        }

        $sites++;

        $closest = 0;
        foreach ($asserted as $words) {
            if (assertedBy($message, $words)) {
                $matched++;
                continue 2;
            }
            $closest = max($closest, longestSharedRun($message, $words));
        }

        $leads[] = [basename($file) . ':' . ($i + 1), describe($message), $closest];
    }
}

$count = count($leads);
echo basename($pkg) . ": {$sites} error sites, {$matched} with a matching assertion, {$count} lead" . ($count === 1 ? '' : 's') . "\n";
foreach ($leads as [$at, $text, $closest]) {
    $note = $closest === 0
        ? 'nothing shared'
        : "closest match shares {$closest} word" . ($closest === 1 ? '' : 's');
    echo "  {$at}  {$text}  ({$note})\n";
}

if ($leads !== []) {
    echo "\nEach lead is a pointer, not a verdict: open it and check the case by hand.\n";
}

/**
 * Every PHP file under a directory, sorted so the report is stable.
 *
 * @return list<string>
 */
function phpFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * The texts the tests assert, as words: the arguments of the assertion calls that
 * carry one. A negative assertion is not one of them — it says the text is *not*
 * in the message.
 *
 * @return list<list<string>>
 */
function needles(string $testsDir): array
{
    $needles = [];
    foreach (phpFiles($testsDir) as $file) {
        $code = (string) file_get_contents($file);
        foreach (['expectError(', 'assertStringContainsString(', 'assertStringStartsWith(', 'expectExceptionMessage('] as $call) {
            $offset = 0;
            while (($at = strpos($code, $call, $offset)) !== false) {
                $offset = $at + strlen($call);
                foreach (stringLiterals(callArguments($code, $at + strlen($call) - 1)) as $literal) {
                    $words = words($literal);
                    if ($words !== []) {
                        $needles[] = $words;
                    }
                }
            }
        }
    }

    return array_values(array_unique($needles, SORT_REGULAR));
}

/**
 * The text of a call's argument list, stopping at the parenthesis that closes it.
 * Quotes are skipped so a parenthesis inside a text does not end the list.
 */
function callArguments(string $code, int $open): string
{
    $depth = 0;
    $quote = null;
    $length = strlen($code);

    for ($i = $open; $i < $length; $i++) {
        $char = $code[$i];
        if ($quote !== null) {
            if ($char === '\\') {
                $i++;
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($char === '\'' || $char === '"') {
            $quote = $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            continue;
        }
        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($code, $open + 1, $i - $open - 1);
            }
        }
    }

    return '';
}

/**
 * @return list<string>
 */
function stringLiterals(string $code): array
{
    preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/', $code, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $match) {
        $literal = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        $out[] = str_replace(['\\n', '\\"', "\\'"], [' ', '"', "'"], $literal);
    }

    return $out;
}

/**
 * A message as words, with every interpolated value standing in as a gap: what a
 * test sees there is a value this side cannot know. Both spellings of an
 * interpolation are handled — the one the string carries inside it (`"{$path}: …`)
 * and the one between two strings (`'the value "' . $raw . '" is …'`).
 *
 * @return list<string>
 */
function messageTokens(string $statement): array
{
    $tokens = [];
    foreach (stringLiterals($statement) as $literal) {
        $literal = (string) preg_replace('/\{[^{}]*\}/', ' ' . GAP . ' ', $literal);
        foreach (words($literal) as $word) {
            $tokens[] = $word;
        }
        $tokens[] = GAP;
    }

    // The last string is followed by no interpolation, so that gap is not one.
    array_pop($tokens);

    return $tokens;
}

/**
 * @return list<string>
 */
function words(string $text): array
{
    $out = [];
    foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
        if ($word === GAP) {
            $out[] = GAP;
        } elseif ($word !== '') {
            $out[] = $word;
        }
    }

    return $out;
}

/**
 * True when a run of the fragment's words long enough to mean something aligns
 * with the message. A test may wrap the fragment it asserts — the child-process
 * case asserts "CompileException: ext-yaml is not loaded" for a message that
 * starts at "ext-yaml" — so the run may start and end anywhere in the fragment.
 *
 * @param list<string> $message
 * @param list<string> $fragment
 */
function assertedBy(array $message, array $fragment): bool
{
    for ($start = 0; $start < count($fragment); $start++) {
        for ($length = MIN_RUN; $start + $length <= count($fragment); $length++) {
            if (aligns($message, array_slice($fragment, $start, $length))) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The most words a fragment shares with the message, however short the run is.
 * Printed next to a lead so the weak match — the one that fell short of MIN_RUN —
 * is visible without a search.
 *
 * @param list<string> $message
 * @param list<string> $fragment
 */
function longestSharedRun(array $message, array $fragment): int
{
    $longest = 0;
    for ($start = 0; $start < count($fragment); $start++) {
        for ($length = 1; $start + $length <= count($fragment); $length++) {
            if (! aligns($message, array_slice($fragment, $start, $length))) {
                break;      // a longer run from this start cannot align either
            }
            $longest = max($longest, $length);
        }
    }

    return $longest;
}

/**
 * True when the needle aligns with a run of the message's words, a gap standing
 * for any run of words the test supplied.
 *
 * @param list<string> $message
 * @param list<string> $needle
 */
function aligns(array $message, array $needle): bool
{
    for ($start = 0; $start < count($message); $start++) {
        if (alignsAt($message, $needle, $start, 0)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $message
 * @param list<string> $needle
 */
function alignsAt(array $message, array $needle, int $at, int $word): bool
{
    if ($word === count($needle)) {
        return true;
    }
    if ($at === count($message)) {
        return false;
    }
    if ($message[$at] === GAP) {
        for ($skip = $word; $skip <= count($needle); $skip++) {
            if (alignsAt($message, $needle, $at + 1, $skip)) {
                return true;
            }
        }

        return false;
    }

    return same($message[$at], $needle[$word]) && alignsAt($message, $needle, $at + 1, $word + 1);
}

/** Words are compared without the punctuation around them. */
function same(string $left, string $right): bool
{
    $trim = static fn (string $word): string => trim($word, '.,;:!?()[]"\'/');

    return $trim($left) === $trim($right);
}

/**
 * @param list<string> $tokens
 */
function describe(array $tokens): string
{
    $text = str_replace(GAP, '…', implode(' ', $tokens));

    return substr($text, 0, 72) . ' …';
}
