<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use App\Service\DocumentImportService;
use Throwable;

/** Dokumentové centrum vozidla a AI inbox. */
final class DocumentsController
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
            $this->handlePost($user, $vehicleId);
        }

        $scope = $this->app->userAccess()->vehicleDetailScope($user, $vehicleId);
        $runId = (int)($_GET['run_id'] ?? 0);
        $run = $runId > 0 ? $this->app->documents()->run($runId) : null;

        if (
            $run
            && (
                (int)$run['vehicle_id'] !== $vehicleId
                || !$this->app->userAccess()->canReadDetailAt(
                    $user,
                    $vehicleId,
                    (string)($run['created_at'] ?? '')
                )
            )
        ) {
            $run = null;
        }

        $runHistory = [];
        $linkedOperationCount = 0;
        if ($run) {
            $documentId = (int)$run['document_id'];
            $runHistory = $this->app->documents()->runsForDocument(
                $documentId,
                $scope['from'],
                25
            );
            $linkedOperationCount = $this->app->documents()->linkedOperationCount(
                $documentId,
                $vehicleId
            );
        }

        $aiProvider = strtolower((string)$this->app->config()->get('ai.provider', 'none'));

        $this->app->template()->render('documents', [
            'app' => $this->app,
            'user' => $user,
            'vehicles' => $this->app->auth()->allowedVehicles($user),
            'vehicle' => $vehicle,
            'documents' => $this->app->documents()->listForVehicle($vehicleId, 100, $scope['from']),
            'run' => $run,
            'runHistory' => $runHistory,
            'linkedOperationCount' => $linkedOperationCount,
            'flash' => $this->app->session()->pullFlash(),
            'aiProvider' => $aiProvider,
            'aiAvailable' => $this->isAiAvailable($aiProvider),
        ]);
    }

    /** @param array<string,mixed> $user */
    private function handlePost(array $user, int $vehicleId): void
    {
        try {
            $this->app->session()->verifyCsrf();
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'upload_document') {
                $runId = $this->app->documentImportService()->uploadAndExtract(
                    $vehicleId,
                    (int)$user['id'],
                    $_FILES['document'] ?? []
                );
                $this->redirectAfterExtraction($vehicleId, $runId, false);
            }

            if ($action === 'reextract_document') {
                $runId = $this->app->documentImportService()->reExtract(
                    $vehicleId,
                    (int)$user['id'],
                    (int)($_POST['document_id'] ?? 0),
                    (string)($_POST['extraction_mode'] ?? DocumentImportService::EXTRACTION_AUTO)
                );
                $this->redirectAfterExtraction($vehicleId, $runId, true);
            }

            if ($action === 'confirm_import') {
                $replaced = $this->app->documentImportService()->confirm(
                    (int)($_POST['run_id'] ?? 0),
                    $vehicleId
                );

                $this->app->session()->flash(
                    $replaced > 0
                        ? 'Nové vytěžení bylo potvrzeno. Dřívější položky vytvořené tímto dokumentem byly nahrazeny aktuálními daty.'
                        : 'Vytěžené údaje byly potvrzeny a zapsány do provozní evidence.'
                );
                Http::redirect('documents.php?vehicle_id=' . $vehicleId);
            }
        } catch (Throwable $e) {
            $this->app->session()->flash($e->getMessage(), 'error');
            Http::redirect('documents.php?vehicle_id=' . $vehicleId);
        }
    }

    private function redirectAfterExtraction(int $vehicleId, int $runId, bool $reExtraction): void
    {
        $run = $this->app->documents()->run($runId);
        $isReview = $run && $run['status'] === 'review';
        $extractor = $run ? trim((string)($run['extractor'] ?? '')) : '';

        if ($isReview) {
            $message = $reExtraction
                ? 'Dokument byl znovu vytěžen. Zkontrolujte nové údaje před potvrzením.'
                : 'Dokument byl vytěžen. Zkontrolujte údaje před potvrzením.';
            if ($extractor !== '') {
                $message .= ' Použitý extraktor: ' . $extractor . '.';
            }
            $this->app->session()->flash($message, 'ok');
        } else {
            $this->app->session()->flash(
                $reExtraction
                    ? 'Opakované vytěžení bylo spuštěno, ale skončilo chybou. Otevřete detail běhu.'
                    : 'Dokument byl uložen, ale vytěžení vyžaduje pozornost.',
                'error'
            );
        }

        Http::redirect('documents.php?vehicle_id=' . $vehicleId . '&run_id=' . $runId);
    }

    private function isAiAvailable(string $provider): bool
    {
        if ($provider === 'openai') {
            return trim((string)$this->app->config()->get('ai.openai_api_key', '')) !== '';
        }

        if ($provider === 'gemini') {
            return trim((string)$this->app->config()->get('ai.gemini_api_key', '')) !== '';
        }

        return false;
    }
}
