<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use Throwable;

/**
 * Aktivace nového účtu potvrzovacím odkazem z e-mailu.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class VerifyRegistrationController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $success = '';
        $error = '';

        try {
            $token = (string)($_GET['token'] ?? '');
            $this->app->registration()->verifyEmail($token);
            $success = 'E-mail byl úspěšně potvrzen a účet je aktivní. Nyní se můžete přihlásit.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $this->app->template()->render('verify-registration', [
            'app' => $this->app,
            'success' => $success,
            'error' => $error,
        ]);
    }
}
