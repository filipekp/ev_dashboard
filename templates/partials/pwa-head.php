<?php
/**
 * Shared Progressive Web App metadata.
 * Keep URLs relative so the application also works when installed from a subdirectory.
 */
?>
<meta name="theme-color" content="#080b10">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="EV Stats">
<meta name="application-version" content="<?= h($appVersionLabel ?? 'local') ?>">
<link rel="manifest" href="manifest.webmanifest?v=<?= h($pwaVersionQuery ?? 'local') ?>">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png?v=<?= h($pwaVersionQuery ?? 'local') ?>">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png?v=<?= h($pwaVersionQuery ?? 'local') ?>">

