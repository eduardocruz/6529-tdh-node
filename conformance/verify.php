<?php

declare(strict_types=1);

/**
 * Conformance verifier for 6529 TDH providers.
 *
 * Answers one question, for any provider that serves the /oracle/* API: does the
 * Merkle root it publishes actually describe the data it serves?
 *
 * The root is what makes TDH falsifiable. Two implementations conform when they
 * produce the same root at the same block — one hash, no judgement calls. But a
 * provider publishing a root it did not derive from its own numbers proves
 * nothing, so this recomputes the root from the entries and compares.
 *
 * The recipe below is not documented anywhere upstream. It was recovered from
 * 6529's production source (src/tdhLoop/tdh_merkle.ts) and confirmed against the
 * live API on 2026-08-02, block 25663469, root 0xdc652a69…ea2f9.
 *
 * Requires PHP 8.5+. No dependencies: hashing, JSON and HTTP are all core. No
 * composer, no vendor — this must stay runnable by anyone with php on their path.
 *
 * Usage:
 *   php conformance/verify.php                    # against api.6529.io
 *   php conformance/verify.php --endpoint=URL     # against any provider
 *   php conformance/verify.php --json             # machine-readable
 *   php conformance/verify.php --save=snap.json.gz
 *
 * Exit status:
 *   0  the published root reproduces from the published entries
 *   1  it does not — the provider and its own data disagree
 *   2  the data could not be fetched or parsed
 */

if (PHP_VERSION_ID < 80500) {
    fwrite(STDERR, 'this project targets PHP 8.5+; found ' . PHP_VERSION . "\n");
    exit(2);
}

const DEFAULT_ENDPOINT = 'https://api.6529.io';
const ENTRIES_PATH = '/oracle/tdh/above/0/entries';
const UA = '6529-tdh-node conformance verifier (+https://github.com/eduardocruz/6529-tdh-node)';

// ── the tree ────────────────────────────────────────────────────────────────
// Kept deliberately small and dependency-free. This is the reference the rest of
// the project is judged against, so it should be readable in one sitting.

/** Leaf: sha256 over "<key>:<value>", hex. The value is the boosted TDH. */
function tdh_leaf(string $consolidationKey, int $tdh): string
{
    return hash('sha256', $consolidationKey . ':' . $tdh);
}

/**
 * Root over (consolidation_key, tdh) pairs, following production exactly.
 *
 * Three details that are easy to get wrong and impossible to guess:
 *
 * 1. Rows with tdh == 0 are excluded. The /above/0 route filters
 *    `boosted_tdh >= 0`, but the tree is built over `> 0` — so the endpoint
 *    serves rows the tree never saw.
 * 2. The sort is `tdh` DESC, then `consolidation_key` ASC. The secondary key is
 *    not decorative: thousands of rows tie on tdh, and the API's own response
 *    order (`ORDER BY boosted_tdh DESC` alone) does NOT reproduce the root.
 * 3. On an odd level the last node is PROMOTED unchanged, not duplicated.
 *    (Production's own comment says "duplicate"; its code promotes.)
 *
 * Parent nodes hash the concatenation of the two children as hex STRINGS, not
 * as bytes.
 *
 * @param  array<int,array{consolidation_key:string,tdh:int}>  $entries
 */
function tdh_merkle_root(array $entries): string
{
    $rows = [];
    foreach ($entries as $e) {
        if ((int) $e['tdh'] > 0) {
            $rows[] = [(string) $e['consolidation_key'], (int) $e['tdh']];
        }
    }

    if ($rows === []) {
        throw new RuntimeException('no entries with tdh > 0 — nothing to hash');
    }

    usort($rows, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));

    $level = array_map(static fn (array $r): string => tdh_leaf($r[0], $r[1]), $rows);

    while (count($level) > 1) {
        $next = [];
        for ($i = 0, $n = count($level); $i < $n; $i += 2) {
            $next[] = isset($level[$i + 1])
                ? hash('sha256', $level[$i] . $level[$i + 1])
                : $level[$i];
        }
        $level = $next;
    }

    return '0x' . $level[0];
}

