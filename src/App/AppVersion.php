<?php

declare(strict_types=1);

namespace App;
/**
 * Reads metadata about the currently installed application version.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class AppVersion
{
    /** @var string */
    private $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }
    /** @return array<string,mixed> */
    public function info(): array
    {
        $file = $this->root . '/.updates/installed-version.json';
        if (is_file($file)) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && !empty($data['version'])) {
                return $data;
            }
        }
        $versionFile = $this->root . '/VERSION';
        $version = is_file($versionFile)?trim((string)@file_get_contents($versionFile)): 'local';
        return ['version' => $version !== ''?$version:'local', 'channel' => 'local'];
    }

    public function label(): string
    {
        $info = $this->info();
        return (string)($info['version'] ?? 'local');
    }
}
