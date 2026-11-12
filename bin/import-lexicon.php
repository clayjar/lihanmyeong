<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

/**
 * Walk a flat JSON object in file order. json_decode keeps only the last copy
 * of a repeated key, which would hide conflicting Hanja spellings.
 *
 * @return list<array{0: string, 1: string}>
 */
function hanja_json_pairs(string $json): array
{
    if (str_starts_with($json, "\xEF\xBB\xBF")) {
        $json = substr($json, 3);
    }

    $length = strlen($json);
    $i = 0;
    $pairs = [];

    $skipWhitespace = function () use (&$i, $length, $json): void {
        while ($i < $length && ($json[$i] === ' ' || $json[$i] === "\t" || $json[$i] === "\n" || $json[$i] === "\r")) {
            $i++;
        }
    };

    $readString = function () use (&$i, $length, $json): string {
        if ($i >= $length || $json[$i] !== '"') {
            throw new RuntimeException('Expected a JSON string at offset ' . $i);
        }
        $i++;
        $buffer = '';
        while ($i < $length) {
            $char = $json[$i];
            if ($char === '\\') {
                $i++;
                if ($i >= $length) {
                    throw new RuntimeException('Truncated JSON escape');
                }
                $escaped = $json[$i];
                $simple = [
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    'b' => "\x08",
                    'f' => "\x0C",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                ];
                if (isset($simple[$escaped])) {
                    $buffer .= $simple[$escaped];
                    $i++;
                    continue;
                }
                if ($escaped === 'u') {
                    $hex = substr($json, $i + 1, 4);
                    if (preg_match('/^[0-9a-fA-F]{4}$/', $hex) !== 1) {
                        throw new RuntimeException('Invalid unicode escape at offset ' . $i);
                    }
                    $buffer .= mb_chr(hexdec($hex), 'UTF-8');
                    $i += 5;
                    continue;
                }
                throw new RuntimeException('Invalid JSON escape at offset ' . $i);
            }
            if ($char === '"') {
                $i++;
                return $buffer;
            }
            $buffer .= $char;
            $i++;
        }
        throw new RuntimeException('Unterminated JSON string');
    };

    $skipWhitespace();
    if ($i >= $length || $json[$i] !== '{') {
        throw new RuntimeException('Lexicon JSON must be an object');
    }
    $i++;
    $skipWhitespace();
    if ($i < $length && $json[$i] === '}') {
        return [];
    }

    while ($i < $length) {
        $skipWhitespace();
        $key = $readString();
        $skipWhitespace();
        if ($i >= $length || $json[$i] !== ':') {
            throw new RuntimeException('Expected ":" at offset ' . $i);
        }
        $i++;
        $skipWhitespace();
        $value = $readString();
        $pairs[] = [$key, $value];
        $skipWhitespace();
        if ($i >= $length) {
            throw new RuntimeException('Unterminated JSON object');
        }
        if ($json[$i] === ',') {
            $i++;
            continue;
        }
        if ($json[$i] === '}') {
            return $pairs;
        }
        throw new RuntimeException('Expected "," or "}" at offset ' . $i);
    }

    throw new RuntimeException('Unterminated JSON object');
}

$path = dirname(__DIR__) . '/data/hanja.txt';
$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
}

try {
    json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $pairs = hanja_json_pairs($raw);
    hanja_boot();
    $pdo = hanja_pdo();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$skipped = 0;
$collisions = 0;
$already = 0;
$cleaned = [];

foreach ($pairs as [$key, $value]) {
    $entry = hanja_normalize_entry($key, $value);
    if ($entry === null) {
        $skipped++;
        continue;
    }

    [$hangeul, $hanja] = $entry;
    if (isset($cleaned[$hangeul])) {
        if ($cleaned[$hangeul] === $hanja) {
            $already++;
        } else {
            $collisions++;
        }
        continue;
    }

    $cleaned[$hangeul] = $hanja;
}

$existing = [];
$select = $pdo->query('SELECT hangeul, hanja FROM hanja_lexicon');
if ($select === false) {
    fwrite(STDERR, "Could not read hanja_lexicon\n");
    exit(1);
}
while ($row = $select->fetch()) {
    $existing[$row['hangeul']] = $row['hanja'];
}

$insertRows = [];
foreach ($cleaned as $hangeul => $hanja) {
    if (!array_key_exists($hangeul, $existing)) {
        $insertRows[] = [$hangeul, $hanja];
        continue;
    }
    if ($existing[$hangeul] === $hanja) {
        $already++;
    } else {
        $collisions++;
    }
}

try {
    $pdo->beginTransaction();
    foreach (array_chunk($insertRows, 500) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as [$hangeul, $hanja]) {
            $placeholders[] = '(?, ?)';
            $params[] = $hangeul;
            $params[] = $hanja;
        }
        $sql = 'INSERT INTO hanja_lexicon (hangeul, hanja) VALUES '
            . implode(', ', $placeholders)
            . ' ON DUPLICATE KEY UPDATE hanja = hanja';
        $insert = $pdo->prepare($sql);
        $insert->execute($params);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, 'inserted: ' . count($insertRows) . "\n");
fwrite(STDOUT, 'already present: ' . $already . "\n");
fwrite(STDOUT, 'skipped: ' . $skipped . "\n");
fwrite(STDOUT, 'collisions: ' . $collisions . "\n");
