<?php

declare(strict_types=1);

namespace App\Service;

use App\AuthService;
use App\Config;
use App\Integration\GenericCsvMapper;
use App\Integration\TabularFileReader;
use App\Repository\IntegrationImportRunRepository;
use App\Repository\TripRepository;
use App\Repository\UnknownImportRepository;
use App\Repository\VehicleRepository;
use App\UploadValidator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Zpracování neznámých tabulkových formátů a sběr vzorků pro nové pluginy.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class UnknownImportService
{
    /** @var PDO */
    private $pdo;

    /** @var AuthService */
    private $auth;

    /** @var Config */
    private $config;

    /** @var string */
    private $root;

    /** @var TabularFileReader */
    private $reader;

    /** @var GenericCsvMapper */
    private $mapper;

    /** @var UnknownImportRepository */
    private $samples;

    /** @var VehicleRepository */
    private $vehicles;

    /** @var TripRepository */
    private $trips;

    /** @var IntegrationImportRunRepository */
    private $runs;

    public function __construct(
        PDO $pdo,
        AuthService $auth,
        Config $config,
        string $root,
        UnknownImportRepository $samples,
        VehicleRepository $vehicles,
        TripRepository $trips,
        IntegrationImportRunRepository $runs
    ) {
        $this->pdo = $pdo;
        $this->auth = $auth;
        $this->config = $config;
        $this->root = $root;
        $this->samples = $samples;
        $this->vehicles = $vehicles;
        $this->trips = $trips;
        $this->runs = $runs;
        $this->reader = new TabularFileReader();
        $this->mapper = new GenericCsvMapper();
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function stage(
        array $user,
        int $vehicleId,
        string $tmpPath,
        string $originalName
    ): array {
        if (
            $vehicleId <= 0
            || !$this->auth->isVehicleAssignedToUser((int)$user['id'], $vehicleId)
        ) {
            throw new RuntimeException('Vybrané vozidlo není přiřazeno vašemu účtu.');
        }

        $vehicle = $this->vehicles->find($vehicleId);
        if ($vehicle === null) {
            throw new RuntimeException('Vozidlo nebylo nalezeno.');
        }

        $preview = $this->reader->read($tmpPath, $originalName, 5);
        if (empty($preview['headers'])) {
            throw new RuntimeException('V souboru nebyly nalezeny sloupce k namapování.');
        }

        $directory = $this->root . '/storage/import-samples';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Nelze vytvořit úložiště vzorků importu.');
        }

        $token = bin2hex(random_bytes(24));
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
            $extension = 'dat';
        }

        if (!is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Importní soubor není platný HTTP upload.');
        }

        $storedName = $token . '.' . $extension;
        $target = $directory . '/' . $storedName;
        if (!move_uploaded_file($tmpPath, $target)) {
            throw new RuntimeException('Nepodařilo se archivovat původní importovaný soubor.');
        }
        @chmod($target, 0640);

        $mimeType = function_exists('mime_content_type')
            ? (string)@mime_content_type($target)
            : 'application/octet-stream';

        $sampleId = $this->samples->create([
            'token' => $token,
            'user_id' => (int)$user['id'],
            'vehicle_id' => $vehicleId,
            'original_name' => UploadValidator::safeOriginalName($originalName, 'import.dat'),
            'stored_name' => $storedName,
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'file_size' => (int)filesize($target),
            'sha256' => hash_file('sha256', $target),
            'file_format' => $preview['format'],
            'headers_json' => json_encode($preview['headers'], JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'id' => $sampleId,
            'token' => $token,
            'vehicle' => $vehicle,
            'preview' => $preview,
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function detail(array $user, string $token): array
    {
        $sample = $this->samples->findByTokenForUser($token, (int)$user['id']);
        if ($sample === null) {
            throw new RuntimeException(
                'Importní vzorek nebyl nalezen nebo k němu nemáte přístup.'
            );
        }

        $preview = $this->reader->read(
            $this->samplePath($sample),
            (string)$sample['original_name'],
            5
        );
        $vehicle = $this->vehicles->find((int)$sample['vehicle_id']);
        if ($vehicle === null) {
            throw new RuntimeException('Vozidlo již neexistuje.');
        }

        return [
            'sample' => $sample,
            'preview' => $preview,
            'vehicle' => $vehicle,
            'fields' => GenericCsvMapper::fieldLabels(),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function import(array $user, string $token, array $post): array
    {
        $detail = $this->detail($user, $token);
        $sample = $detail['sample'];
        $vehicle = $detail['vehicle'];
        $status = (string)$sample['status'];

        if (!in_array($status, ['pending_mapping', 'failed'], true)) {
            throw new RuntimeException('Tento vzorek už byl zpracován.');
        }

        $mapping = isset($post['mapping']) && is_array($post['mapping'])
            ? $post['mapping']
            : [];
        $mapping = array_filter(
            array_map('strval', $mapping),
            static function (string $value): bool {
                return trim($value) !== '';
            }
        );

        if (empty($mapping['started_at']) || empty($mapping['distance_km'])) {
            throw new RuntimeException(
                'Namapujte minimálně začátek jízdy a vzdálenost.'
            );
        }

        $dateFormat = trim((string)($post['date_format'] ?? 'auto'));
        $profile = [
            'mapping' => $mapping,
            'transforms' => [
                'started_at' => ['date_format' => $dateFormat],
                'ended_at' => ['date_format' => $dateFormat],
            ],
        ];

        $data = $this->reader->read(
            $this->samplePath($sample),
            (string)$sample['original_name']
        );
        $runId = $this->runs->start(
            (int)$user['id'],
            (int)$vehicle['id'],
            'generic_mapping',
            'Ruční mapování · ' . (string)$sample['original_name']
        );

        $inserted = 0;
        $skipped = 0;
        $seen = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($data['rows'] as $index => $row) {
                try {
                    $trip = $this->mapper->map($row, $profile);
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        'Řádek ' . ($index + 2) . ': ' . $e->getMessage(),
                        0,
                        $e
                    );
                }

                $seen++;
                if ($this->trips->insertIgnore((int)$vehicle['id'], $trip)) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }

            if ($seen === 0) {
                throw new RuntimeException('Soubor neobsahuje žádné datové řádky.');
            }

            $this->pdo->commit();
            $this->runs->completeSuccess($runId, $inserted, $skipped);
            $this->samples->markImported(
                (int)$sample['id'],
                $profile,
                $runId,
                $inserted,
                $skipped
            );

            if (!empty($post['save_profile'])) {
                $name = trim((string)($post['profile_name'] ?? ''));
                if ($name === '') {
                    $name = 'Mapování ' . (string)$sample['original_name'];
                }
                $profileName = function_exists('mb_substr')
                    ? mb_substr($name, 0, 190, 'UTF-8')
                    : substr($name, 0, 190);
                $this->samples->saveProfile((int)$user['id'], $profileName, $profile);
            }

            $this->notifyAdmin($user, $vehicle, $sample, $profile, $inserted, $skipped);

            return [
                'vehicle_id' => (int)$vehicle['id'],
                'inserted' => $inserted,
                'skipped' => $skipped,
                'plugin_label' => 'Univerzální ruční mapování',
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            try {
                $this->runs->completeFailure($runId, $e->getMessage());
            } catch (Throwable $ignore) {
                // Auditní chyba nesmí překrýt původní chybu importu.
            }
            try {
                $this->samples->markFailed((int)$sample['id'], $e->getMessage());
            } catch (Throwable $ignore) {
                // Evidence vzorku nesmí překrýt původní chybu importu.
            }

            throw $e;
        }
    }

    /** @param array<string,mixed> $sample */
    private function samplePath(array $sample): string
    {
        return $this->root
            . '/storage/import-samples/'
            . basename((string)$sample['stored_name']);
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $vehicle
     * @param array<string,mixed> $sample
     * @param array<string,mixed> $profile
     */
    private function notifyAdmin(
        array $user,
        array $vehicle,
        array $sample,
        array $profile,
        int $inserted,
        int $skipped
    ): void {
        $to = trim((string)$this->config->get('registration.admin_notify_email', ''));
        if ($to === '') {
            $adminId = (int)$this->config->get('registration.parent_admin_id', 1);
            $query = $this->pdo->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
            $query->execute([$adminId]);
            $to = trim((string)$query->fetchColumn());
        }

        $from = trim((string)$this->config->get('mail.from', ''));
        if ($to === '' || $from === '') {
            return;
        }

        $subject = 'Nový neznámý formát importu – '
            . (string)$this->config->get('app.name', 'EV Stats');
        $lines = [
            'Byl použit univerzální ruční mapper pro dosud nerozpoznaný importní formát.',
            '',
            'Uživatel: ' . (string)($user['name'] ?? '')
                . ' <' . (string)($user['email'] ?? '') . '>',
            'Soubor: ' . (string)$sample['original_name'],
            'Formát: ' . strtoupper((string)$sample['file_format']),
            'SHA-256: ' . (string)$sample['sha256'],
            'Uloženo jako: storage/import-samples/' . (string)$sample['stored_name'],
            'Importováno: ' . $inserted . ', přeskočeno: ' . $skipped,
            '',
            'Vozidlo:',
        ];

        foreach ($vehicle as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = '- ' . $key . ': ' . (
                is_scalar($value) ? (string)$value : (string)json_encode($value)
            );
        }

        $lines[] = '';
        $lines[] = 'Mapování:';
        foreach (($profile['mapping'] ?? []) as $target => $source) {
            $lines[] = '- ' . $target . ' <= ' . $source;
        }

        $headers = [
            'From: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : '=?UTF-8?B?' . base64_encode($subject) . '?=';

        @mail(
            $to,
            $encodedSubject,
            implode("\n", $lines),
            implode("\r\n", $headers)
        );
    }
}
