<?php
$pageTitle = 'Dashboard';
$showNavigation = true;
$navTitle = 'Dashboard';
require __DIR__ . '/partials/header.php';
?>
<main class="wrap narrow">
  <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <?php require __DIR__ . '/partials/add-vehicle-modal.php'; ?>

  <section class="card empty-state">
    <h1>🚙 Žádné dostupné vozidlo</h1>
    <p>Nejprve můžete založit vozidlo ručně. To je nutné zejména pro Kia Connect XLSX, protože tento export VIN neobsahuje. CSV s VIN v názvu umí nové vozidlo rozpoznat a založit automaticky.</p>
    <div class="empty-upload">
      <a class="btn primary" href="index.php?add_vehicle=1">➕ Přidat vozidlo</a>
      <form action="upload.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="vehicle_id" value="0">
        <label class="btn">⬆ Nahrát CSV s VIN<input type="file" name="csv"
            accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
            onchange="this.form.submit()"></label>
      </form>
      <?php if ($app->auth()->canManageVehicles($user)): ?><a class="btn" href="vehicles.php">Správa vozidel</a><?php endif; ?>
    </div>
  </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
