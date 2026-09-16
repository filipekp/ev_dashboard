<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use Throwable;

/**
 * Druhý krok importu pro tabulkové soubory bez nativního pluginu.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class GenericImportController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $user = $this->app->auth()->requireLogin();
        $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

        if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
            $this->app->session()->flash('Neplatný odkaz na univerzální import.', 'error');
            Http::redirect('import.php');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->app->session()->verifyCsrf();
                $result = $this->app->unknownImportService()->import($user, $token, $_POST);
                $_SESSION['vehicle_id'] = (int)$result['vehicle_id'];

                $this->app->session()->flash(sprintf(
                    'Import dokončen (%s): %d nových jízd, %d přeskočeno.',
                    (string)$result['plugin_label'],
                    (int)$result['inserted'],
                    (int)$result['skipped']
                ));

                Http::redirect('index.php?vehicle_id=' . (int)$result['vehicle_id']);
            } catch (Throwable $e) {
                $this->app->session()->flash(
                    'Chyba univerzálního importu: ' . $e->getMessage(),
                    'error'
                );
            }
        }

        try {
            $detail = $this->app->unknownImportService()->detail($user, $token);
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('import.php');
            return;
        }

        $this->app->template()->render('generic-import', [
            'app' => $this->app,
            'user' => $user,
            'vehicle' => $detail['vehicle'],
            'sample' => $detail['sample'],
            'preview' => $detail['preview'],
            'fields' => $detail['fields'],
            'token' => $token,
            'flash' => $this->app->session()->pullFlash(),
        ]);
    }
}
