<?php

declare(strict_types=1);

/**
 * Longest first so 에서 wins over 에, and 으로 wins over 로.
 */
const HANJA_PARTICLES = [
    '에서',
    '으로',
    '에게',
    '한테',
    '까지',
    '부터',
    '처럼',
    '보다',
    '하고',
    '이',
    '가',
    '은',
    '는',
    '을',
    '를',
    '의',
    '에',
    '로',
    '과',
    '와',
    '도',
    '만',
];

/**
 * @return array{0: string, 1: string} stem and particle; particle is empty when none matches
 */
function hanja_split_particle(string $token): array
{
    foreach (HANJA_PARTICLES as $particle) {
        $particleLength = mb_strlen($particle, 'UTF-8');
        $tokenLength = mb_strlen($token, 'UTF-8');
        if ($tokenLength <= $particleLength) {
            continue;
        }
        if (mb_substr($token, -$particleLength, null, 'UTF-8') === $particle) {
            return [mb_substr($token, 0, $tokenLength - $particleLength, 'UTF-8'), $particle];
        }
    }

    return [$token, ''];
}
