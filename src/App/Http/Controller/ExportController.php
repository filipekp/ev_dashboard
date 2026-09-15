<?php

declare(strict_types=1);
namespace App\Http\Controller;

use App\Application;

/**
 * Třída ExportController.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class ExportController
{
    /** @var Application */ private $app;
    public function __construct(Application $app){$this->app=$app;}
    public function handle(): void
    {
        $user=$this->app->auth()->requireLogin(); $vehicleId=(int)($_GET['vehicle_id']??0);
        if(!$vehicleId||!$this->app->auth()->canAccessVehicle($user,$vehicleId)){http_response_code(403);exit('K tomuto vozidlu nemáte přístup.');}
        $vehicle=$this->app->vehicles()->find($vehicleId); if(!$vehicle){http_response_code(404);exit('Vozidlo nebylo nalezeno.');}
        $this->app->exporter()->stream($vehicle,(string)($_GET['period']??'all'),(string)($_GET['year']??'')); exit;
    }
}
