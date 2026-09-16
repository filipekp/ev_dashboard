<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;

/** Bezpečné zobrazení dokumentu uloženého mimo veřejný webroot. */
final class DocumentFileController
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
        $document = $this->app->documents()->document((int)($_GET['id'] ?? 0));

        if (
            !$document
            || !$this->app->auth()->canAccessVehicle($user, (int)$document['vehicle_id'])
            || !$this->app->userAccess()->canReadDetailAt(
                $user,
                (int)$document['vehicle_id'],
                (string)($document['created_at'] ?? '')
            )
        ) {
            http_response_code(404);
            exit('Dokument nebyl nalezen.');
        }

        $path = $this->app->root() . '/storage/documents/' . basename((string)$document['stored_name']);
        Http::sendStoredFile(
            $path,
            (string)$document['mime_type'],
            (string)$document['original_name'],
            'inline'
        );
    }
}
