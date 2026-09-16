<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http;

/**
 * Veřejná landing page EV Stats.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class LandingController
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        if (!$this->app->auth()->usersExist()) {
            Http::redirect('setup.php');
        }

        $this->app->template()->render('landing', [
            'app' => $this->app,
            'flash' => $this->app->session()->pullFlash(),
            'demoEnabled' => (bool)$this->app->config()->get('demo.enabled', true),
        ]);
    }
}
