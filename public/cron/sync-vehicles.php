<?php

    declare(strict_types=1);

    /**
     * HTTP cron endpoint pro synchronizaci OEM Connected Car dat.
     *
     * Volání:
     * POST /cron/sync-vehicles.php
     * X-EVStats-Cron-Token: <CRON_HTTP_TOKEN>
     */

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        header('Allow: POST');
        http_response_code(405);

        echo json_encode([
            'status' => 'error',
            'code' => 'method_not_allowed',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    $root = dirname(__DIR__, 2);

    require $root . '/src/bootstrap.php';

    $expectedToken = trim((string)env('CRON_HTTP_TOKEN', ''));
    $providedToken = trim(
        (string)($_SERVER['HTTP_X_EVSTATS_CRON_TOKEN'] ?? '')
    );

    /*
     * Pokud není token nakonfigurovaný, endpoint se chová jako neexistující.
     */
    if ($expectedToken === '') {
        http_response_code(404);

        echo json_encode([
            'status' => 'error',
            'code' => 'not_found',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    /*
     * Timing-safe kontrola tajného tokenu.
     */
    if (
        $providedToken === ''
        || !hash_equals($expectedToken, $providedToken)
    ) {
        http_response_code(401);

        echo json_encode([
            'status' => 'error',
            'code' => 'unauthorized',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    /*
     * Pokud Synology ukončí spojení, synchronizace může na serveru doběhnout.
     */
    ignore_user_abort(true);

    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }

    /*
     * Lock proti souběžnému spuštění dvou synchronizací.
     */
    $lockDir = $root . '/storage/locks';

    if (
        !is_dir($lockDir)
        && !@mkdir($lockDir, 0770, true)
        && !is_dir($lockDir)
    ) {
        error_log(
            '[EV Stats HTTP cron] Nelze vytvořit lock directory: '
            . $lockDir
        );

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'code' => 'lock_unavailable',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    $lockFile = $lockDir . '/sync-vehicles.lock';
    $lockHandle = @fopen($lockFile, 'c');

    if ($lockHandle === false) {
        error_log(
            '[EV Stats HTTP cron] Nelze otevřít lock: '
            . $lockFile
        );

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'code' => 'lock_unavailable',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);

        http_response_code(409);

        echo json_encode([
            'status' => 'busy',
            'code' => 'sync_already_running',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    $startedAt = microtime(true);

    try {
        /*
         * V hostingu bez CLI (např. VEDOS) zajistí první CRON po nasazení
         * aplikaci čekajících databázových migrací. Endpoint je chráněný
         * CRON_HTTP_TOKENem a lockem, takže se migrace nemohou spustit souběžně.
         */
        $appliedMigrations = $app->migrations()->migrate();

        /*
         * Stejná služba jako v:
         * bin/sync-vehicles.php
         */
        $result = $app->vehicleSync()->syncAll();

        $durationMs = (int)round(
            (microtime(true) - $startedAt) * 1000
        );

        http_response_code(200);

        echo json_encode([
            'status' => ((int)($result['failed'] ?? 0) > 0)
                ? 'partial'
                : 'ok',

            'connections' => (int)($result['connections'] ?? 0),
            'snapshots' => (int)($result['snapshots'] ?? 0),
            'events' => (int)($result['events'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'trip_repairs' => (int)($result['repairs'] ?? 0),
            'trip_repair_failed' => (int)($result['repair_failed'] ?? 0),
            'migrations_applied' => array_values($appliedMigrations),

            'duration_ms' => $durationMs,
            'finished_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        /*
         * Detail chyby nevracíme ven.
         * Zůstane pouze v PHP/server logu.
         */
        error_log(
            '[EV Stats HTTP cron] '
            . $e->getMessage()
        );

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'code' => 'sync_failed',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
