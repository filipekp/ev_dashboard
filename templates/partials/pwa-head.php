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
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('service-worker.js').catch(() => {
      // PWA support is optional; the web application remains fully usable without it.
    });
  });
}
</script>
