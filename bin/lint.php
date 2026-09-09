<?php

declare(strict_types=1);

/**
 * Syntax-checks all package PHP files using the running PHP binary.
 */
$paths = [__DIR__ . '/../src', __DIR__ . '/../tests', __DIR__, __DIR__ . '/../.php-cs-fixer.php'];
$files = [];

foreach ($paths as $path) {
    if (is_file($path)) {
        $files[] = $path;
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

foreach ($files as $file) {
    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to start PHP lint for {$file}.\n");
        exit(1);
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        fwrite(STDERR, $output);
        exit(1);
    }
}

printf("No syntax errors in %d PHP files.\n", count($files));
