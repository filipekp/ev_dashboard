<?php

declare(strict_types=1);
namespace App\Http\Controller;
use App\Application;
final class DocumentFileController
{
    /** @var Application */ private $app;
    public function __construct(Application $app){$this->app=$app;}
    public function handle(): void
    {
        $user=$this->app->auth()->requireLogin(); $doc=$this->app->documents()->document((int)($_GET['id'] ?? 0));
        if(!$doc || !$this->app->auth()->canAccessVehicle($user,(int)$doc['vehicle_id'])){http_response_code(404);exit('Dokument nebyl nalezen.');}
        $path=$this->app->root().'/storage/documents/'.basename((string)$doc['stored_name']); if(!is_file($path)){http_response_code(404);exit('Soubor nebyl nalezen.');}
        header('Content-Type: '.(string)$doc['mime_type']); header('Content-Length: '.filesize($path)); header('Content-Disposition: inline; filename="'.rawurlencode((string)$doc['original_name']).'"'); header('X-Content-Type-Options: nosniff'); readfile($path);
    }
}
