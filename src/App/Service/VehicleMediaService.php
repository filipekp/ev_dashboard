<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\VehicleMediaRepository;
use App\UploadValidator;
use RuntimeException;

/**
 * Bezpečný upload fotografií vozidel do neveřejného úložiště.
 */
final class VehicleMediaService
{
    /** @var VehicleMediaRepository */
    private $repository;

    /** @var string */
    private $storageRoot;

    public function __construct(VehicleMediaRepository $repository, string $storageRoot)
    {
        $this->repository = $repository;
        $this->storageRoot = rtrim($storageRoot, '/\\');
    }

    /** @param array<string,mixed> $file */
    public function uploadPhoto(int $vehicleId, int $userId, array $file, ?string $caption = null): int
    {
        $tmp = UploadValidator::uploadedPath($file, 10 * 1024 * 1024);
        $mime = UploadValidator::mime($tmp);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Podporované fotografie jsou JPG, PNG a WebP.');
        }
        UploadValidator::assertImageDimensions($tmp);

        $directory = $this->storageRoot . '/vehicle-media';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Nelze vytvořit úložiště fotografií.');
        }

        $storedName = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $destination = $directory . '/' . $storedName;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('Fotografii se nepodařilo uložit.');
        }
        @chmod($destination, 0640);

        $existing = $this->repository->listForVehicle($vehicleId);
        $caption = trim((string)$caption);
        if (mb_strlen($caption) > 500) {
            $caption = mb_substr($caption, 0, 500);
        }

        return $this->repository->add($vehicleId, $userId, [
            'original_name' => UploadValidator::safeOriginalName(
                (string)($file['name'] ?? ''),
                'photo.' . $allowed[$mime]
            ),
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'file_size' => (int)$file['size'],
            'caption' => $caption !== '' ? $caption : null,
            'is_primary' => !$existing,
        ]);
    }
}
