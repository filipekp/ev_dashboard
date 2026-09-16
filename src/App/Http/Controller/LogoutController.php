<?php
declare(strict_types=1); namespace App\Http\Controller; use App\Application; use App\Http;
/**
 * Třída LogoutController.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class LogoutController { private $app; public function __construct(Application $app){$this->app=$app;} public function handle(): void {$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy();Http::redirect('index.php');}}
