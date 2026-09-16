<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if ($app->auth()->currentUser()) {
    (new App\Http\Controller\DashboardController($app))->handle();
} else {
    (new App\Http\Controller\LandingController($app))->handle();
}
