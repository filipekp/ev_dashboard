<?php
/** @var array<string,int|string> $pagination */
if (!isset($pagination) || (int)($pagination['pages'] ?? 1) <= 1) {
    return;
}
$paginationParameter = (string)$pagination['parameter'];
$paginationPage = (int)$pagination['page'];
$paginationPages = (int)$pagination['pages'];
$paginationStart = max(1, $paginationPage - 2);
$paginationEnd = min($paginationPages, $paginationPage + 2);
?>
<nav class="entity-pagination" aria-label="Stránkování seznamu">
    <div class="entity-pagination-summary">
        <?= (int)$pagination['from'] ?>–<?= (int)$pagination['to'] ?> z <?= (int)$pagination['total'] ?>
    </div>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item<?= $paginationPage <= 1 ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= h(paginationUrl($paginationParameter, max(1, $paginationPage - 1))) ?>" aria-label="Předchozí">‹</a>
        </li>
        <?php if ($paginationStart > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= h(paginationUrl($paginationParameter, 1)) ?>">1</a></li>
            <?php if ($paginationStart > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($paginationIndex = $paginationStart; $paginationIndex <= $paginationEnd; $paginationIndex++): ?>
            <li class="page-item<?= $paginationIndex === $paginationPage ? ' active' : '' ?>">
                <a class="page-link" href="<?= h(paginationUrl($paginationParameter, $paginationIndex)) ?>"><?= $paginationIndex ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($paginationEnd < $paginationPages): ?>
            <?php if ($paginationEnd < $paginationPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= h(paginationUrl($paginationParameter, $paginationPages)) ?>"><?= $paginationPages ?></a></li>
        <?php endif; ?>
        <li class="page-item<?= $paginationPage >= $paginationPages ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= h(paginationUrl($paginationParameter, min($paginationPages, $paginationPage + 1))) ?>" aria-label="Další">›</a>
        </li>
    </ul>
</nav>
