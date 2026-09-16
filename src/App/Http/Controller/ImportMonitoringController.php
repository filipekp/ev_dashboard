<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use DateTimeImmutable;

/**
 * Administrátorský dohled nad importními procesy aplikace.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class ImportMonitoringController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $me = $this->app->auth()->requireAdmin();
        $range = $this->range((string)($_GET['range'] ?? '30'));
        $from = $this->fromForRange($range);
        $integrationStatus = $this->integrationStatus((string)($_GET['status'] ?? ''));
        $documentStatus = $this->documentStatus((string)($_GET['document_status'] ?? ''));
        $sourceType = trim((string)($_GET['source_type'] ?? ''));
        $sourceTypes = $this->app->adminImportMonitoring()->sourceTypes();
        if ($sourceType === '' || !in_array($sourceType, $sourceTypes, true)) {
            $sourceType = null;
        }

        $repository = $this->app->adminImportMonitoring();
        $this->app->template()->render('import-monitoring', [
            'app' => $this->app,
            'me' => $me,
            'range' => $range,
            'from' => $from,
            'integrationStatus' => $integrationStatus,
            'documentStatus' => $documentStatus,
            'sourceType' => $sourceType,
            'sourceTypes' => $sourceTypes,
            'summary' => $repository->summary($from),
            'integrationRuns' => $repository->integrationRuns($from, $integrationStatus, $sourceType),
            'failedRuns' => $repository->failedIntegrationRuns($from),
            'documentRuns' => $repository->documentRuns($from, $documentStatus),
            'unknownImports' => $repository->unknownImports($from),
        ]);
    }

    private function range(string $value): string
    {
        return in_array($value, ['1', '7', '30', '90', 'all'], true) ? $value : '30';
    }

    private function fromForRange(string $range): ?string
    {
        if ($range === 'all') {
            return null;
        }

        return (new DateTimeImmutable('now'))
            ->modify('-' . (int)$range . ' days')
            ->format('Y-m-d H:i:s');
    }

    private function integrationStatus(string $status): ?string
    {
        return in_array($status, ['running', 'success', 'failed'], true) ? $status : null;
    }

    private function documentStatus(string $status): ?string
    {
        return in_array($status, ['uploaded', 'processing', 'review', 'confirmed', 'error'], true)
            ? $status
            : null;
    }
}
