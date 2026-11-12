<?php

declare(strict_types=1);

const HANJA_VERBAL_ENDINGS = ['했', '할', '해', '한', '하', '히'];

const HANJA_LOOKUP_TAGS = ['NNG', 'NNP', 'XR', 'XSV', 'XSA'];

/**
 * @return array{0: string, 1: string}|null
 */
function hanja_prepare_pair(string $key, string $value): ?array
{
    $key = trim($key);
    $value = trim($value);
    if ($key === '' || $value === '') {
        return null;
    }

    if (preg_match('/\p{Han}/u', $key) === 1) {
        return null;
    }

    $key = preg_replace('/\s+/u', '', $key);
    if (!is_string($key) || preg_match('/^[가-힣]{1,64}$/u', $key) !== 1) {
        return null;
    }

    if (mb_strlen($value, 'UTF-8') > 64) {
        return null;
    }

    return [$key, $value];
}

/**
 * @param list<string> $endings
 * @return array{0: string, 1: string}
 */
function hanja_split_ending(string $token, array $endings): array
{
    foreach ($endings as $ending) {
        $endingLength = mb_strlen($ending, 'UTF-8');
        $tokenLength = mb_strlen($token, 'UTF-8');
        if ($tokenLength <= $endingLength) {
            continue;
        }
        if (mb_substr($token, -$endingLength, null, 'UTF-8') === $ending) {
            return [mb_substr($token, 0, $tokenLength - $endingLength, 'UTF-8'), $ending];
        }
    }

    return [$token, ''];
}

/**
 * Reduce one dictionary row to a pure Hangeul key and Hanja value.
 * Particle copies become the stem. Returns null when the row should be skipped.
 *
 * @return array{0: string, 1: string}|null
 */
function hanja_normalize_entry(string $key, string $value): ?array
{
    $pair = hanja_prepare_pair($key, $value);
    if ($pair === null) {
        return null;
    }

    [$key, $value] = $pair;
    if (preg_match('/\p{Han}/u', $value) !== 1) {
        return null;
    }

    if (preg_match('/[가-힣]/u', $value) === 1) {
        [$keyStem, $keyParticle] = hanja_split_particle($key);
        [$valueStem, $valueParticle] = hanja_split_particle($value);
        if ($keyParticle === '' || $keyParticle !== $valueParticle) {
            return null;
        }

        $key = $keyStem;
        $value = $valueStem;
        if (preg_match('/^[가-힣]{1,64}$/u', $key) !== 1) {
            return null;
        }
        $valueLength = mb_strlen($value, 'UTF-8');
        if ($valueLength < 1 || $valueLength > 64) {
            return null;
        }
        if (preg_match('/[가-힣]/u', $value) === 1 || preg_match('/\p{Han}/u', $value) !== 1) {
            return null;
        }
    }

    return [$key, $value];
}

