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
    /**
     * Smaže fotografii z databáze i fyzického úložiště.
     *
     * Soubor se nejprve atomicky přesune na dočasné jméno. Pokud databázová
     * operace selže, vrátí se zpět. Po úspěšném commitu se dočasný soubor
     * definitivně odstraní.
     */
    public function deletePhoto(int $vehicleId, int $mediaId): void
    {
        $media = $this->repository->find($mediaId);
        if (!$media || (int)$media['vehicle_id'] !== $vehicleId) {
            throw new RuntimeException('Fotografie nebyla nalezena.');
        }

        $path = $this->storageRoot . '/vehicle-media/' . basename((string)$media['stored_name']);
        $quarantine = $this->quarantine($path);

        try {
            $this->repository->delete($vehicleId, $mediaId);
        } catch (\Throwable $e) {
            $this->restoreQuarantine($quarantine, $path);
            throw $e;
        }

        $this->purgeQuarantine($quarantine, 'Fotografie byla odstraněna z aplikace, ale fyzický soubor se nepodařilo smazat.');
    }

    private function quarantine(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $quarantine = $path . '.delete-' . bin2hex(random_bytes(6));
        if (!@rename($path, $quarantine)) {
            throw new RuntimeException('Fotografii se nepodařilo připravit k fyzickému smazání.');
        }

        return $quarantine;
    }

    private function restoreQuarantine(?string $quarantine, string $path): void
    {
        if ($quarantine !== null && is_file($quarantine) && !is_file($path)) {
            @rename($quarantine, $path);
        }
    }

    private function purgeQuarantine(?string $quarantine, string $errorMessage): void
    {
        if ($quarantine === null || !is_file($quarantine)) {
            return;
        }
        if (!@unlink($quarantine) && is_file($quarantine)) {
            throw new RuntimeException($errorMessage . ' Zkontrolujte oprávnění adresáře storage/vehicle-media.');
        }
    }

}
