<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Controller provozní evidence vozidla.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class OperationsController
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
        $vehicle = $this->app->auth()->selectVehicle($user);
        if (!$vehicle) {
            Http::redirect('index.php');
        }

        $vehicleId = (int)$vehicle['id'];
        if (!$this->app->auth()->canAccessVehicle($user, $vehicleId)) {
            http_response_code(403);
            exit('Přístup odepřen.');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost($vehicleId);
            return;
        }

        $repository = $this->app->vehicleOperations();
        $services = $repository->serviceRecords($vehicleId);
        $attachments = [];
        foreach ($services as $service) {
            $attachments[(int)$service['id']] = $repository->attachmentsForService((int)$service['id']);
        }

        $this->app->template()->render('operations', [
            'app' => $this->app,
            'user' => $user,
            'vehicles' => $this->app->auth()->allowedVehicles($user),
            'vehicle' => $vehicle,
            'flash' => $this->app->session()->pullFlash(),
            'summary' => $repository->costSummary($vehicleId),
            'energyEntries' => $repository->energyEntries($vehicleId),
            'services' => $services,
            'serviceAttachments' => $attachments,
            'expenses' => $repository->expenses($vehicleId),
            'reminders' => $repository->reminders($vehicleId),
            'tripBook' => $repository->tripBookEntries($vehicleId),
            'importedTrips' => $repository->tripLog($vehicleId, 30),
            'prefillTrip' => (int)($_GET['source_trip_id'] ?? 0) > 0
                ? $repository->importedTrip($vehicleId, (int)$_GET['source_trip_id'])
                : null,
        ]);
    }

    private function handlePost(int $vehicleId): void
    {
        try {
            $this->app->session()->verifyCsrf();
            $action = (string)($_POST['action'] ?? '');
            $service = $this->app->vehicleOperationService();

            switch ($action) {
                case 'add_energy':
                    $service->addEnergyEntry($vehicleId, $_POST);
                    $this->app->session()->flash('Tankování / nabíjení bylo uloženo.');
                    break;

                case 'add_service':
                    $service->addServiceRecord($vehicleId, $_POST, $_FILES['attachment'] ?? null);
                    $this->app->session()->flash('Servisní záznam byl uložen.');
                    break;

                case 'add_expense':
                    $service->addExpense($vehicleId, $_POST);
                    $this->app->session()->flash('Náklad byl uložen.');
                    break;

                case 'add_reminder':
                    $service->addReminder($vehicleId, $_POST);
                    $this->app->session()->flash('Připomínka byla uložena.');
                    break;

                case 'complete_reminder':
                    $reminderId = (int)($_POST['reminder_id'] ?? 0);
                    if ($reminderId <= 0) {
                        throw new RuntimeException('Připomínka nebyla nalezena.');
                    }
                    $this->app->vehicleOperations()->completeReminder($vehicleId, $reminderId);
                    $this->app->session()->flash('Připomínka byla označena jako hotová.');
                    break;

                case 'add_trip_book':
                    $service->addTripBookEntry($vehicleId, $_POST);
                    $this->app->session()->flash('Jízda byla přidána do knihy jízd.');
                    break;

                case 'update_trip_book':
                    $service->updateTripBookEntry($vehicleId, $_POST);
                    $this->app->session()->flash('Záznam jízdy byl upraven.');
                    break;

                case 'update_trip':
                    $service->updateTripLog($vehicleId, $_POST);
                    $this->app->session()->flash('Importovaná jízda byla upravena.');
                    break;

                default:
                    throw new RuntimeException('Neznámá operace.');
            }
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
        }

        Http::redirect('operations.php?vehicle_id=' . $vehicleId);
    }
}
