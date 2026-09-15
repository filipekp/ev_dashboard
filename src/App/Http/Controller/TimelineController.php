<?php

declare(strict_types=1);
namespace App\Http\Controller;
use App\Application; use App\Http;
final class TimelineController { private $app; public function __construct(Application $app){$this->app=$app;} public function handle():void{$user=$this->app->auth()->requireLogin();$vehicle=$this->app->auth()->selectVehicle($user);if(!$vehicle)Http::redirect('index.php');$this->app->template()->render('timeline',['app'=>$this->app,'user'=>$user,'vehicle'=>$vehicle,'vehicles'=>$this->app->auth()->allowedVehicles($user),'events'=>$this->app->timeline()->build((int)$vehicle['id']),'insights'=>$this->app->insights()->build($vehicle),'vehiclePhoto'=>$this->app->vehicleMedia()->primaryForVehicle((int)$vehicle['id']),'flash'=>$this->app->session()->pullFlash()]);}}
