<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Tests\Integration;

use BeersCms\EditLock\EditLockService;
use BeersCms\EditLock\Storage\JsonEditLockStorage;
use RuntimeException;

// Test predava Composer autoload pres auto_prepend_file kazdemu samostatnemu procesu.
$file = $argv[1] ?? '';
$gate = $argv[2] ?? '';
$user = $argv[3] ?? '';
$id = $argv[4] ?? '';
if ($file === '' || $gate === '' || $user === '' || $id === '') {
    throw new RuntimeException('Missing worker arguments.');
}
$deadline = microtime(true) + 15.0;
while (!is_file($gate)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(1000);
}
$service = new EditLockService(new JsonEditLockStorage($file));
echo $service->acquire('article', $id, $user)->isSuccess() ? 'won' : 'lost';
$service->close();
