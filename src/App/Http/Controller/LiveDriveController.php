<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use Throwable;

/**
 * Lehký JSON endpoint pro průběžnou aktualizaci Live Drive karty.
 *
 * Čte pouze již uloženou telemetrii z databáze; nespouští další OEM request,
 * takže dashboard může bezpečně pollovat bez spotřebovávání API limitů.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   25.09.2026
 */
final class LiveDriveController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');

        try {
            $user = $this->app->auth()->requireLogin();
            $vehicleId = max(0, (int)($_GET['vehicle_id'] ?? 0));
            if ($vehicleId === 0 || !$this->app->auth()->canAccessVehicle($user, $vehicleId)) {
                $this->respond(['error' => 'K tomuto vozidlu nemáte přístup.'], 403);
                return;
            }

            $vehicle = $this->app->vehicles()->find($vehicleId);
            if ($vehicle === null) {
                $this->respond(['error' => 'Vozidlo nebylo nalezeno.'], 404);
                return;
            }

            $telemetry = $this->app->vehicleData()->latestTelemetryForVehicle($vehicleId);
            if ($telemetry !== null) {
                $last = strtotime((string)($telemetry['last_synced_at'] ?? $telemetry['received_at'] ?? ''));
                if ($last === false || time() - $last > 7200) {
                    $telemetry = null;
                }
            }

            $intelligence = $this->app->vehicleIntelligence()->dashboard(
                $vehicle,
                $telemetry !== null ? ['telemetry' => $telemetry] : null
            );

            $this->respond([
                'ok' => true,
                'live_drive' => $intelligence['live_drive'] ?? null,
                'range_prediction' => $intelligence['range_prediction'] ?? [],
                'quality_summary' => $intelligence['quality_summary'] ?? [],
                'telemetry' => $telemetry !== null ? [
                    'observed_at' => (string)($telemetry['observed_at'] ?? ''),
                    'received_at' => (string)($telemetry['received_at'] ?? ''),
                    'vehicle_speed_kmh' => is_numeric($telemetry['vehicle_speed_kmh'] ?? null)
                        ? (float)$telemetry['vehicle_speed_kmh']
                        : null,
                    'soc_pct' => is_numeric($telemetry['soc_pct'] ?? null)
                        ? (float)$telemetry['soc_pct']
                        : null,
                ] : null,
            ]);
        } catch (Throwable $e) {
            $this->respond(['error' => 'Live Drive data se nepodařilo načíst.'], 500);
        }
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
