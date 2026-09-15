<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Discovers, validates and applies database migrations.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class MigrationManager
{
    /** @var PDO */
    private $pdo;
    /** @var string */
    private $migrationDir;

    public function __construct(PDO $pdo, string $migrationDir)
    {
        $this->pdo = $pdo;
        $this->migrationDir = rtrim($migrationDir, '/\\');
    }

    public function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL UNIQUE,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");
    }
    /** @return array<int,array<string,mixed>> */
    public function status(?string $directory = null): array
    {
        $this->ensureTable();
        $this->baselineLegacyMigrations();
        $applied = [];
        foreach ($this->pdo->query('SELECT migration,checksum,applied_at FROM schema_migrations ORDER BY migration')->fetchAll() as $row) {
            $applied[(string)$row['migration']] = $row;
        }
        $result = [];
        foreach ($this->files($directory) as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file)?:'';
            $row = $applied[$name] ?? null;
            $result[] = [
                'migration' => $name,
                'applied' => $row !== null,
                'checksum' => $checksum,
                'stored_checksum' => $row['checksum'] ?? null,
                'applied_at' => $row['applied_at'] ?? null,
                'changed_after_apply' => $row !== null && (string)$row['checksum'] !== $checksum,
            ];
        }
        return $result;
    }
    /** @return string[] names of applied migrations */
    public function migrate(?string $directory = null): array
    {
        $this->ensureTable();
        $this->baselineLegacyMigrations();
        $done = [];
        $check = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $insert = $this->pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');
        foreach ($this->files($directory) as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException('Nelze spočítat checksum migrace ' . $name . '.');
            }
            $check->execute([$name]);
            $stored = $check->fetchColumn();
            if ($stored !== false) {
                if (!hash_equals((string)$stored, $checksum)) {
                    throw new RuntimeException('Již aplikovaná migrace ' . $name . ' byla změněna. Vytvořte novou migraci místo úpravy staré.');
                }
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Nelze načíst migraci ' . $name . '.');
            }
            $statements = $this->splitStatements($sql);
            try {
                foreach ($statements as $statement) {
                    $this->pdo->exec($statement);
                }
                $insert->execute([$name, $checksum]);
                $done[] = $name;
            } catch (Throwable $e) {
                throw new RuntimeException('Migrace ' . $name . ' selhala: ' . $e->getMessage(), 0, $e);
            }
        }
        return $done;
    }
    /** @return string[] */
    private function files(?string $directory): array
    {
        $dir = rtrim($directory?:$this->migrationDir, '/\\');
        $files = glob($dir . '/migrate_*.sql')?: [];
        usort($files, static function (string $a, string $b): int
        {
            return strnatcasecmp(basename($a), basename($b));
        }
        );
        return $files;
    }
    /** @return string[] */
    private function splitStatements(string $sql): array
    {
        // Migrace v projektu neobsahují stored procedury; jednoduché dělení je proto záměrně čitelné.
        $sql = preg_replace('/^\s*USE\s+[^;]+;\s*$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql)?: [];
        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $result[] = $part;
            }
        }
        return $result;
    }

    private function baselineLegacyMigrations(): void
    {
        $known = [
            'migrate_v2.sql' => $this->legacyV2Present(),
            'migrate_v3.sql' => $this->legacyV3Present(),
            'migrate_v4.sql' => $this->columnExists('trips', 'source_format'),
            'migrate_v5.sql' => $this->columnExists('users', 'default_vehicle_id'),
        ];
        $exists = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration=?');
        $insert = $this->pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');
        foreach ($known as $name => $present) {
            if (!$present) {
                continue;
            }
            $exists->execute([$name]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $file = $this->migrationDir . '/' . $name;
            if (is_file($file)) {
                $checksum = hash_file('sha256', $file)?:hash('sha256', $name);
                $insert->execute([$name, $checksum]);
            }
        }
    }

    private function legacyV2Present(): bool
    {
        return $this->tableExists('users') && $this->tableExists('user_vehicles') && $this->columnExists('vehicles', 'home_label');
    }

    private function legacyV3Present(): bool
    {
        return $this->tableExists('password_reset_tokens') && $this->columnExists('vehicles', 'battery_nominal_kwh');
    }

    private function tableExists(string $table): bool
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return (int)$q->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $q->execute([$table, $column]);
        return (int)$q->fetchColumn() > 0;
    }
}
