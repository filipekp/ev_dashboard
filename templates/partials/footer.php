<?php
/** Shared application footer and document closing tags. */
$appVersionLabel = isset($app) ? (string)$app->version()->label() : '';
?>
<footer class="site-footer">
  created by: &copy; 2026 Pavel Filípek
  (<a href="https://www.filipek-czech.cz" target="_blank" rel="noopener noreferrer">www.filipek-czech.cz</a>)
  <?php if ($appVersionLabel !== ''): ?> · verze <?= h($appVersionLabel) ?><?php endif; ?>
</footer>
</body>
</html>
