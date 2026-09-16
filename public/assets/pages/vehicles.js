'use strict';

(() => {
  const modal = document.getElementById('vehicleEditorModal');
  const form = document.getElementById('vehicleEditorForm');
  if (!modal || !form) return;

  const byId = id => document.getElementById(id);
  const fields = {
    action: byId('vehicleAction'), id: byId('vehicleId'), name: byId('vehicleName'), vin: byId('vehicleVin'),
    manufacturer: byId('vehicleManufacturer'), powertrain: byId('vehiclePowertrain'), battery: byId('vehicleBattery'),
    batteryNominal: byId('vehicleBatteryNominal'), soh: byId('vehicleSoh'), home: byId('vehicleHome'), tank: byId('vehicleTank'),
    plate: byId('vehiclePlate'), firstRegistration: byId('vehicleFirstRegistration'), acquisitionDate: byId('vehicleAcquisitionDate'),
    acquisitionPrice: byId('vehicleAcquisitionPrice'), currentValue: byId('vehicleCurrentValue'), odometer: byId('vehicleOdometer'),
    title: byId('vehicleEditorTitle'), kicker: byId('vehicleEditorKicker'), subtitle: byId('vehicleEditorSubtitle'),
    submit: byId('vehicleSubmit'), deleteZone: byId('vehicleDeleteZone'), deleteButton: byId('vehicleDeleteButton')
  };
  const userChecks = Array.from(document.querySelectorAll('[data-vehicle-user]'));
  const electricFields = Array.from(document.querySelectorAll('[data-electric-field]'));
  const fuelFields = Array.from(document.querySelectorAll('[data-fuel-field]'));
  let lastFocus = null;

  const value = input => input == null ? '' : String(input);
  const setAssignments = ids => {
    const selected = new Set((ids || []).map(Number));
    userChecks.forEach(input => { input.checked = selected.has(Number(input.value)); });
  };
  const syncPowertrain = () => {
    const type = fields.powertrain.value;
    const electric = type === 'BEV' || type === 'PHEV';
    const fuel = type !== 'BEV';
    electricFields.forEach(el => el.hidden = !electric);
    fuelFields.forEach(el => el.hidden = !fuel);
    fields.battery.required = electric;
  };

  const open = vehicle => {
    lastFocus = document.activeElement;
    const editing = !!vehicle;
    form.reset();
    fields.action.value = editing ? 'update' : 'create';
    fields.id.value = editing ? vehicle.id : '';
    fields.name.value = editing ? value(vehicle.name) : '';
    fields.vin.value = editing ? value(vehicle.vin) : '';
    fields.manufacturer.value = editing ? value(vehicle.manufacturer) : 'SKODA';
    fields.powertrain.value = editing ? value(vehicle.powertrain_type) : 'BEV';
    fields.battery.value = editing ? value(vehicle.battery_kwh) : '77';
    fields.batteryNominal.value = editing ? value(vehicle.battery_nominal_kwh) : '77';
    fields.soh.value = editing ? value(vehicle.soh_manual_pct) : '';
    fields.home.value = editing ? value(vehicle.home_label) : '';
    fields.tank.value = editing ? value(vehicle.fuel_tank_l) : '';
    fields.plate.value = editing ? value(vehicle.registration_plate) : '';
    fields.firstRegistration.value = editing ? value(vehicle.first_registration_date) : '';
    fields.acquisitionDate.value = editing ? value(vehicle.acquisition_date) : '';
    fields.acquisitionPrice.value = editing ? value(vehicle.acquisition_price) : '';
    fields.currentValue.value = editing ? value(vehicle.current_value) : '';
    fields.odometer.value = editing ? value(vehicle.odometer_km) : '';
    setAssignments(editing ? vehicle.user_ids : []);
    syncPowertrain();

    fields.kicker.textContent = editing ? 'Editace vozidla' : 'Nové vozidlo';
    fields.title.textContent = editing ? vehicle.name : 'Přidat vozidlo';
    fields.subtitle.textContent = editing ? vehicle.vin : 'Základní údaje, provozní parametry a přístup uživatelů na jednom místě.';
    fields.submit.textContent = editing ? 'Uložit změny' : 'Přidat vozidlo';
    fields.deleteZone.hidden = !editing;

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

  fields.powertrain.addEventListener('change', syncPowertrain);
  document.querySelector('[data-vehicle-create]')?.addEventListener('click', () => open(null));
  document.querySelectorAll('[data-vehicle-edit]').forEach(button => button.addEventListener('click', () => {
    open(JSON.parse(button.dataset.vehicle));
  }));
  document.querySelectorAll('[data-vehicle-close]').forEach(el => el.addEventListener('click', close));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) close(); });
  fields.deleteButton?.addEventListener('click', event => {
    if (!confirm('Smazat vozidlo včetně všech importovaných jízd?')) event.preventDefault();
  });
})();
