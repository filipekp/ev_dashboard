<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\VehicleOperationRepository;
use RuntimeException;

/**
 * Aplikační služba pro provoz vozidla.
 *
 * Zajišťuje validaci a normalizaci vstupů před zápisem do repository a drží
 * business pravidla mimo HTTP controllery.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class VehicleOperationService
{
    /** @var VehicleOperationRepository */
    private $repository;

    /** @var string */
    private $storageRoot;

    public function __construct(VehicleOperationRepository $repository, string $storageRoot)
    {
        $this->repository = $repository;
        $this->storageRoot = rtrim($storageRoot, '/\\');
    }

    /** @param array<string,mixed> $input */
    public function addEnergyEntry(int $vehicleId, array $input): void
    {
        $quantity = $this->decimal($input['quantity'] ?? null);
        if ($quantity === null || $quantity <= 0) {
            throw new RuntimeException('Množství energie nebo paliva musí být větší než nula.');
        }

        $entryType = (string)($input['entry_type'] ?? 'charging');
        $energyType = (string)($input['energy_type'] ?? 'electricity');
        $allowedEntryTypes = ['charging', 'fueling'];
        $allowedEnergyTypes = ['electricity', 'petrol', 'diesel', 'lpg', 'cng'];
        if (!in_array($entryType, $allowedEntryTypes, true) || !in_array($energyType, $allowedEnergyTypes, true)) {
            throw new RuntimeException('Neplatný typ energie nebo paliva.');
        }

        $unit = $energyType === 'electricity' ? 'kWh' : ($energyType === 'cng' ? 'kg' : 'l');
        $unitPrice = $this->decimal($input['unit_price'] ?? null);
        $totalPrice = $this->decimal($input['total_price'] ?? null);
        if ($totalPrice === null && $unitPrice !== null) {
            $totalPrice = round($quantity * $unitPrice, 2);
        }

        $this->repository->addEnergyEntry($vehicleId, [
            'occurred_at' => $this->requiredDateTime($input['occurred_at'] ?? null),
            'entry_type' => $entryType,
            'energy_type' => $energyType,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'currency' => strtoupper(trim((string)($input['currency'] ?? 'CZK'))) ?: 'CZK',
            'odometer_km' => $this->decimal($input['odometer_km'] ?? null),
            'station' => $this->nullableText($input['station'] ?? null),
            'note' => $this->nullableText($input['note'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $file */
    public function addServiceRecord(int $vehicleId, array $input, ?array $file = null): void
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Vyplňte název servisního úkonu.');
        }

        $recordId = $this->repository->addServiceRecord($vehicleId, [
            'serviced_at' => $this->requiredDate($input['serviced_at'] ?? null),
            'category' => trim((string)($input['category'] ?? 'servis')) ?: 'servis',
            'title' => $title,
            'provider' => $this->nullableText($input['provider'] ?? null),
            'odometer_km' => $this->decimal($input['odometer_km'] ?? null),
            'cost' => $this->decimal($input['cost'] ?? null),
            'currency' => strtoupper(trim((string)($input['currency'] ?? 'CZK'))) ?: 'CZK',
            'note' => $this->nullableText($input['note'] ?? null),
        ]);

        if ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->storeAttachment($recordId, $file);
        }
    }

    /** @param array<string,mixed> $input */
    public function addExpense(int $vehicleId, array $input): void
    {
        $title = trim((string)($input['title'] ?? ''));
        $amount = $this->decimal($input['amount'] ?? null);
        if ($title === '' || $amount === null || $amount < 0) {
            throw new RuntimeException('Vyplňte název a platnou částku nákladu.');
        }

        $this->repository->addExpense($vehicleId, [
            'occurred_at' => $this->requiredDate($input['occurred_at'] ?? null),
            'category' => trim((string)($input['category'] ?? 'ostatni')) ?: 'ostatni',
            'title' => $title,
            'amount' => $amount,
            'currency' => strtoupper(trim((string)($input['currency'] ?? 'CZK'))) ?: 'CZK',
            'odometer_km' => $this->decimal($input['odometer_km'] ?? null),
            'note' => $this->nullableText($input['note'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input */
    public function addReminder(int $vehicleId, array $input): void
    {
        $title = trim((string)($input['title'] ?? ''));
        $dueDate = $this->optionalDate($input['due_date'] ?? null);
        $dueOdometer = $this->decimal($input['due_odometer_km'] ?? null);
        if ($title === '' || ($dueDate === null && $dueOdometer === null)) {
            throw new RuntimeException('Připomínka musí mít název a termín podle data nebo kilometrů.');
        }

        $this->repository->addReminder($vehicleId, [
            'title' => $title,
            'category' => trim((string)($input['category'] ?? 'servis')) ?: 'servis',
            'due_date' => $dueDate,
            'due_odometer_km' => $dueOdometer,
            'note' => $this->nullableText($input['note'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input */
    public function addTripBookEntry(int $vehicleId, array $input): void
    {
        $startedAt = trim((string)($input['started_at'] ?? ''));
        $start = trim((string)($input['start_address'] ?? ''));
        $end = trim((string)($input['end_address'] ?? ''));
        $distance = $this->decimal($input['distance_km'] ?? null);
        if ($startedAt === '' || $start === '' || $end === '' || $distance === null || $distance < 0) {
            throw new RuntimeException('Vyplňte datum, trasu a platnou vzdálenost jízdy.');
        }
        $classification = trim((string)($input['classification'] ?? ''));
        if (!in_array($classification, ['', 'private', 'business', 'commute', 'other'], true)) {
            throw new RuntimeException('Neplatná klasifikace jízdy.');
        }
        $sourceTripId = (int)($input['source_trip_id'] ?? 0);
        if ($sourceTripId > 0 && $this->repository->importedTrip($vehicleId, $sourceTripId) === null) {
            throw new RuntimeException('Zdrojová importovaná jízda nebyla nalezena.');
        }
        $this->repository->addTripBookEntry($vehicleId, [
            'source_trip_id' => $sourceTripId > 0 ? $sourceTripId : null,
            'started_at' => str_replace('T', ' ', $startedAt),
            'ended_at' => trim((string)($input['ended_at'] ?? '')) !== '' ? str_replace('T', ' ', trim((string)$input['ended_at'])) : null,
            'start_address' => $start,
            'end_address' => $end,
            'distance_km' => $distance,
            'start_odometer_km' => $this->decimal($input['start_odometer_km'] ?? null),
            'end_odometer_km' => $this->decimal($input['end_odometer_km'] ?? null),
            'classification' => $classification ?: null,
            'purpose' => $this->nullableText($input['purpose'] ?? null),
            'note' => $this->nullableText($input['trip_note'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input */
    public function updateTripBookEntry(int $vehicleId, array $input): void
    {
        $entryId = (int)($input['entry_id'] ?? 0);
        if ($entryId <= 0) {
            throw new RuntimeException('Záznam jízdy nebyl nalezen.');
        }

        $startedAt = trim((string)($input['started_at'] ?? ''));
        $start = trim((string)($input['start_address'] ?? ''));
        $end = trim((string)($input['end_address'] ?? ''));
        $distance = $this->decimal($input['distance_km'] ?? null);
        if ($startedAt === '' || $start === '' || $end === '' || $distance === null || $distance < 0) {
            throw new RuntimeException('Vyplňte datum, trasu a platnou vzdálenost jízdy.');
        }

        $classification = trim((string)($input['classification'] ?? ''));
        if (!in_array($classification, ['', 'private', 'business', 'commute', 'other'], true)) {
            throw new RuntimeException('Neplatná klasifikace jízdy.');
        }

        $sourceTripId = (int)($input['source_trip_id'] ?? 0);
        if ($sourceTripId > 0 && $this->repository->importedTrip($vehicleId, $sourceTripId) === null) {
            throw new RuntimeException('Zdrojová importovaná jízda nebyla nalezena.');
        }

        $this->repository->updateTripBookEntry($vehicleId, $entryId, [
            'source_trip_id' => $sourceTripId > 0 ? $sourceTripId : null,
            'started_at' => str_replace('T', ' ', $startedAt),
            'ended_at' => trim((string)($input['ended_at'] ?? '')) !== '' ? str_replace('T', ' ', trim((string)$input['ended_at'])) : null,
            'start_address' => $start,
            'end_address' => $end,
            'distance_km' => $distance,
            'start_odometer_km' => $this->decimal($input['start_odometer_km'] ?? null),
            'end_odometer_km' => $this->decimal($input['end_odometer_km'] ?? null),
            'classification' => $classification ?: null,
            'purpose' => $this->nullableText($input['purpose'] ?? null),
            'note' => $this->nullableText($input['trip_note'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input */
    public function updateTripLog(int $vehicleId, array $input): void
    {
        $tripId = (int)($input['trip_id'] ?? 0);
        if ($tripId <= 0) {
            throw new RuntimeException('Jízda nebyla nalezena.');
        }

        $classification = trim((string)($input['classification'] ?? ''));
        $allowed = ['', 'private', 'business', 'commute', 'other'];
        if (!in_array($classification, $allowed, true)) {
            throw new RuntimeException('Neplatná klasifikace jízdy.');
        }
        $this->repository->updateTripLog(
            $vehicleId,
            $tripId,
            $classification,
            $this->nullableText($input['trip_note'] ?? null)
        );
    }

    /** @param array<string,mixed> $file */
    private function storeAttachment(int $serviceRecordId, array $file): void
    {
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Přílohu se nepodařilo nahrát.');
        }
        if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new RuntimeException('Příloha může mít maximálně 10 MB.');
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpName);
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Povolené přílohy jsou PDF, JPG, PNG a WEBP.');
        }

        $directory = $this->storageRoot . '/service';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Nelze vytvořit adresář pro servisní přílohy.');
        }

        $storedName = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $destination = $directory . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Přílohu se nepodařilo uložit.');
        }

        $this->repository->addAttachment(
            $serviceRecordId,
            basename((string)($file['name'] ?? 'priloha.' . $allowed[$mime])),
            $storedName,
            $mime,
            (int)$file['size']
        );
    }

    /** @param mixed $value */
    private function decimal($value): ?float
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace([' ', ','], ['', '.'], $raw);
        return is_numeric($raw) ? (float)$raw : null;
    }

    /** @param mixed $value */
    private function nullableText($value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    /** @param mixed $value */
    private function requiredDate($value): string
    {
        $date = $this->optionalDate($value);
        if ($date === null) {
            throw new RuntimeException('Vyplňte platné datum.');
        }
        return $date;
    }

    /** @param mixed $value */
    private function optionalDate($value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        return $date && $date->format('Y-m-d') === $raw ? $raw : null;
    }

    /** @param mixed $value */
    private function requiredDateTime($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            throw new RuntimeException('Vyplňte datum a čas.');
        }
        $normalized = str_replace('T', ' ', $raw);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $normalized);
        if (!$date) {
            throw new RuntimeException('Vyplňte platné datum a čas.');
        }
        return $date->format('Y-m-d H:i:s');
    }
}
