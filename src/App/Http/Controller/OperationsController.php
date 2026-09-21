<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use App\Pagination;
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
            $this->handlePost($vehicleId, $user);
            return;
        }

        $scope = $this->app->userAccess()->vehicleDetailScope($user, $vehicleId);
        $from = $scope['from'];
        $repository = $this->app->vehicleOperations();
        $perPage = Pagination::DEFAULT_PER_PAGE;
        $energyPagination = Pagination::meta(
            $repository->energyEntryCount($vehicleId, $from),
            Pagination::currentPage('energy_page'),
            $perPage,
            'energy_page'
        );
        $servicePagination = Pagination::meta(
            $repository->serviceRecordCount($vehicleId, $from),
            Pagination::currentPage('service_page'),
            $perPage,
            'service_page'
        );
        $expensePagination = Pagination::meta(
            $repository->expenseCount($vehicleId, $from),
            Pagination::currentPage('expense_page'),
            $perPage,
            'expense_page'
        );
        $reminderPagination = Pagination::meta(
            $repository->reminderCount($vehicleId, $from),
            Pagination::currentPage('reminder_page'),
            $perPage,
            'reminder_page'
        );
        $tripBookPagination = Pagination::meta(
            $repository->tripBookCount($vehicleId, $from),
            Pagination::currentPage('trip_page'),
            $perPage,
            'trip_page'
        );

        $services = $repository->serviceRecords(
            $vehicleId,
            $perPage,
            $from,
            ((int)$servicePagination['page'] - 1) * $perPage
        );
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
            'energyEntries' => $repository->energyEntries($vehicleId, $perPage, $from, ((int)$energyPagination['page'] - 1) * $perPage),
            'energyPagination' => $energyPagination,
            'services' => $services,
            'servicePagination' => $servicePagination,
            'serviceAttachments' => $attachments,
            'expenses' => $repository->expenses($vehicleId, $perPage, $from, ((int)$expensePagination['page'] - 1) * $perPage),
            'expensePagination' => $expensePagination,
            'reminders' => $repository->reminders($vehicleId, $from, $perPage, ((int)$reminderPagination['page'] - 1) * $perPage),
            'reminderPagination' => $reminderPagination,
            'tripBook' => $repository->tripBookEntries($vehicleId, $perPage, $from, ((int)$tripBookPagination['page'] - 1) * $perPage),
            'tripBookPagination' => $tripBookPagination,
            'importedTrips' => $repository->tripLog($vehicleId, 100, $from),
            'prefillTrip' => (int)($_GET['source_trip_id'] ?? 0) > 0
                ? $repository->importedTrip($vehicleId, (int)$_GET['source_trip_id'], $from)
                : null,
        ]);
    }

    private function handlePost(int $vehicleId, array $user): void
    {
        try {
            $this->app->session()->verifyCsrf();
            $action = (string)($_POST['action'] ?? '');
            $scope = $this->app->userAccess()->vehicleDetailScope($user, $vehicleId);
            $dateValue = (string)($_POST['occurred_at'] ?? $_POST['serviced_at'] ?? $_POST['started_at'] ?? '');
            if ($dateValue !== '' && !$this->app->userAccess()->canReadDetailAt($user, $vehicleId, $dateValue)) {
                throw new RuntimeException('Záznam nelze uložit před začátek vašeho přístupu k vozidlu.');
            }
            if ($action === 'update_trip') {
                $trip = $this->app->vehicleOperations()->importedTrip($vehicleId, (int)($_POST['trip_id'] ?? 0), $scope['from']);
                if (!$trip) {
                    throw new RuntimeException('Jízda není v období, ke kterému máte přístup.');
                }
            }
            $service = $this->app->vehicleOperationService();

            switch ($action) {
                case 'add_energy':
                    $service->addEnergyEntry($vehicleId, $_POST);
                    $this->app->session()->flash('Tankování / nabíjení bylo uloženo.');
                    break;

                case 'update_energy_price':
                    $service->updateEnergyPrice($vehicleId, $_POST);
                    $this->app->session()->flash('Cena nabíjení byla uložena.');
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

        $returnView = (string)($_POST['action'] ?? '') === 'add_expense' ? 'costs' : 'operations';
        Http::redirect('operations.php?vehicle_id=' . $vehicleId . '&view=' . $returnView);
    }
}
