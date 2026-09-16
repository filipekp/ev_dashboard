'use strict';

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
  const currentUserId = Number(modal.dataset.currentUserId || 0);
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
