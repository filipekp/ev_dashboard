#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config;
use App\Database;
use App\MigrationManager;
use App\Repository\TripRepository;
use App\Repository\VehicleDataRepository;
use App\Repository\VehicleOperationRepository;
use App\Service\VehicleTelemetryDerivedDataService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/App/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * @param string $message
 */
function out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * @param string $message
 */
function err(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

/**
 * Zpracuje jednorázovou frontu rekonstrukce telemetry jízd. Pokud v26 ještě
 * není aplikovaná, repository vrátí prázdnou frontu.
 *
 * @return array{repaired:int,failed:int}
 */
function repairTelemetryTripQueue(PDO $pdo): array
{
    $telemetryRepository = new VehicleDataRepository($pdo);
    $tripRepository = new TripRepository($pdo);
    $operationRepository = new VehicleOperationRepository($pdo);
    $derivedData = new VehicleTelemetryDerivedDataService(
        $pdo,
        $telemetryRepository,
        $tripRepository,
        $operationRepository
    );

    $repaired = 0;
    $failed = 0;
    foreach ($telemetryRepository->queuedTripRebuilds(500) as $queued) {
        try {
            $derivedData->rebuildTelemetryTrips(
                (int)$queued['vehicle_id'],
                (int)$queued['connection_id'],
                (string)$queued['provider']
            );
            $telemetryRepository->completeTripRebuild((int)$queued['connection_id']);
            $repaired++;
        } catch (Throwable $e) {
            $failed++;
            err(
                '  WARN konektor #' . (int)$queued['connection_id']
                . ': ' . $e->getMessage()
            );
        }
    }

    return ['repaired' => $repaired, 'failed' => $failed];
}

function printHelp(): void
{
    out('EV Stats - databázové migrace');
    out();
    out('Použití:');
    out('  php bin/migrate.php             Spustí všechny čekající migrace');
    out('  php bin/migrate.php --status    Zobrazí stav migrací bez změny databáze');
    out('  php bin/migrate.php --help      Zobrazí tuto nápovědu');
}

/**
 * @param array<int,array<string,mixed>> $status
 */
function printStatus(array $status): void
{
    if ($status === []) {
        out('Nebyly nalezeny žádné soubory migrate_*.sql.');
        return;
    }

    out(str_pad('Migrace', 28) . str_pad('Stav', 16) . 'Aplikováno');
    out(str_repeat('-', 72));

    foreach ($status as $row) {
        $applied = !empty($row['applied']);
        $changed = !empty($row['changed_after_apply']);

        if ($changed) {
            $label = 'ZMĚNĚNA!';
        } elseif ($applied) {
            $label = 'aplikována';
        } else {
            $label = 'čeká';
        }

        $appliedAt = isset($row['applied_at']) && $row['applied_at'] !== null
            ? (string)$row['applied_at']
            : '-';

        out(
            str_pad((string)$row['migration'], 28)
            . str_pad($label, 16)
            . $appliedAt
        );
    }
}

$options = array_slice($argv, 1);

if (in_array('--help', $options, true) || in_array('-h', $options, true)) {
    printHelp();
    exit(0);
}

$allowedOptions = ['--status', '--help', '-h'];
foreach ($options as $option) {
    if (!in_array($option, $allowedOptions, true)) {
        err('Neznámý parametr: ' . $option);
        printHelp();
        exit(64);
    }
}

$lockPath = sys_get_temp_dir() . '/evstats-migrations-' . sha1($root) . '.lock';
$lockHandle = fopen($lockPath, 'c');

if ($lockHandle === false) {
    err('[EV Stats] Nelze vytvořit zámek migrací: ' . $lockPath);
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    err('[EV Stats] Jiný proces právě spouští databázové migrace.');
    fclose($lockHandle);
    exit(75);
}

try {
    $configValues = require $root . '/src/config.php';
    $config = new Config($configValues);
    $database = new Database($config);
    $pdo = $database->pdo();

    $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($databaseName === '') {
        throw new RuntimeException('Není vybrána databáze. Zkontrolujte DB_NAME v .env.');
    }

    $migrations = new MigrationManager($pdo, $root . '/sql');
    $status = $migrations->status();

    out('EV Stats database migration runner');
    out('Databáze: ' . $databaseName);
    out('Adresář migrací: ' . $root . '/sql');
    out();

    printStatus($status);

    $changedMigrations = array_filter($status, static function (array $row): bool {
        return !empty($row['changed_after_apply']);
    });

    if ($changedMigrations !== []) {
        out();
        err('[EV Stats] STOP: některá již aplikovaná migrace byla změněna.');
        err('Nevracejte změny do starého SQL souboru; vytvořte novou migraci.');
        exit(2);
    }

    if (in_array('--status', $options, true)) {
        $pending = array_filter($status, static function (array $row): bool {
            return empty($row['applied']);
        });

        out();
        out('Čekající migrace: ' . count($pending));
        exit(0);
    }

    $pending = array_filter($status, static function (array $row): bool {
        return empty($row['applied']);
    });

    if ($pending === []) {
        $repair = repairTelemetryTripQueue($pdo);
        out();
        out('Databáze je aktuální. Není co migrovat.');
        if ($repair['repaired'] > 0 || $repair['failed'] > 0) {
            out('Opravené telemetry konektory: ' . $repair['repaired']);
            if ($repair['failed'] > 0) {
                out('Neopravené konektory: ' . $repair['failed'] . ' (další CRON je zkusí znovu)');
            }
        }
        exit(0);
    }

    out();
    out('Spouštím ' . count($pending) . ' čekajících migrací...');

    $applied = $migrations->migrate();

    if ($applied === []) {
        out('Nebyla aplikována žádná migrace.');
        exit(0);
    }

    foreach ($applied as $migration) {
        out('  OK  ' . $migration);
    }

    $repair = repairTelemetryTripQueue($pdo);
    if ($repair['repaired'] > 0 || $repair['failed'] > 0) {
        out();
        out('Opravuji telemetry jízdy z uložených snapshotů...');
        out('  Opravené konektory: ' . $repair['repaired']);
        if ($repair['failed'] > 0) {
            out('  Neopravené konektory: ' . $repair['failed'] . ' (další CRON je zkusí znovu)');
        }
    }

    out();
    out('Hotovo. Aplikováno migrací: ' . count($applied));
    exit(0);
} catch (Throwable $e) {
    err('');
    err('[EV Stats] Migrace selhala: ' . $e->getMessage());
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
