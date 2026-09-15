<?php

declare(strict_types=1);
namespace App\Service;

use App\AuthService;
use App\Csv\CsvImporter;
use App\Repository\TripRepository;
use App\Repository\VehicleRepository;
use PDO;
use RuntimeException;
use Throwable;

final class ImportService
{
    /** @var PDO */ private $pdo; /** @var AuthService */ private $auth; /** @var CsvImporter */ private $importer; /** @var VehicleRepository */ private $vehicles; /** @var TripRepository */ private $trips;
    public function __construct(PDO $pdo,AuthService $auth,CsvImporter $importer,VehicleRepository $vehicles,TripRepository $trips){$this->pdo=$pdo;$this->auth=$auth;$this->importer=$importer;$this->vehicles=$vehicles;$this->trips=$trips;}

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function importUploaded(array $user,string $path,string $originalName,int $selectedVehicleId): array
    {
        $meta=$this->importer->inspect($path,$originalName); $vin=$meta['vin']; $vehicleId=0; $new=false;
        try {
            if($vin!==null){
                $existing=$this->vehicles->findByVin((string)$vin);
                if($existing){$vehicleId=(int)$existing['id']; if(!$this->auth->canAccessVehicle($user,$vehicleId))throw new RuntimeException('CSV patří již existujícímu vozidlu VIN '.$vin.', ke kterému nemáte přístup.');}
                else {
                    $this->pdo->beginTransaction();
                    try{$vehicleId=$this->vehicles->createFromImport((string)$vin,$meta);$this->vehicles->assignUser((int)$user['id'],$vehicleId);$this->pdo->commit();$new=true;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
                }
            } else {
                $vehicleId=$selectedVehicleId;
                if(!$vehicleId||!$this->auth->canAccessVehicle($user,$vehicleId))throw new RuntimeException('Z názvu CSV se nepodařilo rozpoznat VIN. Vyberte vozidlo nebo ponechte původní název exportu s VIN.');
            }
            $result=$this->importer->import($vehicleId,$path,$originalName?:null);
            return array_merge($result,['vehicle_id'=>$vehicleId,'vin'=>$vin,'new_vehicle'=>$new]);
        } catch(Throwable $e){
            if($new&&$vehicleId>0&&$this->trips->countForVehicle($vehicleId)===0){try{$this->vehicles->delete($vehicleId);}catch(Throwable $ignore){}}
            throw $e;
        }
    }
}
