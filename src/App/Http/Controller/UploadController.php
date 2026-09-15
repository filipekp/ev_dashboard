<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Třída UploadController.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class UploadController
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csv'])) {
            Http::redirect('index.php');
        }

        $vehicleId = 0;
        try {
            $this->app->session()->verifyCsrf();
            $file = $_FILES['csv'];

            if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload souboru selhal.');
            }
            if ((int)$file['size'] > 20 * 1024 * 1024) {
                throw new RuntimeException('Soubor je příliš velký (max. 20 MB).');
            }

            $result = $this->app->importService()->importUploaded(
                $user,
                (string)$file['tmp_name'],
                (string)($file['name'] ?? ''),
                (int)($_POST['vehicle_id'] ?? 0)
            );

            $vehicleId = (int)$result['vehicle_id'];
            $_SESSION['vehicle_id'] = $vehicleId;
            $message = sprintf(
                'Import dokončen (%s): %d nových jízd, %d přeskočeno.',
                (string)$result['plugin_label'],
                (int)$result['inserted'],
                (int)$result['skipped']
            );

            if (!empty($result['new_vehicle'])) {
                $_SESSION['new_vehicle_id'] = $vehicleId;
                $this->app->session()->flash(
                    'Rozpoznáno nové vozidlo VIN ' . (string)$result['vin'] . '. ' .
                    $message . ' Doplňte údaje o vozidle.'
                );
                Http::redirect('index.php?vehicle_id=' . $vehicleId . '&new_vehicle=1');
            }

            $this->app->session()->flash($message);
        } catch (Throwable $e) {
            $this->app->session()->flash('Chyba importu: ' . $e->getMessage(), 'error');
        }

        Http::redirect('index.php' . ($vehicleId > 0 ? '?vehicle_id=' . $vehicleId : ''));
    }
}
