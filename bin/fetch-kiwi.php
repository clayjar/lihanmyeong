<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(1);
}

const KIWI_VERSION = '0.23.0';
const MODEL_URL = 'https://github.com/bab2min/Kiwi/releases/download/v' . KIWI_VERSION . '/kiwi_model_v' . KIWI_VERSION . '_base.tgz';
const PACKAGE_URL = 'https://registry.npmjs.org/kiwi-nlp/-/kiwi-nlp-' . KIWI_VERSION . '.tgz';
const MODEL_FILES = [
    'combiningRule.txt',
    'cong.mdl',
    'default.dict',
    'dialect.dict',
    'extract.mdl',
    'multi.dict',
    'nounchr.mdl',
    'sj.morph',
    'typo.dict',
];

function hanja_run(string $command): void
{
    passthru($command, $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "Command failed ({$exit}): {$command}\n");
        exit($exit === 0 ? 1 : $exit);
    }
}

function hanja_download(string $url, string $dest): void
{
    hanja_run('curl -fsSL -o ' . escapeshellarg($dest) . ' -L ' . escapeshellarg($url));
}

$npx = trim((string) shell_exec('command -v npx'));
$tar = trim((string) shell_exec('command -v tar'));
if ($npx === '' || $tar === '') {
    fwrite(STDERR, "npx and tar are required to fetch Kiwi.\n");
    exit(1);
}

$root = dirname(__DIR__);
$dest = $root . '/public/kiwi';
if (!is_dir($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
    fwrite(STDERR, "Could not create {$dest}\n");
    exit(1);
}

$work = sys_get_temp_dir() . '/lihanmyeong-kiwi-' . KIWI_VERSION . '-' . bin2hex(random_bytes(4));
if (!mkdir($work, 0700, true) && !is_dir($work)) {
    fwrite(STDERR, "Could not create {$work}\n");
    exit(1);
}

$cleanup = function () use ($work): void {
    hanja_run('rm -rf ' . escapeshellarg($work));
};

try {
    fwrite(STDOUT, "Downloading Kiwi " . KIWI_VERSION . "\n");
    hanja_download(MODEL_URL, $work . '/model.tgz');
    hanja_download(PACKAGE_URL, $work . '/kiwi-nlp.tgz');
    hanja_run('tar -xzf ' . escapeshellarg($work . '/model.tgz') . ' -C ' . escapeshellarg($work));
    hanja_run('tar -xzf ' . escapeshellarg($work . '/kiwi-nlp.tgz') . ' -C ' . escapeshellarg($work));

    $builderPath = $work . '/package/dist/kiwi-builder.js';
    $builder = file_get_contents($builderPath);
    if ($builder === false) {
        throw new RuntimeException('Missing kiwi-builder.js');
    }

    $old = <<<'JS'
async function createKiwiApi(wasmPath) {
    const kiwi = await initKiwi({
        locateFile: (path) => {
            if (path.endsWith('.wasm')) {
                return wasmPath;
            }
            return path;
        },
    });
JS;
    $new = <<<'JS'
async function createKiwiApi(wasmSource) {
    const options = {};
    if (wasmSource instanceof Uint8Array) {
        options.wasmBinary = wasmSource;
        options.locateFile = (path) => path;
    } else {
        options.locateFile = (path) => {
            if (path.endsWith('.wasm')) {
                return wasmSource;
            }
            return path;
        };
    }
    const kiwi = await initKiwi(options);
JS;
    if (!str_contains($builder, $old)) {
        throw new RuntimeException('kiwi-builder.js did not match Kiwi ' . KIWI_VERSION);
    }
    if (file_put_contents($builderPath, str_replace($old, $new, $builder)) === false) {
        throw new RuntimeException('Could not patch kiwi-builder.js');
    }

    $bundle = $dest . '/kiwi-nlp.js';
    hanja_run(
        escapeshellarg($npx) . ' --yes esbuild '
        . escapeshellarg($builderPath)
        . ' --bundle --format=esm --platform=browser --external:node:module --outfile='
        . escapeshellarg($bundle)
    );

    $wasmSrc = $work . '/package/dist/kiwi-wasm.wasm';
    if (!copy($wasmSrc, $dest . '/kiwi-wasm.wasm')) {
        throw new RuntimeException('Could not copy kiwi-wasm.wasm');
    }

    $modelDir = $work . '/models/cong/base';
    foreach (MODEL_FILES as $name) {
        $from = $modelDir . '/' . $name;
        if (!is_file($from) || !copy($from, $dest . '/' . $name)) {
            throw new RuntimeException('Missing model file ' . $name);
        }
    }

    $files = array_merge(['kiwi-nlp.js', 'kiwi-wasm.wasm'], MODEL_FILES);
    $manifest = json_encode(
        ['version' => KIWI_VERSION, 'files' => $files],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    if ($manifest === false || file_put_contents($dest . '/manifest.json', $manifest . "\n") === false) {
        throw new RuntimeException('Could not write manifest.json');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $cleanup();
    exit(1);
}

$cleanup();
fwrite(STDOUT, "Kiwi " . KIWI_VERSION . " is in public/kiwi\n");
