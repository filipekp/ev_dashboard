<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Přihlášení do veřejného read-only demo účtu.
 *
 * Demo účet nepoužívá heslo a je určen výhradně pro prohlížení předem
 * připravených dat. Veškeré zapisující HTTP požadavky blokuje bootstrap.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class DemoLoginController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        if ($this->app->auth()->currentUser()) {
            Http::redirect('index.php');
        }

        try {
            if (!(bool)$this->app->config()->get('demo.enabled', true)) {
                throw new RuntimeException('Demo režim je momentálně vypnutý.');
            }

            $email = strtolower(trim((string)$this->app->config()->get('demo.email', 'demo@evstats.local')));
            $query = $this->app->pdo()->prepare("SELECT id,active,role FROM users WHERE email=? AND role='user' LIMIT 1");
            $query->execute([$email]);
            $user = $query->fetch();

            if (!$user || !(int)$user['active']) {
                throw new RuntimeException('Demo účet zatím není připraven. Spusťte databázovou migraci v19.');
            }

            session_regenerate_id(true);
            $this->app->session()->rotateCsrf();
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['_last_regenerated'] = time();
            unset($_SESSION['vehicle_id']);
            Http::redirect('index.php');
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('index.php');
        }
    }
}
