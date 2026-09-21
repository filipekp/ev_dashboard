<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Pagination;
use Throwable;

/**
 * Obsluhuje stránku aktualizací aplikace a databázových migrací.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class UpdateController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $me = $this->app->auth()->requireAdmin();
        $migrations = $this->app->migrations();
        $updater = $this->app->updater();
        $message = null;
        $installedVersion = $this->app->version()->info();
        $channel = $updater->channel();
        $error = null;
        $remote = null;
        $changelog = [];

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->app->session()->verifyCsrf();
                $action = (string)($_POST['action'] ?? '');

                if ($action === 'migrate') {
                    $applied = $migrations->migrate();
                    $message = $applied
                        ? 'Migrace dokončeny: ' . implode(', ', $applied)
                        : 'Databáze je aktuální, nebyla potřeba žádná migrace.';
                } elseif ($action === 'update') {
                    $result = $updater->update();
                    $applied = $result['migrations'] ?? [];
                    $message = 'Aktualizace z GitHubu na verzi '
                        . (string)($result['version'] ?? '')
                        . ' byla dokončena.'
                        . ($applied
                            ? ' Provedené migrace: ' . implode(', ', $applied) . '.'
                            : ' Databáze nevyžadovala novou migraci.');

                    // Po úspěšném update čteme metadata znovu, aby stránka ihned ukázala nový stav.
                    $installedVersion = $this->app->version()->info();
                }
            }

            try {
                $remote = $updater->remoteInfo();
            } catch (Throwable $e) {
                $remote = null;
            }

            try {
                $changelog = $updater->changelogSince($installedVersion);
            } catch (Throwable $e) {
                $changelog = [];
            }

            $migrationStatus = $migrations->status();
        } catch (Throwable $e) {
            $error = $e->getMessage();
            try {
                $migrationStatus = $migrations->status();
            } catch (Throwable $ignored) {
                $migrationStatus = [];
            }
        }

        $changelogPage = Pagination::slice($changelog, 'changes_page');
        $migrationPage = Pagination::slice($migrationStatus, 'migrations_page');

        $this->app->template()->render('update', [
            'app' => $this->app,
            'me' => $me,
            'message' => $message,
            'installedVersion' => $installedVersion,
            'channel' => $channel,
            'error' => $error,
            'remote' => $remote,
            'changelog' => $changelogPage['items'],
            'changelogPagination' => $changelogPage['pagination'],
            'migrationStatus' => $migrationPage['items'],
            'migrationPagination' => $migrationPage['pagination'],
        ]);
    }
}
