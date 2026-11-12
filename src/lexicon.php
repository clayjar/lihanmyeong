<?php

declare(strict_types=1);

/**
 * Reduce one dictionary row to a pure Hangeul key and Hanja value.
 * Particle copies become the stem. Returns null when the row should be skipped.
 *
 * @return array{0: string, 1: string}|null
 */
function hanja_normalize_entry(string $key, string $value): ?array
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

    if (mb_strlen($value, 'UTF-8') > 64 || preg_match('/\p{Han}/u', $value) !== 1) {
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
 * @param list<mixed> $tokens
 * @return array<string, string>
 */
function hanja_lookup(PDO $pdo, array $tokens): array
{
    $unique = [];
    foreach ($tokens as $token) {
        if (!is_string($token) || preg_match('/^[가-힣]{2,64}$/u', $token) !== 1) {
            continue;
        }
        $unique[$token] = true;
        if (count($unique) >= 1000) {
            break;
        }
    }

    $accepted = array_keys($unique);
    $needles = [];
    foreach ($accepted as $token) {
        $needles[$token] = true;
        [$stem, $particle] = hanja_split_particle($token);
        if ($particle !== '' && mb_strlen($stem, 'UTF-8') >= 2) {
            $needles[$stem] = true;
        }
    }

    $rows = [];
    foreach (array_chunk(array_keys($needles), 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
            "SELECT hangeul, hanja FROM hanja_lexicon WHERE hangeul IN ($placeholders)"
        );
        $stmt->execute(array_values($chunk));
        while ($row = $stmt->fetch()) {
            $rows[$row['hangeul']] = $row['hanja'];
        }
    }

    $matches = [];
    foreach ($accepted as $token) {
        if (isset($rows[$token])) {
            $matches[$token] = $rows[$token];
            continue;
        }

        [$stem, $particle] = hanja_split_particle($token);
        if ($particle !== '' && mb_strlen($stem, 'UTF-8') >= 2 && isset($rows[$stem])) {
            $matches[$token] = $rows[$stem] . $particle;
        }
    }

    return $matches;
}
