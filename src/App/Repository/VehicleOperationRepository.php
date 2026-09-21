<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Datová vrstva pro provozní evidenci vozidel.
 *
 * Odděluje SQL dotazy od controllerů a služeb. Pracuje s tankováním/nabíjením,
 * servisem, ostatními náklady, připomínkami a klasifikací jízd.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class VehicleOperationRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @param array<string,mixed> $data */
    public function addEnergyEntry(int $vehicleId, array $data): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO vehicle_energy_entries
                (vehicle_id, occurred_at, ended_at, entry_type, energy_type, quantity, unit, unit_price, total_price, currency,
                 odometer_km, start_soc, end_soc, station, note, source, source_key, is_estimated, price_source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $query->execute([
            $vehicleId,
            $data['occurred_at'],
            $data['ended_at'] ?? null,
            $data['entry_type'],
            $data['energy_type'],
            $data['quantity'],
            $data['unit'],
            $data['unit_price'],
            $data['total_price'],
            $data['currency'],
            $data['odometer_km'],
            $data['start_soc'] ?? null,
            $data['end_soc'] ?? null,
            $data['station'],
            $data['note'],
            $data['source'] ?? 'manual',
            $data['source_key'] ?? null,
            !empty($data['is_estimated']) ? 1 : 0,
            $data['price_source'] ?? (($data['unit_price'] ?? null) !== null ? 'manual' : 'none'),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function energyEntry(int $vehicleId, int $entryId): ?array
    {
        $query = $this->pdo->prepare(
            "SELECT e.*, EXISTS(SELECT 1 FROM document_operation_links l WHERE l.vehicle_id=e.vehicle_id AND l.operation_type='energy' AND l.operation_id=e.id) document_linked
             FROM vehicle_energy_entries e WHERE e.id=? AND e.vehicle_id=? LIMIT 1"
        );
        $query->execute([$entryId, $vehicleId]);
        $row = $query->fetch();

        return $row ?: null;
    }

    public function updateEnergyPrice(
        int $vehicleId,
        int $entryId,
        ?float $unitPrice,
        ?float $totalPrice,
        string $currency,
        string $priceSource = 'manual'
    ): void {
        $query = $this->pdo->prepare(
            'UPDATE vehicle_energy_entries SET unit_price=?,total_price=?,currency=?,price_source=? WHERE id=? AND vehicle_id=?'
        );
        $query->execute([$unitPrice, $totalPrice, $currency, $priceSource, $entryId, $vehicleId]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    public function findMatchingTelemetryEnergyEntry(int $vehicleId, array $row): ?array
    {
        $occurredAtText = trim((string)($row['occurred_at'] ?? ''));
        $occurredAt = strtotime($occurredAtText);
        $quantity = isset($row['quantity']) && is_numeric($row['quantity']) ? (float)$row['quantity'] : null;
        if ($occurredAt === false || $quantity === null || $quantity <= 0) {
            return null;
        }

        // Doklady často obsahují pouze datum bez přesného času. Po normalizaci
        // je takový údaj 00:00:00; v tom případě párujeme celý kalendářní den.
        $timePart = substr($occurredAtText, 11, 8);
        $dateOnly = $timePart === '' || $timePart === '00:00:00';
        if ($dateOnly) {
            $from = date('Y-m-d 00:00:00', $occurredAt);
            $to = date('Y-m-d 23:59:59', $occurredAt);
        } else {
            $from = date('Y-m-d H:i:s', $occurredAt - 7200);
            $to = date('Y-m-d H:i:s', $occurredAt + 7200);
        }

        $query = $this->pdo->prepare(
            "SELECT e.* FROM vehicle_energy_entries e
             WHERE e.vehicle_id=? AND e.entry_type='charging' AND e.energy_type='electricity'
               AND e.source LIKE 'telemetry_%'
               AND NOT EXISTS (SELECT 1 FROM document_operation_links l WHERE l.vehicle_id=e.vehicle_id AND l.operation_type='energy' AND l.operation_id=e.id)
               AND e.occurred_at>=? AND e.occurred_at<=?
             ORDER BY ABS(TIMESTAMPDIFF(SECOND,e.occurred_at,?)), ABS(e.quantity-?)
             LIMIT 20"
        );
        $query->execute([
            $vehicleId,
            $from,
            $to,
            date('Y-m-d H:i:s', $occurredAt),
            $quantity,
        ]);

        $rowOdometer = isset($row['odometer_km']) && is_numeric($row['odometer_km']) ? (float)$row['odometer_km'] : null;
        foreach ($query->fetchAll() as $candidate) {
            $candidateQuantity = (float)$candidate['quantity'];
            $tolerance = max(1.0, max($quantity, $candidateQuantity) * 0.18);
            if (abs($candidateQuantity - $quantity) > $tolerance) {
                continue;
            }

            $candidateOdometer = isset($candidate['odometer_km']) && is_numeric($candidate['odometer_km'])
                ? (float)$candidate['odometer_km']
                : null;
            if ($rowOdometer !== null && $candidateOdometer !== null && abs($rowOdometer - $candidateOdometer) > 5.0) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    public function applyDocumentEnergyData(int $vehicleId, int $entryId, array $row, string $currency): void
    {
        $query = $this->pdo->prepare(
            'UPDATE vehicle_energy_entries SET quantity=?,unit_price=?,total_price=?,currency=?,odometer_km=COALESCE(?,odometer_km),
             station=COALESCE(?,station),note=COALESCE(?,note),is_estimated=0,price_source=? WHERE id=? AND vehicle_id=?'
        );
        $query->execute([
            $row['quantity'],
            $row['unit_price'],
            $row['total_price'],
            $currency,
            $row['odometer_km'],
            $row['station'],
            $row['note'],
            ($row['unit_price'] ?? null) !== null || ($row['total_price'] ?? null) !== null ? 'document' : 'none',
            $entryId,
            $vehicleId,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function addServiceRecord(int $vehicleId, array $data): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO vehicle_service_records
                (vehicle_id, serviced_at, category, title, provider, odometer_km, cost, currency, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $query->execute([
            $vehicleId,
            $data['serviced_at'],
            $data['category'],
            $data['title'],
            $data['provider'],
            $data['odometer_km'],
            $data['cost'],
            $data['currency'],
            $data['note'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function addAttachment(
        int $serviceRecordId,
        string $originalName,
        string $storedName,
        string $mimeType,
        int $fileSize
    ): int {
        $query = $this->pdo->prepare(
            'INSERT INTO vehicle_service_attachments
                (service_record_id, original_name, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?)'
        );
        $query->execute([$serviceRecordId, $originalName, $storedName, $mimeType, $fileSize]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function addExpense(int $vehicleId, array $data): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO vehicle_expenses
                (vehicle_id, occurred_at, category, title, amount, currency, odometer_km, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $query->execute([
            $vehicleId,
            $data['occurred_at'],
            $data['category'],
            $data['title'],
            $data['amount'],
            $data['currency'],
            $data['odometer_km'],
            $data['note'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function addReminder(int $vehicleId, array $data): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO vehicle_reminders
                (vehicle_id, title, category, due_date, due_odometer_km, note)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $query->execute([
            $vehicleId,
            $data['title'],
            $data['category'],
            $data['due_date'],
            $data['due_odometer_km'],
            $data['note'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function completeReminder(int $vehicleId, int $reminderId): void
    {
        $query = $this->pdo->prepare(
            'UPDATE vehicle_reminders SET completed_at=NOW() WHERE id=? AND vehicle_id=?'
        );
        $query->execute([$reminderId, $vehicleId]);
    }

    public function updateTripLog(int $vehicleId, int $tripId, string $classification, ?string $note): void
    {
        $query = $this->pdo->prepare(
            'UPDATE trips SET classification=?, trip_note=? WHERE id=? AND vehicle_id=?'
        );
        $query->execute([$classification ?: null, $note, $tripId, $vehicleId]);
    }

    /**
     * Nabíjecí relace zasahující do časového intervalu jízdy.
     *
     * @return array<int,array<string,mixed>>
     */
    public function chargingEntriesBetween(int $vehicleId, string $from, string $to): array
    {
        $query = $this->pdo->prepare(
            "SELECT id,occurred_at,ended_at,quantity,unit,unit_price,total_price,currency,odometer_km,start_soc,end_soc,station,note,source,is_estimated "
            . "FROM vehicle_energy_entries "
            . "WHERE vehicle_id=? AND entry_type='charging' AND energy_type='electricity' "
            . "AND occurred_at<=? AND COALESCE(ended_at,occurred_at)>=? "
            . 'ORDER BY occurred_at ASC,id ASC LIMIT 50'
        );
        $query->execute([$vehicleId, $to, $from]);

        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function energyEntries(int $vehicleId, int $limit = 20, ?string $from = null, int $offset = 0): array
    {
        $query = $this->pdo->prepare(
            "SELECT e.*, EXISTS(SELECT 1 FROM document_operation_links l WHERE l.vehicle_id=e.vehicle_id AND l.operation_type='energy' AND l.operation_id=e.id) document_linked
             FROM vehicle_energy_entries e WHERE e.vehicle_id=?" . ($from !== null ? ' AND e.occurred_at>=?' : '')
            . ' ORDER BY e.occurred_at DESC, e.id DESC LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset)
        );
        $params = [$vehicleId]; if ($from !== null) { $params[] = $from; }
        $query->execute($params);

        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function serviceRecords(int $vehicleId, int $limit = 20, ?string $from = null, int $offset = 0): array
    {
        $query = $this->pdo->prepare(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM vehicle_service_attachments a WHERE a.service_record_id=s.id) attachment_count
             FROM vehicle_service_records s
             WHERE s.vehicle_id=?' . ($from !== null ? ' AND s.serviced_at>=?' : '') . '
             ORDER BY s.serviced_at DESC, s.id DESC
             LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset)
        );
        $params = [$vehicleId]; if ($from !== null) { $params[] = substr($from, 0, 10); }
        $query->execute($params);

        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function expenses(int $vehicleId, int $limit = 20, ?string $from = null, int $offset = 0): array
    {
        $query = $this->pdo->prepare(
            'SELECT * FROM vehicle_expenses WHERE vehicle_id=?' . ($from !== null ? ' AND occurred_at>=?' : '') . ' ORDER BY occurred_at DESC, id DESC LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset)
        );
        $params = [$vehicleId]; if ($from !== null) { $params[] = substr($from, 0, 10); }
        $query->execute($params);

        return $query->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function reminders(int $vehicleId, ?string $from = null, int $limit = 20, int $offset = 0): array
    {
        $query = $this->pdo->prepare(
            'SELECT * FROM vehicle_reminders
             WHERE vehicle_id=?' . ($from !== null ? ' AND created_at>=?' : '') . '
             ORDER BY completed_at IS NOT NULL, due_date IS NULL, due_date, due_odometer_km, id DESC
             LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset)
        );
        $params = [$vehicleId]; if ($from !== null) { $params[] = $from; }
        $query->execute($params);

        return $query->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function addTripBookEntry(int $vehicleId, array $data): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO vehicle_trip_book_entries
                (vehicle_id, source_trip_id, started_at, ended_at, start_address, end_address, distance_km,
                 start_odometer_km, end_odometer_km, classification, purpose, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $query->execute([
            $vehicleId, $data['source_trip_id'], $data['started_at'], $data['ended_at'], $data['start_address'],
            $data['end_address'], $data['distance_km'], $data['start_odometer_km'], $data['end_odometer_km'],
            $data['classification'], $data['purpose'], $data['note'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function updateTripBookEntry(int $vehicleId, int $entryId, array $data): void
    {
        $query = $this->pdo->prepare(
            'UPDATE vehicle_trip_book_entries
             SET source_trip_id=?, started_at=?, ended_at=?, start_address=?, end_address=?, distance_km=?,
                 start_odometer_km=?, end_odometer_km=?, classification=?, purpose=?, note=?
             WHERE id=? AND vehicle_id=?'
        );
        $query->execute([
            $data['source_trip_id'], $data['started_at'], $data['ended_at'], $data['start_address'],
            $data['end_address'], $data['distance_km'], $data['start_odometer_km'], $data['end_odometer_km'],
            $data['classification'], $data['purpose'], $data['note'], $entryId, $vehicleId,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function tripBookEntries(int $vehicleId, int $limit = 20, ?string $from = null, int $offset = 0): array
    {
        $query = $this->pdo->prepare(
            'SELECT b.*, t.source_format
             FROM vehicle_trip_book_entries b
             LEFT JOIN trips t ON t.id=b.source_trip_id
             WHERE b.vehicle_id=?' . ($from !== null ? ' AND b.started_at>=?' : '') . '
             ORDER BY b.started_at DESC, b.id DESC
             LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset)
        );
        $params = [$vehicleId]; if ($from !== null) { $params[] = $from; }
        $query->execute($params);
        return $query->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function importedTrip(int $vehicleId, int $tripId, ?string $from = null): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT id, started_at, ended_at, start_address, end_address, distance_km, start_odometer_km,
                    end_odometer_km, classification, trip_note
             FROM trips WHERE vehicle_id=? AND id=?' . ($from !== null ? ' AND started_at>=?' : '') . ' LIMIT 1'
        );
        $params = [$vehicleId, $tripId]; if ($from !== null) { $params[] = $from; }
        $query->execute($params);
        $row = $query->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function tripLog(int $vehicleId, int $limit = 100, ?string $from = null, int $offset = 0): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, started_at, ended_at, start_address, end_address, distance_km, start_odometer_km,
                    end_odometer_km, classification, trip_note
             FROM trips
             WHERE vehicle_id=?' . ($from !== null ? ' AND started_at>=?' : '') . '
             ORDER BY started_at DESC
             LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset)
        );
        $params = [$vehicleId]; if ($from !== null) { $params[] = $from; }
        $query->execute($params);

        return $query->fetchAll();
    }

    public function energyEntryCount(int $vehicleId, ?string $from = null): int
    {
        return $this->countRows('vehicle_energy_entries', 'occurred_at', $vehicleId, $from);
    }

    public function serviceRecordCount(int $vehicleId, ?string $from = null): int
    {
        return $this->countRows('vehicle_service_records', 'serviced_at', $vehicleId, $from);
    }

    public function expenseCount(int $vehicleId, ?string $from = null): int
    {
        return $this->countRows('vehicle_expenses', 'occurred_at', $vehicleId, $from);
    }

    public function reminderCount(int $vehicleId, ?string $from = null): int
    {
        return $this->countRows('vehicle_reminders', 'created_at', $vehicleId, $from);
    }

    public function tripBookCount(int $vehicleId, ?string $from = null): int
    {
        return $this->countRows('vehicle_trip_book_entries', 'started_at', $vehicleId, $from);
    }

    private function countRows(string $table, string $dateColumn, int $vehicleId, ?string $from): int
    {
        $allowed = [
            'vehicle_energy_entries' => 'occurred_at',
            'vehicle_service_records' => 'serviced_at',
            'vehicle_expenses' => 'occurred_at',
            'vehicle_reminders' => 'created_at',
            'vehicle_trip_book_entries' => 'started_at',
        ];
        if (!isset($allowed[$table]) || $allowed[$table] !== $dateColumn) {
            return 0;
        }
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE vehicle_id=?';
        $params = [$vehicleId];
        if ($from !== null) {
            $sql .= ' AND ' . $dateColumn . '>=?';
            $params[] = in_array($dateColumn, ['serviced_at', 'occurred_at'], true) && $table !== 'vehicle_energy_entries'
                ? substr($from, 0, 10)
                : $from;
        }
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        return (int)$query->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function attachment(int $attachmentId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT a.*, s.vehicle_id, s.serviced_at
             FROM vehicle_service_attachments a
             JOIN vehicle_service_records s ON s.id=a.service_record_id
             WHERE a.id=?'
        );
        $query->execute([$attachmentId]);
        $row = $query->fetch();

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function attachmentsForService(int $serviceRecordId): array
    {
        $query = $this->pdo->prepare(
            'SELECT * FROM vehicle_service_attachments WHERE service_record_id=? ORDER BY id'
        );
        $query->execute([$serviceRecordId]);

        return $query->fetchAll();
    }

    /** @return array<string,float> */
    public function costSummary(int $vehicleId): array
    {
        $query = $this->pdo->prepare(
            'SELECT
                (SELECT COALESCE(SUM(total_price),0) FROM vehicle_energy_entries WHERE vehicle_id=?) energy_cost,
                (SELECT COALESCE(SUM(cost),0) FROM vehicle_service_records WHERE vehicle_id=?) service_cost,
                (SELECT COALESCE(SUM(amount),0) FROM vehicle_expenses WHERE vehicle_id=?) other_cost,
                (SELECT COALESCE(SUM(distance_km),0) FROM trips WHERE vehicle_id=?) distance_km'
        );
        $query->execute([$vehicleId, $vehicleId, $vehicleId, $vehicleId]);
        $row = $query->fetch() ?: [];
        $energy = (float)($row['energy_cost'] ?? 0);
        $service = (float)($row['service_cost'] ?? 0);
        $other = (float)($row['other_cost'] ?? 0);
        $distance = (float)($row['distance_km'] ?? 0);
        $total = $energy + $service + $other;

        return [
            'energy_cost' => $energy,
            'service_cost' => $service,
            'other_cost' => $other,
            'total_cost' => $total,
            'distance_km' => $distance,
            'cost_per_km' => $distance > 0 ? $total / $distance : 0.0,
        ];
    }
}