/**
 * 하다-style rows become separate XSV and XSA readings of the stem.
 * They do not replace the plain noun.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function hanja_verbal_senses(string $key, string $value): array
{
    $pair = hanja_prepare_pair($key, $value);
    if ($pair === null) {
        return [];
    }

    [$key, $value] = $pair;
    if (preg_match('/\p{Han}/u', $value) !== 1 || preg_match('/[가-힣]/u', $value) !== 1) {
        return [];
    }

    [$keyStem, $keyEnding] = hanja_split_ending($key, HANJA_VERBAL_ENDINGS);
    [$valueStem, $valueEnding] = hanja_split_ending($value, HANJA_VERBAL_ENDINGS);
    if ($keyEnding === '' || $keyEnding !== $valueEnding) {
        return [];
    }
    if (preg_match('/^[가-힣]{1,64}$/u', $keyStem) !== 1) {
        return [];
    }
    $valueLength = mb_strlen($valueStem, 'UTF-8');
    if ($valueLength < 1 || $valueLength > 64) {
        return [];
    }
    if (preg_match('/[가-힣]/u', $valueStem) === 1 || preg_match('/\p{Han}/u', $valueStem) !== 1) {
        return [];
    }

    return [
        [$keyStem, $valueStem, 'XSV'],
        [$keyStem, $valueStem, 'XSA'],
    ];
}

function hanja_migrate_lexicon(PDO $pdo): void
{
    $hasOld = false;
    $hasNew = false;
    $indexes = $pdo->query('SHOW INDEX FROM hanja_lexicon');
    if ($indexes === false) {
        throw new RuntimeException('Could not read hanja_lexicon indexes');
    }
    while ($row = $indexes->fetch()) {
        if ($row['Key_name'] === 'idx_hangeul') {
            $hasOld = true;
        }
        if ($row['Key_name'] === 'idx_hangeul_pos') {
            $hasNew = true;
        }
    }

    $pdo->exec("UPDATE hanja_lexicon SET pos = 'NNG' WHERE pos IS NULL OR pos = ''");
    if ($hasOld) {
        $pdo->exec('ALTER TABLE hanja_lexicon DROP INDEX idx_hangeul');
    }
    if (!$hasNew) {
        $pdo->exec('ALTER TABLE hanja_lexicon ADD UNIQUE KEY idx_hangeul_pos (hangeul, pos)');
    }
}

function hanja_pos_for_tag(string $tag): ?string
{
    return match ($tag) {
        'NNG', 'NNP', 'XR' => 'NNG',
        'XSV' => 'XSV',
        'XSA' => 'XSA',
        default => null,
    };
}

/**
 * @param list<mixed> $tokens
 * @return array<string, string>
 */
function hanja_lookup(PDO $pdo, array $tokens): array
{
    $queries = [];
    foreach ($tokens as $token) {
        if (is_string($token)) {
            if (preg_match('/^[가-힣]{2,64}$/u', $token) !== 1) {
                continue;
            }
            $queries[$token] = ['key' => $token, 'form' => $token, 'pos' => 'NNG', 'particle' => true];
        } elseif (is_array($token)) {
            $form = $token['form'] ?? null;
            $tag = $token['tag'] ?? null;
            if (!is_string($form) || !is_string($tag) || preg_match('/^[가-힣]{2,64}$/u', $form) !== 1) {
                continue;
            }
            if (!in_array($tag, HANJA_LOOKUP_TAGS, true)) {
                continue;
            }
            $pos = hanja_pos_for_tag($tag);
            if ($pos === null) {
                continue;
            }
            $key = $form . ':' . $tag;
            $queries[$key] = ['key' => $key, 'form' => $form, 'pos' => $pos, 'particle' => false];
        }

        if (count($queries) >= 1000) {
            break;
        }
    }

    $needles = [];
    foreach ($queries as $query) {
        $needles[$query['form']] = true;
        if ($query['particle']) {
            [$stem, $particle] = hanja_split_particle($query['form']);
            if ($particle !== '' && mb_strlen($stem, 'UTF-8') >= 2) {
                $needles[$stem] = true;
            }
        }
    }

    $rows = [];
    foreach (array_chunk(array_keys($needles), 500) as $chunk) {
        if ($chunk === []) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
            "SELECT hangeul, pos, hanja FROM hanja_lexicon WHERE hangeul IN ($placeholders)"
        );
        $stmt->execute(array_values($chunk));
        while ($row = $stmt->fetch()) {
            $pos = $row['pos'] === null || $row['pos'] === '' ? 'NNG' : $row['pos'];
            $rows[$row['hangeul'] . "\0" . $pos] = $row['hanja'];
        }
    }

    $matches = [];
    foreach ($queries as $query) {
        $exact = $rows[$query['form'] . "\0" . $query['pos']] ?? null;
        if ($exact !== null) {
            $matches[$query['key']] = $exact;
            continue;
        }

        if (!$query['particle'] || $query['pos'] !== 'NNG') {
            continue;
        }

        [$stem, $particle] = hanja_split_particle($query['form']);
        if ($particle !== '' && mb_strlen($stem, 'UTF-8') >= 2) {
            $stemHanja = $rows[$stem . "\0NNG"] ?? null;
            if ($stemHanja !== null) {
                $matches[$query['key']] = $stemHanja . $particle;
            }
        }
    }

    return $matches;
}
