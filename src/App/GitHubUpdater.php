<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Zajišťuje zjištění dostupné verze, changelogu a bezpečné nasazení aktualizace z GitHubu.
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
                throw new RuntimeException(
                    'GitHub nevrátil informace o posledním releasu. Je v repozitáři publikovaný Release?'
                );
            }

            return [
                'channel' => 'release',
                'version' => (string)$data['tag_name'],
                'tag' => (string)$data['tag_name'],
                'name' => isset($data['name']) ? (string)$data['name'] : (string)$data['tag_name'],
                'date' => isset($data['published_at']) ? (string)$data['published_at'] : null,
                'message' => isset($data['body']) ? trim((string)$data['body']) : null,
                'zip_url' => 'https://codeload.github.com/' . $repo
                    . '/zip/refs/tags/' . rawurlencode((string)$data['tag_name']),
            ];
        }

        $branch = $this->branch();
        $data = $this->downloadJson(
            'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($branch)
        );
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
            'date' => isset($data['commit']['committer']['date'])
                ? (string)$data['commit']['committer']['date']
                : null,
            'message' => isset($data['commit']['message']) ? trim((string)$data['commit']['message']) : null,
            'zip_url' => 'https://codeload.github.com/' . $repo
                . '/zip/refs/heads/' . str_replace('%2F', '/', rawurlencode($branch)),
        ];
    }

    /**
     * Vrátí všechny release notes nebo dev commity od aktuálně nainstalované verze.
     *
     * @param array<string,mixed> $installedVersion
     * @return array<int,array<string,mixed>>
     */
    public function changelogSince(array $installedVersion): array
    {
        if ($this->channel() === 'dev') {
            return $this->devChangelogSince($installedVersion);
        }

        return $this->releaseChangelogSince((string)($installedVersion['version'] ?? ''));
    }

    /** @return array<string,mixed> */
    public function update(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Na serveru chybí PHP rozšíření ZipArchive, které je pro aktualizaci potřeba.'
            );
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
            $this->validateUpdateArchive($zip);
            if (!$zip->extractTo($stageDir)) {
                $zip->close();
                throw new RuntimeException('Stažený ZIP nelze rozbalit.');
            }
            $zip->close();

            $packageRoot = $this->locatePackageRoot($stageDir);
            foreach (['public', 'src', 'sql', 'templates'] as $required) {
                if (!is_dir($packageRoot . '/' . $required)) {
                    throw new RuntimeException(
                        'Balíček z GitHubu neobsahuje očekávaný adresář ' . $required . '.'
                    );
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

            @file_put_contents(
                $workBase . '/installed-version.json',
                json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            @file_put_contents(
                $workBase . '/last-update.json',
                json_encode(
                    $installed + ['migrations' => $appliedMigrations],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                )
            );

            return [
                'backup' => $backupDir,
                'migrations' => $appliedMigrations,
                'repository' => $repo,
                'version' => $remote['version'],
                'channel' => $remote['channel'],
            ];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function releaseChangelogSince(string $installedVersion): array
    {
        $installedComparable = $this->comparableVersion($installedVersion);
        if ($installedComparable === null) {
            return $this->latestReleaseAsChangelog();
        }

        $repo = $this->repository();
        $items = [];

        // GitHub vrací releasy od nejnovějšího. Několik stránek pokryje i dlouhou historii
        // bez toho, aby updater při každém otevření stahoval neomezené množství dat.
        for ($page = 1; $page <= 5; $page++) {
            $releases = $this->downloadJsonList(
                'https://api.github.com/repos/' . $repo . '/releases?per_page=100&page=' . $page
            );
            if (!$releases) {
                break;
            }

            foreach ($releases as $release) {
                if (!empty($release['draft']) || empty($release['tag_name'])) {
                    continue;
                }

                $tag = (string)$release['tag_name'];
                $candidate = $this->comparableVersion($tag);
                if ($candidate === null) {
                    continue;
                }

                if (version_compare($candidate, $installedComparable, '<=')) {
                    return $items;
                }

                $items[] = $this->releaseToChangelogItem($release);
            }

            if (count($releases) < 100) {
                break;
            }
        }

        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function latestReleaseAsChangelog(): array
    {
        $remote = $this->remoteInfo();

        return [[
            'type' => 'release',
            'version' => (string)$remote['version'],
            'name' => (string)($remote['name'] ?? $remote['version']),
            'date' => $remote['date'] ?? null,
            'body' => (string)($remote['message'] ?? ''),
            'url' => null,
            'prerelease' => false,
        ]];
    }

    /**
     * @param array<string,mixed> $installedVersion
     * @return array<int,array<string,mixed>>
     */
    private function devChangelogSince(array $installedVersion): array
    {
        $baseSha = trim((string)($installedVersion['sha'] ?? ''));
        if ($baseSha === '' || !preg_match('/^[a-f0-9]{7,40}$/i', $baseSha)) {
            $remote = $this->remoteInfo();

            return [[
                'type' => 'commit',
                'version' => (string)$remote['version'],
                'name' => (string)$remote['version'],
                'date' => $remote['date'] ?? null,
                'body' => (string)($remote['message'] ?? ''),
                'url' => null,
            ]];
        }

        $repo = $this->repository();
        $branch = $this->branch();
        $compare = $this->downloadJson(
            'https://api.github.com/repos/' . $repo . '/compare/'
            . rawurlencode($baseSha) . '...' . rawurlencode($branch)
        );
        $commits = isset($compare['commits']) && is_array($compare['commits'])
            ? $compare['commits']
            : [];
        $items = [];

        // Compare endpoint vrací commity od nejstaršího, UI changelogu je přehlednější od nejnovějšího.
        foreach (array_reverse($commits) as $commit) {
            if (!is_array($commit) || empty($commit['sha'])) {
                continue;
            }

            $message = isset($commit['commit']['message'])
                ? trim((string)$commit['commit']['message'])
                : '';
            $items[] = [
                'type' => 'commit',
                'version' => substr((string)$commit['sha'], 0, 12),
                'name' => strtok($message, "\n") ?: substr((string)$commit['sha'], 0, 12),
                'date' => isset($commit['commit']['committer']['date'])
                    ? (string)$commit['commit']['committer']['date']
                    : null,
                'body' => $message,
                'url' => isset($commit['html_url']) ? (string)$commit['html_url'] : null,
            ];
        }

        return $items;
    }

    /** @param array<string,mixed> $release */
    private function releaseToChangelogItem(array $release): array
    {
        $tag = (string)$release['tag_name'];

        return [
            'type' => 'release',
            'version' => $tag,
            'name' => isset($release['name']) && trim((string)$release['name']) !== ''
                ? (string)$release['name']
                : $tag,
            'date' => isset($release['published_at']) ? (string)$release['published_at'] : null,
            'body' => isset($release['body']) ? trim((string)$release['body']) : '',
            'url' => isset($release['html_url']) ? (string)$release['html_url'] : null,
            'prerelease' => !empty($release['prerelease']),
        ];
    }

    private function comparableVersion(string $version): ?string
    {
        $version = ltrim(trim($version), "vV \t\n\r\0\x0B");
        if ($version === '') {
            return null;
        }

        if (!preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            return null;
        }

        return $version;
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
        $data = json_decode(
            $this->downloadString($url, ['Accept: application/vnd.github+json']),
            true
        );
        if (!is_array($data)) {
            throw new RuntimeException('GitHub vrátil neplatnou odpověď.');
        }

        return $data;
    }

    /** @return array<int,array<string,mixed>> */
    private function downloadJsonList(string $url): array
    {
        $data = json_decode(
            $this->downloadString($url, ['Accept: application/vnd.github+json']),
            true
        );
        if (!is_array($data)) {
            throw new RuntimeException('GitHub vrátil neplatnou odpověď.');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /** @param array<int,string> $extraHeaders */
    private function downloadString(string $url, array $extraHeaders = []): string
    {
        $this->assertAllowedRemoteUrl($url);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headers = array_merge(['User-Agent: EV-Stats-Updater'], $extraHeaders);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $error = curl_error($ch);
            curl_close($ch);

            if ($effectiveUrl !== '') {
                $this->assertAllowedRemoteUrl($effectiveUrl);
            }

            if (!is_string($body) || $status < 200 || $status >= 300) {
                throw new RuntimeException(
                    'Stažení z GitHubu selhalo (HTTP ' . $status . '). ' . $error
                );
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'follow_location' => 1,
                'header' => implode(
                    "\r\n",
                    array_merge(['User-Agent: EV-Stats-Updater'], $extraHeaders)
                ),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException(
                'Stažení z GitHubu selhalo. Na serveru není cURL nebo je blokovaný HTTPS přístup.'
            );
        }

        return $body;
    }

    private function assertAllowedRemoteUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $allowedHosts = [
            'api.github.com',
            'codeload.github.com',
            'github.com',
            'objects.githubusercontent.com',
        ];

        if ($scheme !== 'https' || !in_array($host, $allowedHosts, true)) {
            throw new RuntimeException('Updater odmítl nedůvěryhodnou URL.');
        }
    }

    private function validateUpdateArchive(ZipArchive $zip): void
    {
        $maxFiles = 10000;
        $maxTotalUncompressed = 250 * 1024 * 1024;
        $totalUncompressed = 0;

        if ($zip->numFiles <= 0 || $zip->numFiles > $maxFiles) {
            throw new RuntimeException('Aktualizační ZIP má neplatný počet souborů.');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new RuntimeException('Aktualizační ZIP obsahuje nečitelnou položku.');
            }

            $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
            if (
                $name === ''
                || strpos($name, "\0") !== false
                || $name[0] === '/'
                || preg_match('#(^|/)\.\.(?:/|$)#', $name)
                || preg_match('/^[A-Za-z]:\//', $name)
            ) {
                throw new RuntimeException('Aktualizační ZIP obsahuje nebezpečnou cestu.');
            }

            $size = (int)($stat['size'] ?? 0);
            if ($size < 0 || $size > 50 * 1024 * 1024) {
                throw new RuntimeException('Aktualizační ZIP obsahuje příliš velký soubor.');
            }
            $totalUncompressed += $size;
            if ($totalUncompressed > $maxTotalUncompressed) {
                throw new RuntimeException('Aktualizační ZIP překračuje bezpečnostní limit velikosti.');
            }

            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    $mode = ($attributes >> 16) & 0xF000;
                    if ($mode === 0xA000) {
                        throw new RuntimeException('Aktualizační ZIP obsahuje symbolický odkaz.');
                    }
                }
            }
        }
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
        $items = array_values(array_filter(
            scandir($stageDir) ?: [],
            static function (string $item): bool {
                return $item !== '.' && $item !== '..';
            }
        ));

        return count($items) === 1 && is_dir($stageDir . '/' . $items[0])
            ? $stageDir . '/' . $items[0]
            : $stageDir;
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
            if (
                is_file($packageRoot . '/' . $file)
                && !@copy($packageRoot . '/' . $file, $this->root . '/' . $file)
            ) {
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
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->removeTree($path . '/' . $item);
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

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $src = $source . '/' . $item;
            $dst = $target . '/' . $item;
            if (is_dir($src)) {
                $this->copyTree($src, $dst);
            } elseif (!@copy($src, $dst)) {
                throw new RuntimeException('Nelze zapsat ' . $dst . '.');
            }
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit adresář ' . $dir . '.');
        }
    }
}
