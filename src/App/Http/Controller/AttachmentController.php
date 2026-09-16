<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;

/** Bezpečné stahování servisních příloh mimo veřejný webroot. */
final class AttachmentController
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
        $attachment = $this->app->vehicleOperations()->attachment((int)($_GET['id'] ?? 0));

        if (
            !$attachment
            || !$this->app->auth()->canAccessVehicle($user, (int)$attachment['vehicle_id'])
            || !$this->app->userAccess()->canReadDetailAt(
                $user,
                (int)$attachment['vehicle_id'],
                (string)($attachment['serviced_at'] ?? '')
            )
        ) {
            http_response_code(404);
            exit('Příloha nebyla nalezena.');
        }

        $path = $this->app->root() . '/storage/service/' . basename((string)$attachment['stored_name']);
        Http::sendStoredFile(
            $path,
            (string)$attachment['mime_type'],
            (string)$attachment['original_name'],
            'attachment'
        );
    }
}