// ── fetching ────────────────────────────────────────────────────────────────

/**
 * GET JSON, with backoff. Providers are self-funded hobby boxes; be patient with
 * them and explicit when giving up.
 *
 * @return array<string,mixed>
 */
function tdh_fetch(string $url, int $tries = 3): array
{
    $delay = 2;

    for ($attempt = 1; $attempt <= $tries; $attempt++) {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: " . UA . "\r\nAccept: application/json\r\n",
            'timeout' => 120,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($body !== false && $status >= 200 && $status < 300) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $problem = 'response was not JSON: ' . substr($body, 0, 400);
        } else {
            $problem = $body === false
                ? 'connection failed'
                : "HTTP {$status}: " . substr($body, 0, 400);
        }

        if ($attempt === $tries) {
            throw new RuntimeException("could not read {$url}\n{$problem}");
        }

        sleep($delay);
        $delay *= 2;
    }

    throw new RuntimeException('unreachable');
}

/**
 * Compare the published root against one recomputed from the payload.
 *
 * @param  array<string,mixed>  $payload
 * @return array<string,mixed>
 */
function tdh_check(array $payload): array
{
    foreach (['block', 'merkle_root', 'entries'] as $field) {
        if (! array_key_exists($field, $payload)) {
            throw new RuntimeException("response is missing '{$field}' — not a TDH oracle payload");
        }
    }

    $entries = $payload['entries'];
    $computed = tdh_merkle_root($entries);

    $inTree = 0;
    foreach ($entries as $e) {
        if ((int) $e['tdh'] > 0) {
            $inTree++;
        }
    }

    return [
        'block' => $payload['block'],
        'entries_served' => count($entries),
        'entries_in_tree' => $inTree,
        'published_root' => $payload['merkle_root'],
        'computed_root' => $computed,
        'conforms' => $computed === $payload['merkle_root'],
    ];
}

// ── CLI ─────────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli' || (isset($argv[0]) && realpath($argv[0]) !== __FILE__)) {
    return; // included as a library (by the vector runner) — define only
}

$opts = getopt('', ['endpoint::', 'json', 'save::', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Verify a 6529 TDH provider's Merkle root against its own data.\n\n"
        . "  --endpoint=URL   provider base URL (default: " . DEFAULT_ENDPOINT . ")\n"
        . "  --json           machine-readable output\n"
        . "  --save=PATH      write the raw payload here (.gz is compressed)\n");
    exit(0);
}

$endpoint = rtrim((string) ($opts['endpoint'] ?? DEFAULT_ENDPOINT), '/');
$url = $endpoint . ENTRIES_PATH;

try {
    $payload = tdh_fetch($url);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

if (isset($opts['save']) && $opts['save'] !== false) {
    $path = (string) $opts['save'];
    $json = json_encode($payload);
    $written = str_ends_with($path, '.gz')
        ? file_put_contents($path, gzencode($json, 9))
        : file_put_contents($path, $json);
    if ($written === false) {
        fwrite(STDERR, "could not write {$path}\n");
        exit(2);
    }
}

try {
    $result = tdh_check($payload);
} catch (RuntimeException $e) {
    fwrite(STDERR, "malformed payload from {$url}: " . $e->getMessage() . "\n");
    exit(2);
}

if (isset($opts['json'])) {
    echo json_encode(['endpoint' => $endpoint] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $excluded = $result['entries_served'] - $result['entries_in_tree'];
    printf("endpoint        %s\n", $endpoint);
    printf("block           %s\n", $result['block']);
    printf("entries served  %d\n", $result['entries_served']);
    printf("entries in tree %d  (%d excluded at tdh == 0)\n", $result['entries_in_tree'], $excluded);
    printf("published root  %s\n", $result['published_root']);
    printf("computed root   %s\n", $result['computed_root']);
    echo "\n", $result['conforms']
        ? "CONFORMS — the published root reproduces from the published entries\n"
        : "DIVERGES — this provider's root does not describe the data it serves\n";
}

exit($result['conforms'] ? 0 : 1);
