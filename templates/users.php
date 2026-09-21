<?php
$pageTitle = 'Uživatelé';
$showNavigation = true;
$navTitle = 'Správa uživatelů';
$roleLabels = [
    'admin' => 'Administrátor',
    'manager' => 'Správce vozidel',
    'user' => 'Řidič',
];
require __DIR__ . '/partials/header.php';
?>
<main class="wrap narrow admin-list-page">
    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'error' ? 'alert-danger' : 'alert-success' ?> shadow-sm" role="alert"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="card dashboard-card table-card admin-list-card">
        <div class="list-toolbar">
            <div>
                <span class="section-kicker">Administrace</span>
                <h1>Uživatelé</h1>
                <p>Správa účtů, rolí a přístupů k vozidlům. Seznam je stránkovaný po 20 položkách.</p>
            </div>
            <button class="btn btn-primary" type="button" data-user-create>＋ Přidat uživatele</button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle admin-list-table mb-0">
                <thead>
                <tr>
                    <th>Uživatel</th>
                    <th>Role</th>
                    <th>Nadřízený</th>
                    <th>Vozidla</th>
                    <th>Stav</th>
                    <th class="text-end">Akce</th>
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
                        <td><div class="table-primary"><strong><?= h($u['name']) ?></strong><small><?= h($u['email']) ?></small></div></td>
                        <td><span class="badge text-bg-secondary-subtle border"><?= h($roleLabels[$u['role']] ?? $u['role']) ?></span></td>
                        <td><?= h((string)($u['parent_name'] ?? '—')) ?></td>
                        <td><span class="count-badge"><?= (int)$u['vehicle_count'] ?></span></td>
                        <td><span class="status-badge <?= (int)$u['active'] ? 'is-active' : 'is-inactive' ?>"><?= (int)$u['active'] ? 'Aktivní' : 'Neaktivní' ?></span></td>
                        <td class="text-end">
                            <div class="row-actions justify-content-end">
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-user-edit data-user='<?= h((string)$json) ?>'>Upravit</button>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="reset_link">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" title="Poslat obnovu hesla">🔑</button>
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
        <?php require __DIR__ . '/partials/pagination.php'; ?>
    </section>
</main>

<div class="modal fade" id="userEditorModal" data-current-user-id="<?= (int)$me['id'] ?>" tabindex="-1" aria-labelledby="userEditorTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="section-kicker" id="userEditorKicker">Nový účet</span>
                    <h2 class="modal-title" id="userEditorTitle">Přidat uživatele</h2>
                    <p class="modal-subtitle" id="userEditorSubtitle">Vyplňte údaje a případně rovnou přiřaďte vozidla.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>

            <form method="post" id="userEditorForm" class="modal-scroll-form">
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" id="userAction" value="create">
                    <input type="hidden" name="id" id="userId" value="">

                    <div class="admin-modal-grid">
                        <div class="form-panel">
                            <h3>Účet</h3>
                            <div class="modal-field-grid">
                                <label class="span-2"><span>Jméno</span><input class="form-control" id="userName" name="name" required autocomplete="name"></label>
                                <label class="span-2"><span>E-mail</span><input class="form-control" id="userEmail" name="email" type="email" required autocomplete="email"></label>
                                <label id="userPasswordField" class="span-2"><span>Heslo</span><input class="form-control" id="userPassword" name="password" type="password" minlength="8" autocomplete="new-password"><small>Minimálně 8 znaků.</small></label>
                                <?php if ($app->auth()->isAdmin($me)): ?>
                                    <label><span>Role</span><select class="form-select" id="userRole" name="role">
                                        <option value="user">Řidič</option>
                                        <option value="manager">Správce vozidel</option>
                                        <option value="admin">Administrátor</option>
                                    </select></label>
                                    <label id="userParentField"><span>Nadřízený správce</span><select class="form-select" id="userParent" name="parent_user_id">
                                        <option value="">— vyberte správce —</option>
                                        <?php foreach ($managers as $manager): ?><option value="<?= (int)$manager['id'] ?>"><?= h($manager['name']) ?> · <?= h($manager['email']) ?></option><?php endforeach; ?>
                                    </select></label>
                                <?php else: ?>
                                    <input type="hidden" id="userRole" name="role" value="user">
                                    <input type="hidden" id="userParent" name="parent_user_id" value="<?= (int)$me['id'] ?>">
                                <?php endif; ?>
                                <label class="toggle-field" id="userActiveField"><span>Stav účtu</span><span class="toggle-line"><input class="form-check-input" id="userActive" type="checkbox" name="active" checked> Aktivní účet</span></label>
                            </div>
                        </div>

                        <div class="form-panel">
                            <h3>Přiřazená vozidla</h3>
                            <p class="form-panel-help">Řidič může dostat pouze vozidla svého nadřízeného správce. Datum účinnosti chrání historii předchozích vlastníků.</p>
                            <label><span>Změna přiřazení platí od</span><input class="form-control" type="date" name="assignment_effective_date" id="userAssignmentDate" value="<?= date('Y-m-d') ?>"></label>
                            <div class="assignment-list">
                                <?php foreach ($vehicles as $v): ?>
                                    <label class="check assignment">
                                        <input class="form-check-input" type="checkbox" name="vehicle_ids[]" value="<?= (int)$v['id'] ?>" data-user-vehicle>
                                        <span><?= h($v['name']) ?><small><?= h($v['vin']) ?></small></span>
                                    </label>
                                <?php endforeach; ?>
                                <?php if (!$vehicles): ?><p class="empty-assignment">Nejsou založena žádná vozidla.</p><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer admin-modal-actions">
                    <div class="danger-zone me-auto" id="userDeleteZone" hidden>
                        <button class="btn btn-outline-danger" type="submit" name="action" value="delete" id="userDeleteButton">Smazat uživatele</button>
                    </div>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-primary" id="userSubmit">Přidat uživatele</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script defer src="assets/pages/users.js?v=<?= (int)@filemtime(__DIR__ . '/../public/assets/pages/users.js') ?>"></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
