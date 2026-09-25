<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/** Bezpečné uživatelské mazání dokončených jízd. */
final class TripDeleteController
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
        $vehicleId = max(0, (int)($_POST['vehicle_id'] ?? 0));
        $returnTo = (string)($_POST['return_to'] ?? 'dashboard');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Metoda není povolena.');
        }

        try {
            $this->app->session()->verifyCsrf();
            $tripId = max(0, (int)($_POST['trip_id'] ?? 0));
            if ($vehicleId <= 0 || $tripId <= 0) {
                throw new RuntimeException('Neplatná jízda ke smazání.');
            }
            if (!$this->app->auth()->canAccessVehicle($user, $vehicleId)) {
                throw new RuntimeException('K tomuto vozidlu nemáte přístup.');
            }

            $trip = $this->app->trips()->findByIdForVehicle($vehicleId, $tripId);
            if (
                !$trip
                || !$this->app->userAccess()->canReadDetailAt(
                    $user,
                    $vehicleId,
                    (string)($trip['started_at'] ?? '')
                )
            ) {
                throw new RuntimeException('Jízda nebyla nalezena.');
            }

            $this->app->trips()->deleteForVehicle($vehicleId, $tripId, (int)$user['id']);
            $this->app->session()->flash('Jízda byla trvale smazána.');
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
        }

        Http::redirect($this->returnUrl($returnTo, $vehicleId));
    }

    private function returnUrl(string $returnTo, int $vehicleId): string
    {
        if ($returnTo === 'timeline') {
            return 'timeline.php?vehicle_id=' . $vehicleId;
        }

        return 'index.php?vehicle_id=' . $vehicleId;
    }
}
