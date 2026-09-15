<?php
declare(strict_types=1); namespace App\Http\Controller; use App\Application; use App\Http; use RuntimeException; use Throwable;
/**
 * Třída ForgotPasswordController.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class ForgotPasswordController { private $app; public function __construct(Application $app){$this->app=$app;} public function handle(): void {if(!$this->app->auth()->usersExist())Http::redirect('setup.php');$message='';$debugUrl='';if($_SERVER['REQUEST_METHOD']==='POST'){try{$this->app->session()->verifyCsrf();$email=strtolower(trim((string)($_POST['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Zadejte platný e-mail.');$q=$this->app->pdo()->prepare('SELECT id,name,email,active FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$u=$q->fetch();if($u&&(int)$u['active']){$token=$this->app->auth()->createPasswordResetToken((int)$u['id']);$url=$this->app->auth()->resetUrl($token);$this->app->auth()->sendPasswordResetEmail($u['email'],$u['name'],$url);if((bool)$this->app->config()->get('app.debug',false))$debugUrl=$url;}$message='Pokud účet s tímto e-mailem existuje, poslali jsme odkaz pro nastavení nového hesla.';}catch(Throwable $e){$message=$e->getMessage();}}$this->app->template()->render('forgot-password',['app'=>$this->app,'message'=>$message,'debugUrl'=>$debugUrl]);}}
