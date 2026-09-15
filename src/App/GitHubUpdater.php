<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;
use ZipArchive;

final class GitHubUpdater
{
    /** @var Config */ private $config;
    /** @var string */ private $root;
    /** @var MigrationManager */ private $migrations;

    public function __construct(Config $config, string $root, MigrationManager $migrations)
    {
        $this->config = $config;
        $this->root = rtrim($root, '/\\');
        $this->migrations = $migrations;
    }

    /** @return array<string,string|null> */
    public function remoteInfo(): array
    {
        $repo = (string)$this->config->get('update.repository', 'filipekp/ev_dashboard');
        $branch = (string)$this->config->get('update.branch', 'dev');
        $url = 'https://api.github.com/repos/' . rawurlencode(explode('/', $repo)[0]) . '/' . rawurlencode(explode('/', $repo)[1] ?? '') . '/commits/' . rawurlencode($branch);
        $json = $this->downloadString($url, ['Accept: application/vnd.github+json']);
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['sha'])) {
            throw new RuntimeException('GitHub nevrátil informace o posledním commitu.');
        }
        return [
            'sha' => (string)$data['sha'],
            'short_sha' => substr((string)$data['sha'], 0, 12),
            'message' => isset($data['commit']['message']) ? trim((string)$data['commit']['message']) : null,
            'date' => isset($data['commit']['committer']['date']) ? (string)$data['commit']['committer']['date'] : null,
        ];
    }

    /** @return array<string,mixed> */
    public function update(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Na serveru chybí PHP rozšíření ZipArchive, které je pro aktualizaci potřeba.');
        }
        $repo = (string)$this->config->get('update.repository', 'filipekp/ev_dashboard');
        $branch = (string)$this->config->get('update.branch', 'dev');
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new RuntimeException('Neplatná hodnota UPDATE_REPOSITORY.');
        }
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
            throw new RuntimeException('Neplatná hodnota UPDATE_BRANCH.');
        }

        $workBase = $this->root . '/.updates';
        $this->ensureDirectory($workBase);
        if (!is_file($workBase . '/.htaccess')) {
            @file_put_contents($workBase . '/.htaccess', "Require all denied\n");
        }
        $runId = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $runDir = $workBase . '/' . $runId;
        $stageDir = $runDir . '/stage';
        $backupDir = $runDir . '/backup';
        $this->ensureDirectory($stageDir);
        $this->ensureDirectory($backupDir);
        $zipFile = $runDir . '/release.zip';
        $lockFile = $workBase . '/update.lock';
        $lock = fopen($lockFile, 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Právě probíhá jiná aktualizace. Zkuste to později.');
        }

        try {
            $zipUrl = 'https://codeload.github.com/' . $repo . '/zip/refs/heads/' . str_replace('%2F', '/', rawurlencode($branch));
            $this->downloadFile($zipUrl, $zipFile);
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Stažený ZIP nelze otevřít.');
            }
            if (!$zip->extractTo($stageDir)) {
                $zip->close();
                throw new RuntimeException('Stažený ZIP nelze rozbalit.');
            }
            $zip->close();

            $packageRoot = $this->locatePackageRoot($stageDir);
            foreach (['public', 'src', 'sql'] as $required) {
                if (!is_dir($packageRoot . '/' . $required)) {
                    throw new RuntimeException('Balíček z GitHubu neobsahuje očekávaný adresář ' . $required . '.');
                }
            }

            $this->backupManagedFiles($backupDir);
            $appliedMigrations = $this->migrations->migrate($packageRoot . '/sql');
            $this->deployManagedFiles($packageRoot);
            @file_put_contents($workBase . '/last-update.json', json_encode([
                'updated_at' => date(DATE_ATOM),
                'repository' => $repo,
                'branch' => $branch,
                'migrations' => $appliedMigrations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return [
                'backup' => $backupDir,
                'migrations' => $appliedMigrations,
                'repository' => $repo,
                'branch' => $branch,
            ];
        } catch (Throwable $e) {
            throw $e;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function downloadString(string $url, array $extraHeaders = []): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headers = array_merge(['User-Agent: EV-Stats-Updater'], $extraHeaders);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if (!is_string($body) || $status < 200 || $status >= 300) {
                throw new RuntimeException('Stažení z GitHubu selhalo (HTTP ' . $status . '). ' . $error);
            }
            return $body;
        }
        $context = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1, 'header' => implode("\r\n", array_merge(['User-Agent: EV-Stats-Updater'], $extraHeaders))]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Stažení z GitHubu selhalo. Na serveru není cURL nebo je blokovaný HTTPS přístup.');
        }
        return $body;
    }

    private function downloadFile(string $url, string $target): void
    {
        $body = $this->downloadString($url);
        if (@file_put_contents($target, $body) === false) {
            throw new RuntimeException('Nelze uložit staženou aktualizaci do ' . dirname($target) . '.');
        }
    }

    private function locatePackageRoot(string $stageDir): string
    {
        $items = array_values(array_filter(scandir($stageDir) ?: [], static function (string $item): bool { return $item !== '.' && $item !== '..'; }));
        if (count($items) === 1 && is_dir($stageDir . '/' . $items[0])) {
            return $stageDir . '/' . $items[0];
        }
        return $stageDir;
    }

    private function backupManagedFiles(string $backupDir): void
    {
        foreach (['public', 'src', 'sql'] as $dir) {
            if (is_dir($this->root . '/' . $dir)) {
                $this->copyTree($this->root . '/' . $dir, $backupDir . '/' . $dir);
            }
        }
        foreach (['.htaccess', '.env.example', '.gitignore', 'README.md'] as $file) {
            if (is_file($this->root . '/' . $file)) {
                @copy($this->root . '/' . $file, $backupDir . '/' . $file);
            }
        }
    }

    private function deployManagedFiles(string $packageRoot): void
    {
        foreach (['public', 'src', 'sql'] as $dir) {
            $this->syncTree($packageRoot . '/' . $dir, $this->root . '/' . $dir);
        }
        foreach (['.htaccess', '.env.example', '.gitignore', 'README.md'] as $file) {
            if (is_file($packageRoot . '/' . $file)) {
                if (!@copy($packageRoot . '/' . $file, $this->root . '/' . $file)) {
                    throw new RuntimeException('Nelze aktualizovat soubor ' . $file . '.');
                }
            }
        }
        // .env se úmyslně nikdy nepřepisuje.
    }


    private function syncTree(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $targetItems = scandir($target);
        if ($targetItems === false) {
            throw new RuntimeException('Nelze číst cílový adresář ' . $target . '.');
        }
        foreach ($targetItems as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (!file_exists($source . '/' . $item)) {
                $this->removeTree($target . '/' . $item);
            }
        }
        $this->copyTree($source, $target);
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $items = scandir($path);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item !== '.' && $item !== '..') {
                        $this->removeTree($path . '/' . $item);
                    }
                }
            }
            if (!@rmdir($path)) {
                throw new RuntimeException('Nelze odstranit starý adresář ' . $path . '.');
            }
            return;
        }
        if (file_exists($path) && !@unlink($path)) {
            throw new RuntimeException('Nelze odstranit starý soubor ' . $path . '.');
        }
    }

    private function copyTree(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $items = scandir($source);
        if ($items === false) {
            throw new RuntimeException('Nelze číst adresář ' . $source . '.');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $source . '/' . $item;
            $dst = $target . '/' . $item;
            if (is_dir($src)) {
                $this->copyTree($src, $dst);
            } elseif (!@copy($src, $dst)) {
                throw new RuntimeException('Nelze zapsat soubor ' . $dst . '.');
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Nelze vytvořit adresář ' . $directory . '.');
        }
    }
}
