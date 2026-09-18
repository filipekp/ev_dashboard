<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $connectionId = 0;
    foreach ($argv as $argument) {
        if (strpos($argument, '--connection=') === 0) {
            $connectionId = (int)substr($argument, strlen('--connection='));
        }
    }

    $result = $connectionId > 0
        ? $app->vehicleSync()->syncConnection($connectionId, null, 'cron')
        : $app->vehicleSync()->syncAll();

    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[EV Stats AutoSync] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
