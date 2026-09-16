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
    $stylesheetPath    = __DIR__ . '/../../public/assets/app.css';
    $stylesheetVersion = (int)@filemtime($stylesheetPath);
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?php require __DIR__ . '/pwa-head.php'; ?>
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/app.css?v=<?= $stylesheetVersion ?>">
    <meta name="author" content="Pavel Filípek <pavel@filipek-czech.cz>">

    <?php
    $analyticsUser = $headerCurrentUser;
    $analyticsEnabled = !($analyticsUser && $app->auth()->isAdmin($analyticsUser));
    ?>
    <?php if ($analyticsEnabled): ?>
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-J9STJ7QW39"></script>
        <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                dataLayer.push(arguments);
            }

            gtag('js', new Date());
            gtag('config', 'G-J9STJ7QW39');
        </script>
    <?php endif; ?>

    <?= $pageHead ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . h($bodyClass) . '"' : '' ?>>
<?php if ($showNavigation): ?>
    <?php require __DIR__ . '/navigation.php'; ?>
    <?php if ($headerDemoMode): ?>
        <div class="demo-readonly-banner"><span>◉</span><b>Demo režim · pouze pro čtení</b><span>Uploady a změny dat jsou vypnuté.</span><a href="register.php">Vytvořit vlastní účet →</a></div>
    <?php endif; ?>
<?php endif; ?>
