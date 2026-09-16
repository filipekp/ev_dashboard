<?php
$pageTitle = 'Uživatelé';
$showNavigation = true;
$navTitle = 'Správa uživatelů';
require __DIR__ . '/partials/header.php';
?>
<main class="wrap narrow admin-list-page">
  <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'error' ? 'error' : 'notice' ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <section class="card table-card admin-list-card">
    <div class="list-toolbar">
      <div>
        <span class="section-kicker">Administrace</span>
        <h2>👥 Uživatelé</h2>
        <p>Spravujte účty, role a přístup k vozidlům bez dlouhých formulářů na stránce.</p>
      </div>
      <button class="btn primary" type="button" data-user-create>＋ Přidat uživatele</button>
    </div>

    <div class="table-wrap">
      <table class="data-table admin-list-table">
        <thead>
        <tr>
          <th>Uživatel</th>
          <th>Role</th>
          <th>Nadřízený</th>
          <th>Vozidla</th>
          <th>Stav</th>
          <th class="actions-col">Akce</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $payload = [
                'id' => (int)$u['id'],
                'name' => (string)$u['name'],
                'email' => (string)$u['email'],
                'role' => (string)$u['role'],
                'active' => (bool)$u['active'],
                'parent_user_id' => isset($u['parent_user_id']) ? (int)$u['parent_user_id'] : null,
                'vehicle_ids' => $assigned[(int)$u['id']] ?? [],
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
        ?>
          <tr>
            <td>
              <div class="table-primary"><strong><?= h($u['name']) ?></strong><small><?= h($u['email']) ?></small></div>
            </td>
            <td><?= h($roleLabels[$u['role']] ?? $u['role']) ?></td>
            <td><?= h((string)($u['parent_name'] ?? '—')) ?></td>
            <td><span class="count-badge"><?= (int)$u['vehicle_count'] ?></span></td>
            <td><span class="status-badge <?= (int)$u['active'] ? 'is-active' : 'is-inactive' ?>"><?= (int)$u['active'] ? 'Aktivní' : 'Neaktivní' ?></span></td>
            <td class="actions-col">
              <div class="row-actions">
                <button class="btn btn-compact" type="button" data-user-edit data-user='<?= h((string)$json) ?>'>Upravit</button>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                  <input type="hidden" name="action" value="reset_link">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-compact" type="submit" title="Poslat obnovu hesla">🔑</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
          <tr><td colspan="6" class="empty-table">Zatím nejsou založeni žádní uživatelé.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<div class="app-modal" id="userEditorModal" hidden aria-hidden="true">
  <div class="app-modal-backdrop" data-user-close></div>
  <section class="app-modal-dialog admin-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="userEditorTitle">
    <header class="app-modal-head">
      <div>
        <span class="section-kicker" id="userEditorKicker">Nový účet</span>
        <h2 id="userEditorTitle">Přidat uživatele</h2>
        <p id="userEditorSubtitle">Vyplňte údaje a případně rovnou přiřaďte vozidla.</p>
      </div>
      <button class="modal-close" type="button" data-user-close aria-label="Zavřít">×</button>
    </header>

    <form method="post" class="app-modal-body" id="userEditorForm">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" id="userAction" value="create">
      <input type="hidden" name="id" id="userId" value="">

      <div class="admin-modal-grid">
        <div class="form-panel">
          <h3>Účet</h3>
          <div class="modal-field-grid">
            <label class="span-2"><span>Jméno</span><input id="userName" name="name" required autocomplete="name"></label>
            <label class="span-2"><span>E-mail</span><input id="userEmail" name="email" type="email" required autocomplete="email"></label>
            <label id="userPasswordField" class="span-2"><span>Heslo</span><input id="userPassword" name="password" type="password" minlength="8" autocomplete="new-password"><small>Minimálně 8 znaků.</small></label>
            <?php if ($app->auth()->isAdmin($me)): ?>
            <label><span>Role</span><select id="userRole" name="role">
              <option value="user">Řidič</option>
              <option value="manager">Správce vozidel</option>
              <option value="admin">Administrátor</option>
            </select></label>
            <label id="userParentField"><span>Nadřízený správce</span><select id="userParent" name="parent_user_id">
              <option value="">— vyberte správce —</option>
              <?php foreach ($managers as $manager): ?><option value="<?= (int)$manager['id'] ?>"><?= h($manager['name']) ?> · <?= h($manager['email']) ?></option><?php endforeach; ?>
            </select></label>
            <?php else: ?>
            <input type="hidden" id="userRole" name="role" value="user">
            <input type="hidden" id="userParent" name="parent_user_id" value="<?= (int)$me['id'] ?>">
            <?php endif; ?>
            <label class="toggle-field" id="userActiveField"><span>Stav účtu</span><span class="toggle-line"><input id="userActive" type="checkbox" name="active" checked> Aktivní účet</span></label>
          </div>
        </div>

        <div class="form-panel">
          <h3>Přiřazená vozidla</h3>
          <p class="form-panel-help">Řidič může dostat pouze vozidla svého nadřízeného správce. Datum účinnosti chrání historii předchozích vlastníků.</p>
          <label><span>Změna přiřazení platí od</span><input type="date" name="assignment_effective_date" id="userAssignmentDate" value="<?= date('Y-m-d') ?>"></label>
          <div class="assignment-list">
            <?php foreach ($vehicles as $v): ?>
              <label class="check assignment">
                <input type="checkbox" name="vehicle_ids[]" value="<?= (int)$v['id'] ?>" data-user-vehicle>
                <span><?= h($v['name']) ?><small><?= h($v['vin']) ?></small></span>
              </label>
            <?php endforeach; ?>
            <?php if (!$vehicles): ?><p class="empty-assignment">Nejsou založena žádná vozidla.</p><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="modal-actions admin-modal-actions">
        <div class="danger-zone" id="userDeleteZone" hidden>
          <button class="btn danger" type="submit" name="action" value="delete" id="userDeleteButton">Smazat uživatele</button>
        </div>
        <div class="modal-actions-right">
          <button type="button" class="btn" data-user-close>Zrušit</button>
          <button type="submit" class="btn primary" id="userSubmit">Přidat uživatele</button>
        </div>
      </div>
    </form>
  </section>
</div>

<script>
(() => {
  const modal = document.getElementById('userEditorModal');
  const form = document.getElementById('userEditorForm');
  if (!modal || !form) return;

  const fields = {
    action: document.getElementById('userAction'),
    id: document.getElementById('userId'),
    name: document.getElementById('userName'),
    email: document.getElementById('userEmail'),
    password: document.getElementById('userPassword'),
    passwordField: document.getElementById('userPasswordField'),
    role: document.getElementById('userRole'),
    parent: document.getElementById('userParent'),
    parentField: document.getElementById('userParentField'),
    active: document.getElementById('userActive'),
    activeField: document.getElementById('userActiveField'),
    title: document.getElementById('userEditorTitle'),
    kicker: document.getElementById('userEditorKicker'),
    subtitle: document.getElementById('userEditorSubtitle'),
    submit: document.getElementById('userSubmit'),
    deleteZone: document.getElementById('userDeleteZone'),
    deleteButton: document.getElementById('userDeleteButton')
  };
  const vehicleChecks = Array.from(document.querySelectorAll('[data-user-vehicle]'));
  const currentUserId = <?= (int)$me['id'] ?>;
  let lastFocus = null;

  const setAssignments = ids => {
    const selected = new Set((ids || []).map(Number));
    vehicleChecks.forEach(input => { input.checked = selected.has(Number(input.value)); });
  };


  const syncHierarchyFields = () => {
    if (!fields.parentField || !fields.role) return;
    fields.parentField.hidden = fields.role.value !== 'user';
    if (fields.role.value !== 'user' && fields.parent) fields.parent.value = '';
  };
  fields.role?.addEventListener('change', syncHierarchyFields);

  const open = user => {
    lastFocus = document.activeElement;
    const editing = !!user;
    form.reset();
    fields.action.value = editing ? 'update' : 'create';
    fields.id.value = editing ? user.id : '';
    fields.name.value = editing ? user.name : '';
    fields.email.value = editing ? user.email : '';
    fields.role.value = editing ? user.role : 'user';
    if (fields.parent) fields.parent.value = editing && user.parent_user_id ? String(user.parent_user_id) : '';
    fields.active.checked = editing ? !!user.active : true;
    fields.password.value = '';
    fields.password.required = !editing;
    fields.passwordField.hidden = editing;
    fields.activeField.hidden = !editing;
    setAssignments(editing ? user.vehicle_ids : []);
    syncHierarchyFields();

    fields.kicker.textContent = editing ? 'Editace účtu' : 'Nový účet';
    fields.title.textContent = editing ? user.name : 'Přidat uživatele';
    fields.subtitle.textContent = editing ? user.email : 'Vyplňte údaje a případně rovnou přiřaďte vozidla.';
    fields.submit.textContent = editing ? 'Uložit změny' : 'Přidat uživatele';
    fields.deleteZone.hidden = !editing || Number(user.id) === currentUserId;

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    setTimeout(() => fields.name.focus(), 30);
  };

  const close = () => {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    if (lastFocus) lastFocus.focus();
  };

  document.querySelector('[data-user-create]')?.addEventListener('click', () => open(null));
  document.querySelectorAll('[data-user-edit]').forEach(button => button.addEventListener('click', () => {
    open(JSON.parse(button.dataset.user));
  }));
  document.querySelectorAll('[data-user-close]').forEach(el => el.addEventListener('click', close));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) close(); });
  fields.deleteButton?.addEventListener('click', event => {
    if (!confirm('Opravdu smazat uživatele?')) event.preventDefault();
  });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
