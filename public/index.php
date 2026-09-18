<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if ($app->auth()->currentUser()) {
    (new App\Http\Controller\DashboardController($app))->handle();
} elseif ((string)($_GET['pwa'] ?? '') === '1') {
    App\Http::redirect('login.php?pwa=1');
} else {
    (new App\Http\Controller\LandingController($app))->handle();
}
