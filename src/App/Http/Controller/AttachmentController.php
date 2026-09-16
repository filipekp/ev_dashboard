<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;

/**
 * Bezpečné stahování servisních příloh mimo veřejný webroot.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
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
        $attachmentId = (int)($_GET['id'] ?? 0);
        $attachment = $this->app->vehicleOperations()->attachment($attachmentId);

        if (!$attachment || !$this->app->auth()->canAccessVehicle($user, (int)$attachment['vehicle_id']) || !$this->app->userAccess()->canReadDetailAt($user, (int)$attachment['vehicle_id'], (string)($attachment['serviced_at'] ?? ''))) {
            http_response_code(404);
            exit('Příloha nebyla nalezena.');
        }

        $path = $this->app->root() . '/storage/service/' . basename((string)$attachment['stored_name']);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Soubor nebyl nalezen.');
        }

        header('Content-Type: ' . (string)$attachment['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . rawurlencode((string)$attachment['original_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }
}
