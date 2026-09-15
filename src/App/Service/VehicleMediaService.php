<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\VehicleMediaRepository;
use RuntimeException;

/** Bezpečný upload fotografií vozidel. */
final class VehicleMediaService
{
    /** @var VehicleMediaRepository */ private $repository;
    /** @var string */ private $storageRoot;
    public function __construct(VehicleMediaRepository $repository, string $storageRoot)
    {
        $this->repository = $repository;
        $this->storageRoot = rtrim($storageRoot, '/\\');
    }

    /** @param array<string,mixed> $file */
    public function uploadPhoto(int $vehicleId, int $userId, array $file, ?string $caption = null): int
    {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Fotografii se nepodařilo nahrát.');
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('Fotografie může mít maximálně 10 MB.');
        $tmp = (string)($file['tmp_name'] ?? '');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $allowed = ['image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp'];
        if (!isset($allowed[$mime])) throw new RuntimeException('Podporované fotografie jsou JPG, PNG a WebP.');
        $dir = $this->storageRoot . '/vehicle-media';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Nelze vytvořit úložiště fotografií.');
        $stored = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) throw new RuntimeException('Fotografii se nepodařilo uložit.');
        $existing = $this->repository->listForVehicle($vehicleId);
        return $this->repository->add($vehicleId,$userId,[
            'original_name' => (string)($file['name'] ?? 'photo.' . $allowed[$mime]),
            'stored_name' => $stored,
            'mime_type' => $mime,
            'file_size' => $size,
            'caption' => trim((string)$caption) ?: null,
            'is_primary' => !$existing,
        ]);
    }
}
