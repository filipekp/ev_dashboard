<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$key = base64_encode(random_bytes(32));
fwrite(STDOUT, 'VEHICLE_CREDENTIALS_KEY=' . $key . PHP_EOL);
fwrite(STDOUT, 'Uložte hodnotu do .env a neměňte ji, dokud existují připojené OEM konektory.' . PHP_EOL);
