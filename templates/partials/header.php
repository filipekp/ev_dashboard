<?php
    /**
     * Shared application document header.
     *
     * Optional variables set by a template before including this partial:
     * - $pageTitle      string  Browser title.
     * - $bodyClass      string  Additional <body> classes.
     * - $navTitle       string  Navigation title.
     * - $showNavigation bool    Render the shared application navigation.
     * - $pageHead       string  Page-specific markup appended inside <head>.
     */
    $pageTitle         = isset($pageTitle) && $pageTitle !== '' ? (string)$pageTitle . ' | EV Stats' : 'EV Stats';
    $bodyClass         = isset($bodyClass) ? trim((string)$bodyClass) : '';
    $showNavigation    = isset($showNavigation) ? (bool)$showNavigation : TRUE;
    $navTitle          = isset($navTitle) ? (string)$navTitle : '';
    $pageHead          = isset($pageHead) ? (string)$pageHead : '';
    $headerCurrentUser = isset($app) ? $app->auth()->currentUser() : null;
    $headerDemoMode = $headerCurrentUser ? $app->auth()->isDemo($headerCurrentUser) : false;
    if ($headerDemoMode) {
        $bodyClass = trim($bodyClass . ' demo-mode');
    }
    $stylesheetPath = __DIR__ . '/../../public/assets/app.css';
    $scriptPath = __DIR__ . '/../../public/assets/app.js';
    $serviceWorkerPath = __DIR__ . '/../../public/service-worker.js';
    $manifestPath = __DIR__ . '/../../public/manifest.webmanifest';
    $appVersionLabel = isset($app) ? (string)$app->version()->label() : 'local';
    $pwaAssetTimestamp = max(
        (int)@filemtime($stylesheetPath),
        (int)@filemtime($scriptPath),
        (int)@filemtime($serviceWorkerPath),
        (int)@filemtime($manifestPath)
    );
    $pwaCacheVersion = $appVersionLabel . '-' . $pwaAssetTimestamp;
    $pwaVersionQuery = rawurlencode($pwaCacheVersion);
?>
<!doctype html>
<html lang="cs" data-app-version="<?= h($appVersionLabel) ?>" data-pwa-cache-version="<?= h($pwaCacheVersion) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?php require __DIR__ . '/pwa-head.php'; ?>
    <title><?= h($pageTitle) ?></title>
    <script nonce="<?= h(cspNonce()) ?>">
        (() => {
            let stored = 'auto';
            try {
                stored = localStorage.getItem('evstats-theme') || 'auto';
            } catch (error) {
                stored = 'auto';
            }
            const resolved = stored === 'auto'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : stored;
            document.documentElement.dataset.themePreference = stored;
            document.documentElement.setAttribute('data-bs-theme', resolved);
        })();
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/app.css?v=<?= h($pwaVersionQuery) ?>">
    <meta name="author" content="Pavel Filípek <pavel@filipek-czech.cz>">

    <?php
    $analyticsUser = $headerCurrentUser;
    $analyticsEnabled = !($analyticsUser && $app->auth()->isAdmin($analyticsUser));
    ?>
    <?php if ($analyticsEnabled): ?>
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-J9STJ7QW39"></script>
        <script nonce="<?= h(cspNonce()) ?>">
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                dataLayer.push(arguments);
            }

            gtag('js', new Date());
            gtag('config', 'G-J9STJ7QW39');
        </script>
    <?php endif; ?>

    <?= $pageHead ?>
    <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="assets/app.js?v=<?= h($pwaVersionQuery) ?>"></script>
</head>
<body<?= $bodyClass !== '' ? ' class="' . h($bodyClass) . '"' : '' ?>>
<?php if ($showNavigation): ?>
    <?php require __DIR__ . '/navigation.php'; ?>
    <?php if ($headerDemoMode): ?>
        <div class="demo-readonly-banner"><span>◉</span><b>Demo režim · pouze pro čtení</b><span>Uploady a změny dat jsou vypnuté.</span><a href="register.php">Vytvořit vlastní účet →</a></div>
    <?php endif; ?>
<?php endif; ?>
