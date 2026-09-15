<?php

declare(strict_types=1);
namespace App\Http\Controller;

use App\Application;
use App\Http;
use RuntimeException;
use Throwable;

/**
 * Třída DashboardController.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class DashboardController
{
    /** @var Application */ private $app;
    public function __construct(Application $app){$this->app=$app;}

    public function handle(): void
    {
        $user=$this->app->auth()->requireLogin();
        if($_SERVER['REQUEST_METHOD']==='POST'&&(string)($_POST['action']??'')==='complete_new_vehicle'){$this->completeNewVehicle($user);return;}
        $vehicles=$this->app->auth()->allowedVehicles($user);$vehicle=$this->app->auth()->selectVehicle($user);$flash=$this->app->session()->pullFlash();
        if(!$vehicle){$this->app->template()->render('dashboard-empty',['app'=>$this->app,'user'=>$user,'flash'=>$flash,'vehicles'=>$vehicles]);return;}
        $newVehicleModal=null;
        if((string)($_GET['new_vehicle']??'')==='1'&&(int)($_SESSION['new_vehicle_id']??0)===(int)$vehicle['id'])$newVehicleModal=$this->app->vehicles()->find((int)$vehicle['id']);
        $data=$this->app->dashboard()->build($vehicle,$_GET);
        $this->app->template()->render('dashboard',array_merge($data,['app'=>$this->app,'user'=>$user,'vehicles'=>$vehicles,'vehicle'=>$vehicle,'flash'=>$flash,'newVehicleModal'=>$newVehicleModal]));
    }

    /** @param array<string,mixed> $user */
    private function completeNewVehicle(array $user): void
    {
        try{
            $this->app->session()->verifyCsrf();$vehicleId=(int)($_POST['vehicle_id']??0);$sessionVehicleId=(int)($_SESSION['new_vehicle_id']??0);
            if(!$vehicleId||$vehicleId!==$sessionVehicleId||!$this->app->auth()->canAccessVehicle($user,$vehicleId))throw new RuntimeException('Údaje tohoto vozidla nelze upravit.');
            $name=trim((string)($_POST['name']??''));$battery=(float)str_replace(',','.',(string)($_POST['battery_kwh']??'0'));$nominal=(float)str_replace(',','.',(string)($_POST['battery_nominal_kwh']??'0'));$sohRaw=trim((string)($_POST['soh_manual_pct']??''));$soh=$sohRaw===''?null:(float)str_replace(',','.',$sohRaw);$home=trim((string)($_POST['home_label']??''));
            if($name===''||$battery<=0||$nominal<=0)throw new RuntimeException('Vyplňte název a kapacitu baterie.');if($soh!==null&&($soh<50||$soh>110))throw new RuntimeException('SoH zadejte v rozsahu 50–110 %.');
            $q=$this->app->pdo()->prepare('UPDATE vehicles SET name=?,battery_kwh=?,battery_nominal_kwh=?,soh_manual_pct=?,soh_manual_at=IF(? IS NULL,NULL,NOW()),home_label=? WHERE id=?');$q->execute([$name,$battery,$nominal,$soh,$soh,$home?:null,$vehicleId]);unset($_SESSION['new_vehicle_id']);$this->app->session()->flash('Údaje nového vozidla byly uloženy.');Http::redirect('index.php?vehicle_id='.$vehicleId);
        }catch(Throwable $e){$this->app->session()->flash($e->getMessage(),'error');$vid=(int)($_POST['vehicle_id']??0);Http::redirect('index.php'.($vid>0?'?vehicle_id='.$vid.'&new_vehicle=1':''));}
    }
}
