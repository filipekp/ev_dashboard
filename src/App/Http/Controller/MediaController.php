<?php

declare(strict_types=1);
namespace App\Http\Controller;
use App\Application; use App\Http; use Throwable;
final class MediaController
{
    /** @var Application */ private $app;
    public function __construct(Application $app){$this->app=$app;}
    public function handle(): void
    {
        $user=$this->app->auth()->requireLogin();
        if (isset($_GET['view'])) { $this->view($user,(int)$_GET['view']); return; }
        $vehicleId=(int)($_POST['vehicle_id'] ?? $_GET['vehicle_id'] ?? 0);
        if ($vehicleId<=0 || !$this->app->auth()->canAccessVehicle($user,$vehicleId)) { http_response_code(404); exit('Vozidlo nebylo nalezeno.'); }
        try {
            $this->app->session()->verifyCsrf();
            if ((string)($_POST['action'] ?? '')==='upload_photo') {
                $this->app->vehicleMediaService()->uploadPhoto($vehicleId,(int)$user['id'],$_FILES['photo'] ?? [],$_POST['caption'] ?? null);
                $this->app->session()->flash('Fotografie vozidla byla nahrána.');
            } elseif ((string)($_POST['action'] ?? '')==='set_primary') {
                $this->app->vehicleMedia()->setPrimary($vehicleId,(int)($_POST['media_id'] ?? 0));
                $this->app->session()->flash('Hlavní fotografie byla změněna.');
            }
        } catch(Throwable $e){$this->app->session()->flash($e->getMessage(),'error');}
        Http::redirect('documents.php?vehicle_id='.$vehicleId.'#vehicle-photos');
    }
    private function view(array $user,int $id): void
    {
        $media=$this->app->vehicleMedia()->find($id);
        if(!$media || !$this->app->auth()->canAccessVehicle($user,(int)$media['vehicle_id'])){http_response_code(404);exit('Fotografie nebyla nalezena.');}
        $path=$this->app->root().'/storage/vehicle-media/'.basename((string)$media['stored_name']); if(!is_file($path)){http_response_code(404);exit('Soubor nebyl nalezen.');}
        header('Content-Type: '.(string)$media['mime_type']); header('Content-Length: '.filesize($path)); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff'); readfile($path);
    }
}
