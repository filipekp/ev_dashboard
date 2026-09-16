<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use App\Import\UnsupportedImportFormatException;
use App\UploadValidator;
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

            $uploadedPath = UploadValidator::uploadedPath($file, 20 * 1024 * 1024);
            $originalName = UploadValidator::safeOriginalName((string)($file['name'] ?? ''), 'import.dat');

            $result = $this->app->importService()->importUploaded(
                $user,
                $uploadedPath,
                $originalName,
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
        } catch (UnsupportedImportFormatException $e) {
            try {
                $file = $_FILES['csv'];
                $staged = $this->app->unknownImportService()->stage(
                    $user,
                    (int)($_POST['vehicle_id'] ?? 0),
                    $uploadedPath,
                    $originalName
                );
                Http::redirect('generic-import.php?token=' . rawurlencode((string)$staged['token']));
            } catch (Throwable $fallbackError) {
                $this->app->session()->flash('Soubor nebyl rozpoznán pluginem a univerzální mapování se nepodařilo připravit: ' . $fallbackError->getMessage(), 'error');
            }
        } catch (Throwable $e) {
            $this->app->session()->flash('Chyba importu: ' . $e->getMessage(), 'error');
        }

        Http::redirect('index.php' . ($vehicleId > 0 ? '?vehicle_id=' . $vehicleId : ''));
    }
}
