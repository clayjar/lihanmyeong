<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/particles.php';
require_once __DIR__ . '/lexicon.php';

function hanja_boot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    hanja_load_env(dirname(__DIR__) . '/.env');
    $booted = true;
}
