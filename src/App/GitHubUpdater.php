<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Downloads, validates and deploys application updates from GitHub.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class GitHubUpdater
{
    /** @var Config */
    private $config;
    /** @var string */
    private $root;
    /** @var MigrationManager */
    private $migrations;

    public function __construct(Config $config, string $root, MigrationManager $migrations)
    {
        $this->config = $config;
        $this->root = rtrim($root, '/\\');
        $this->migrations = $migrations;
    }

    public function channel(): string
    {
        $channel = strtolower(trim((string)$this->config->get('update.channel', 'release')));
        if (!in_array($channel, ['release', 'dev'], true)) {
            throw new RuntimeException('UPDATE_CHANNEL musí být "release" nebo "dev".');
        }
        return $channel;
    }
    /** @return array<string,mixed> */
    public function remoteInfo(): array
    {
        $repo = $this->repository();
        if ($this->channel() === 'release') {
            $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
            $data = $this->downloadJson($url);
            if (empty($data['tag_name'])) {
                throw new RuntimeException('GitHub nevrátil informace o posledním releasu. Je v repozitáři publikovaný Release?');
            }
            return [
                'channel' => 'release',
                'version' => (string)$data['tag_name'],
                'tag' => (string)$data['tag_name'],
                'name' => isset($data['name'])?(string)$data['name']:(string)$data['tag_name'],
                'date' => isset($data['published_at'])?(string)$data['published_at']:null,
                'message' => isset($data['body'])?trim((string)$data['body']): null,
                'zip_url' => 'https://codeload.github.com/' . $repo . '/zip/refs/tags/' . rawurlencode((string)$data['tag_name']),
            ];
        }
        $branch = $this->branch();
        $data = $this->downloadJson('https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($branch));
        if (empty($data['sha'])) {
            throw new RuntimeException('GitHub nevrátil informace o posledním dev commitu.');
        }
        $sha = (string)$data['sha'];
        return [
            'channel' => 'dev',
            'version' => 'dev@' . substr($sha, 0, 12),
            'sha' => $sha,
            'short_sha' => substr($sha, 0, 12),
            'branch' => $branch,
            'date' => isset($data['commit']['committer']['date'])?(string)$data['commit']['committer']['date']:null,
            'message' => isset($data['commit']['message'])?trim((string)$data['commit']['message']): null,
            'zip_url' => 'https://codeload.github.com/' . $repo . '/zip/refs/heads/' . str_replace('%2F', '/', rawurlencode($branch)),
        ];
    }
    /** @return array<string,mixed> */
    public function update(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Na serveru chybí PHP rozšíření ZipArchive, které je pro aktualizaci potřeba.');
        }
        $repo = $this->repository();
        $remote = $this->remoteInfo();
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
        $zipFile = $runDir . '/package.zip';
        $lock = fopen($workBase . '/update.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Právě probíhá jiná aktualizace. Zkuste to později.');
        }
        try {
            $this->downloadFile((string)$remote['zip_url'], $zipFile);
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
            foreach (['public', 'src', 'sql', 'templates'] as $required) {
                if (!is_dir($packageRoot . '/' . $required)) {
                    throw new RuntimeException('Balíček z GitHubu neobsahuje očekávaný adresář ' . $required . '.');
                }
            }
            $this->backupManagedFiles($backupDir);
            $appliedMigrations = $this->migrations->migrate($packageRoot . '/sql');
            $this->deployManagedFiles($packageRoot);
            $installed = [
                'version' => (string)$remote['version'],
                'channel' => (string)$remote['channel'],
                'repository' => $repo,
                'installed_at' => date(DATE_ATOM),
            ];
            if (!empty($remote['tag'])) {
                $installed['tag'] = $remote['tag'];
            }
            if (!empty($remote['sha'])) {
                $installed['sha'] = $remote['sha'];
            }
            if (!empty($remote['branch'])) {
                $installed['branch'] = $remote['branch'];
            }
            @file_put_contents($workBase . '/installed-version.json', json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            @file_put_contents($workBase . '/last-update.json', json_encode($installed + ['migrations' => $appliedMigrations], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return [
                'backup' => $backupDir,
                'migrations' => $appliedMigrations,
                'repository' => $repo,
                'version' => $remote['version'],
                'channel' => $remote['channel']
            ];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function repository(): string
    {
        $repo = trim((string)$this->config->get('update.repository', 'filipekp/ev_dashboard'));
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new RuntimeException('Neplatná hodnota UPDATE_REPOSITORY.');
        }
        return $repo;
    }

    private function branch(): string
    {
        $branch = trim((string)$this->config->get('update.branch', 'dev'));
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
            throw new RuntimeException('Neplatná hodnota UPDATE_BRANCH.');
        }
        return $branch;
    }
    /** @return array<string,mixed> */
    private function downloadJson(string $url): array
    {
        $data = json_decode($this->downloadString($url, ['Accept: application/vnd.github+json']), true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub vrátil neplatnou odpověď.');
        }
        return $data;
    }

    private function downloadString(string $url, array $extraHeaders = []): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headers = array_merge(['User-Agent: EV-Stats-Updater'], $extraHeaders);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $headers
            ]);
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
            throw new RuntimeException('Nelze uložit staženou aktualizaci.');
        }
    }

    private function locatePackageRoot(string $stageDir): string
    {
        $items = array_values(array_filter(scandir($stageDir)?: [], static function (string $i): bool
        {
            return $i !== '.' && $i !== '..';
        }
        ));
        return count($items) === 1 && is_dir($stageDir . '/' . $items[0])?$stageDir . '/' . $items[0]:$stageDir;
    }

    private function backupManagedFiles(string $backupDir): void
    {
        foreach (['public', 'src', 'sql', 'templates'] as $dir) {
            if (is_dir($this->root . '/' . $dir)) {
                $this->copyTree($this->root . '/' . $dir, $backupDir . '/' . $dir);
            }
        }
        foreach (['.htaccess', '.env.example', '.gitignore', 'README.md', 'VERSION'] as $file) {
            if (is_file($this->root . '/' . $file)) {
                @copy($this->root . '/' . $file, $backupDir . '/' . $file);
            }
        }
    }

    private function deployManagedFiles(string $packageRoot): void
    {
        foreach (['public', 'src', 'sql', 'templates'] as $dir) {
            $this->syncTree($packageRoot . '/' . $dir, $this->root . '/' . $dir);
        }
        foreach (['.htaccess', '.env.example', '.gitignore', 'README.md', 'VERSION'] as $file) {
            if (is_file($packageRoot . '/' . $file) && !@copy($packageRoot . '/' . $file, $this->root . '/' . $file)) {
                throw new RuntimeException('Nelze aktualizovat soubor ' . $file . '.');
            }
        }
    }

    private function syncTree(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $items = scandir($target);
        if ($items === false) {
            throw new RuntimeException('Nelze číst cílový adresář.');
        }
        foreach ($items as $item) {
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
            foreach (scandir($path)?: [] as $i) {
                if ($i !== '.' && $i !== '..') {
                    $this->removeTree($path . '/' . $i);
                }
            }
            if (!@rmdir($path)) {
                throw new RuntimeException('Nelze odstranit ' . $path . '.');
            }
            return;
        }
        if (file_exists($path) && !@unlink($path)) {
            throw new RuntimeException('Nelze odstranit ' . $path . '.');
        }
    }

    private function copyTree(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $items = scandir($source);
        if ($items === false) {
            throw new RuntimeException('Nelze číst ' . $source . '.');
        }
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $src = $source . '/' . $i;
            $dst = $target . '/' . $i;
            if (is_dir($src)) {
                $this->copyTree($src, $dst);
            }
            elseif (!@copy($src, $dst))throw new RuntimeException('Nelze zapsat ' . $dst . '.');
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit adresář ' . $dir . '.');
        }
    }
}
