<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;
use Throwable;

/** Dokumentové centrum vozidla a AI inbox. */
final class DocumentsController
{
    /** @var Application */ private $app;
    public function __construct(Application $app) { $this->app=$app; }

    public function handle(): void
    {
        $user=$this->app->auth()->requireLogin();
        $vehicle=$this->app->auth()->selectVehicle($user);
        if (!$vehicle) Http::redirect('index.php');
        $vehicleId=(int)$vehicle['id'];
        if (!$this->app->auth()->canAccessVehicle($user,$vehicleId)) { http_response_code(403); exit('Přístup odepřen.'); }
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            try {
                $this->app->session()->verifyCsrf();
                $action=(string)($_POST['action'] ?? '');
                if ($action==='upload_document') {
                    $runId=$this->app->documentImportService()->uploadAndExtract($vehicleId,(int)$user['id'],$_FILES['document'] ?? []);
                    $run=$this->app->documents()->run($runId);
                    $this->app->session()->flash(($run && $run['status']==='review') ? 'Dokument byl vytěžen. Zkontrolujte údaje před potvrzením.' : 'Dokument byl uložen, ale vytěžení vyžaduje pozornost.', ($run && $run['status']==='review') ? 'ok' : 'error');
                    Http::redirect('documents.php?vehicle_id='.$vehicleId.'&run_id='.$runId);
                }
                if ($action==='confirm_import') {
                    $this->app->documentImportService()->confirm((int)($_POST['run_id'] ?? 0),$vehicleId);
                    $this->app->session()->flash('Vytěžené údaje byly potvrzeny a zapsány do provozní evidence.');
                    Http::redirect('documents.php?vehicle_id='.$vehicleId);
                }
            } catch (Throwable $e) { $this->app->session()->flash($e->getMessage(),'error'); Http::redirect('documents.php?vehicle_id='.$vehicleId); }
        }
        $runId=(int)($_GET['run_id'] ?? 0);
        $run=$runId>0 ? $this->app->documents()->run($runId) : null;
        if ($run && (int)$run['vehicle_id']!==$vehicleId) $run=null;
        $this->app->template()->render('documents',[
            'app'=>$this->app,'user'=>$user,'vehicles'=>$this->app->auth()->allowedVehicles($user),'vehicle'=>$vehicle,
            'documents'=>$this->app->documents()->listForVehicle($vehicleId),'run'=>$run,'flash'=>$this->app->session()->pullFlash(),
            'aiProvider'=>(string)$this->app->config()->get('ai.provider','none'),
        ]);
    }
}
