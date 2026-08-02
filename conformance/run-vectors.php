<?php

declare(strict_types=1);

/**
 * Runs the golden vectors against this repository's Merkle implementation.
 *
 * Offline and deterministic: no network, no clock, no environment. It must pass
 * even when api.6529.io is down, which is the point — a conformance suite that
 * only works while the thing it audits is healthy is not a conformance suite.
 *
 * Two kinds of vector, doing different jobs:
 *
 *   tree-shape.json    Small synthetic cases pinning the tree's shape — odd-level
 *                      promotion, tie-breaking, zero exclusion. Their expected
 *                      roots were computed by a separate implementation, and one
 *                      was confirmed by hand with shasum, so they are not this
 *                      code marking its own homework.
 *
 *   mainnet-*.json.gz  A frozen real snapshot. Its expected root is the one 6529's
 *                      production published for that block — the only expectation
 *                      here that no part of this project authored.
 *
 * Usage:  php conformance/run-vectors.php
 * Exit:   0 all pass · 1 any fail
 */

if (PHP_VERSION_ID < 80500) {
    fwrite(STDERR, 'this project targets PHP 8.5+; found ' . PHP_VERSION . "\n");
    exit(2);
}

require __DIR__ . '/verify.php';

$dir = __DIR__ . '/vectors';
$failures = 0;
$passes = 0;

/** Report one case and tally it. */
function vector_result(string $name, string $expected, string $actual, string $note = ''): void
{
    global $failures, $passes;

    if ($expected === $actual) {
        $passes++;
        printf("  ok    %s\n", $name);

        return;
    }

    $failures++;
    printf("  FAIL  %s\n", $name);
    printf("        expected %s\n", $expected);
    printf("        got      %s\n", $actual);
    if ($note !== '') {
        printf("        %s\n", $note);
    }
}

// ── tree shape ──────────────────────────────────────────────────────────────

$shapePath = $dir . '/tree-shape.json';
$shape = json_decode((string) file_get_contents($shapePath), true);

if (! is_array($shape) || ! isset($shape['cases'])) {
    fwrite(STDERR, "could not read {$shapePath}\n");
    exit(1);
}

echo "tree shape (synthetic)\n";
foreach ($shape['cases'] as $case) {
    try {
        $actual = tdh_merkle_root($case['entries']);
    } catch (Throwable $e) {
        $actual = 'threw: ' . $e->getMessage();
    }
    vector_result($case['name'], $case['expected_root'], $actual, $case['note'] ?? '');
}

// ── frozen mainnet snapshots ────────────────────────────────────────────────

echo "\nmainnet snapshots (expected roots published by 6529 production)\n";

$snapshots = glob($dir . '/mainnet-*.json.gz') ?: [];

if ($snapshots === []) {
    echo "  FAIL  no mainnet snapshot found — the end-to-end case is missing\n";
    $failures++;
}

foreach ($snapshots as $path) {
    $raw = gzdecode((string) file_get_contents($path));
    $payload = json_decode((string) $raw, true);

    if (! is_array($payload)) {
        vector_result(basename($path), '<valid json>', '<unreadable>');

        continue;
    }

    $result = tdh_check($payload);
    vector_result(
        sprintf('block %s — %d entries, %d in tree', $payload['block'], $result['entries_served'], $result['entries_in_tree']),
        $result['published_root'],
        $result['computed_root']
    );
}

printf("\n%d passed, %d failed\n", $passes, $failures);

exit($failures === 0 ? 0 : 1);
