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
  const connector = {
    form: byId('vehicleConnectorForm'), panel: byId('vehicleConnectorPanel'), unsupported: byId('vehicleConnectorUnsupported'),
    body: byId('vehicleConnectorBody'), title: byId('vehicleConnectorTitle'), description: byId('vehicleConnectorDescription'),
    status: byId('vehicleConnectorStatus'), csrf: byId('vehicleConnectorCsrf'), vehicleId: byId('vehicleConnectorVehicleId'), provider: byId('vehicleConnectorProvider'),
    docs: byId('vehicleConnectorDocs'), connect: byId('vehicleConnectorConnect'), connected: byId('vehicleConnectorConnected'),
    credentialFlow: byId('vehicleConnectorCredentialFlow'), oauthFlow: byId('vehicleConnectorOauthFlow'),
    oauthHelp: byId('vehicleConnectorOauthHelp'), oauthButton: byId('vehicleConnectorOauthButton'),
    credentialConnectButton: byId('vehicleConnectorCredentialConnectButton'), apiKey: byId('vehicleConnectorApiKey'),
    replacementApiKey: byId('vehicleConnectorReplacementApiKey'), replace: byId('vehicleConnectorReplace'),
    saveCredential: byId('vehicleConnectorSaveCredential'), credentialLabel: byId('vehicleConnectorCredentialLabel'),
    credentialHelp: byId('vehicleConnectorCredentialHelp'), credentialHint: byId('vehicleConnectorCredentialHint'),
    lastSync: byId('vehicleConnectorLastSync'), nextSync: byId('vehicleConnectorNextSync'), expires: byId('vehicleConnectorExpires'),
    rateLimit: byId('vehicleConnectorRateLimit'), error: byId('vehicleConnectorError'), disconnect: byId('vehicleConnectorDisconnect')
  };
  const userChecks = Array.from(document.querySelectorAll('[data-vehicle-user]'));
  const electricFields = Array.from(document.querySelectorAll('[data-electric-field]'));
  const fuelFields = Array.from(document.querySelectorAll('[data-fuel-field]'));
  let lastFocus = null;
  let currentVehicle = null;

  const value = input => input == null ? '' : String(input);
  const formatDate = input => {
    if (!input) return '—';
    const normalized = String(input).replace(' ', 'T');
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? String(input) : date.toLocaleString('cs-CZ', { dateStyle: 'short', timeStyle: 'short' });
  };
  const setAssignments = ids => {
    const selected = new Set((ids || []).map(Number));
    userChecks.forEach(input => { input.checked = selected.has(Number(input.value)); });
  };
  const syncPowertrain = () => {
    const type = fields.powertrain.value;
    const electric = type === 'BEV' || type === 'PHEV';
    const fuel = type !== 'BEV';
    electricFields.forEach(el => { el.hidden = !electric; });
    fuelFields.forEach(el => { el.hidden = !fuel; });
    fields.battery.required = electric;
  };

  const syncConnector = vehicle => {
    if (!connector.panel) return;
    const editing = !!vehicle;
    connector.panel.hidden = !editing;
    if (!editing) return;

    const manufacturerChanged = value(vehicle.manufacturer).toUpperCase() !== value(fields.manufacturer.value).toUpperCase();
    const options = Array.isArray(vehicle.connector_options) ? vehicle.connector_options : [];
    const connection = vehicle.connector || null;
    const option = options.find(item => connection && item.id === connection.provider) || options[0] || null;

    connector.vehicleId.value = value(vehicle.id);
    connector.apiKey.value = '';
    connector.replacementApiKey.value = '';

    if (manufacturerChanged) {
      connector.unsupported.hidden = false;
      connector.unsupported.textContent = 'Výrobce vozidla byl změněn. Nejdříve uložte změny; potom EV Stats nabídne konektor odpovídající nové značce.';
      connector.body.hidden = true;
      connector.title.textContent = 'OEM konektor';
      connector.description.textContent = 'Přímé propojení vozidla s oficiálním API výrobce.';
      connector.status.textContent = 'Čeká na uložení';
      return;
    }

    if (!option) {
      connector.unsupported.hidden = false;
      connector.unsupported.textContent = 'Pro tohoto výrobce zatím není v EV Stats registrován přímý OEM konektor.';
      connector.body.hidden = true;
      connector.title.textContent = 'OEM konektor';
      connector.description.textContent = 'Přímé propojení vozidla s oficiálním API výrobce.';
      connector.status.textContent = 'Není dostupný';
      return;
    }

    connector.unsupported.hidden = true;
    connector.body.hidden = false;
    connector.provider.value = option.id;
    connector.title.textContent = option.label || option.id;
    const schema = option.credentials || {};
    connector.description.textContent = schema.description
      || (option.id === 'skoda_public_api'
        ? 'Oficiální MyŠkoda Public API. API klíč se ukládá pouze šifrovaně a nikdy se neposílá zpět do prohlížeče.'
        : option.id === 'kia_pleos'
          ? 'Oficiální Kia Europe Vehicle Data API přes Pleos. Přihlášení i souhlas se sdílením probíhá přímo u Kia/Pleos.'
          : 'Přímé propojení vozidla s oficiálním API výrobce.');

    const isOauth = schema.type === 'oauth';
    const credentialField = Array.isArray(schema.fields) ? schema.fields[0] : null;
    connector.credentialFlow.hidden = isOauth;
    connector.oauthFlow.hidden = !isOauth;
    connector.credentialConnectButton.hidden = isOauth;
    connector.credentialLabel.textContent = credentialField?.label || 'Credential';
    connector.credentialHelp.textContent = isOauth ? '' : (credentialField?.help || '');
    connector.docs.href = schema.documentation_url || '#';

    if (isOauth) {
      const available = schema.available !== false && connector.panel?.dataset.securityReady === '1';
      connector.oauthHelp.textContent = connector.panel?.dataset.securityReady === '1'
        ? (schema.help || '')
        : 'Nejdříve nastavte VEHICLE_CREDENTIALS_KEY, aby bylo možné OAuth tokeny bezpečně zašifrovat.';
      connector.oauthButton.textContent = schema.connect_label || 'Připojit účet výrobce';
      connector.oauthButton.hidden = !available;
      if (available) {
        const csrf = value(connector.csrf?.value).trim();

        if (csrf !== '') {
          const params = new URLSearchParams({
            vehicle_id: value(vehicle.id),
            provider: option.id,
            csrf
          });
          const connectUrl = schema.connect_url || '#';
          const separator = connectUrl.includes('?') ? '&' : '?';
          connector.oauthButton.href = `${connectUrl}${separator}${params.toString()}`;
          connector.oauthButton.removeAttribute('aria-disabled');
        } else {
          connector.oauthButton.removeAttribute('href');
          connector.oauthButton.setAttribute('aria-disabled', 'true');
          connector.oauthHelp.textContent = 'OAuth připojení nelze spustit: chybí CSRF token. Obnovte stránku a zkuste to znovu.';
        }
      } else {
        connector.oauthButton.removeAttribute('href');
        connector.oauthButton.setAttribute('aria-disabled', 'true');
      }
    }

    const isConnected = !!connection;
    connector.connect.hidden = isConnected;
    connector.connected.hidden = !isConnected;
    connector.apiKey.disabled = isConnected || isOauth;
    connector.replacementApiKey.disabled = !isConnected || isOauth;
    connector.replace.hidden = isOauth;
    connector.saveCredential.hidden = isOauth;

    if (!isConnected) {
      connector.status.textContent = 'Nepřipojeno';
      connector.status.dataset.state = 'idle';
      return;
    }

    const status = value(connection.status);
    connector.status.textContent = status === 'active' ? 'Připojeno' : status === 'error' ? 'Vyžaduje pozornost' : 'Čeká na ověření';
    connector.status.dataset.state = status;
    connector.credentialHint.textContent = value(connection.credential_hint) || 'uloženo';
    connector.lastSync.textContent = formatDate(connection.last_synced_at);
    connector.nextSync.textContent = formatDate(connection.next_sync_at);
    connector.expires.textContent = formatDate(connection.credential_expires_at);
    const remaining = connection.rate_limit_remaining;
    const limit = connection.rate_limit_limit;
    connector.rateLimit.textContent = remaining != null && limit != null ? `${remaining} / ${limit}` : '—';
    const error = value(connection.last_error).trim();
    connector.error.textContent = error;
    connector.error.hidden = error.length === 0;
  };

  const open = vehicle => {
    lastFocus = document.activeElement;
    currentVehicle = vehicle || null;
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
    syncConnector(vehicle);

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
    currentVehicle = null;
    if (lastFocus) lastFocus.focus();
  };

  fields.powertrain.addEventListener('change', syncPowertrain);
  fields.manufacturer.addEventListener('change', () => syncConnector(currentVehicle));
  document.querySelector('[data-vehicle-create]')?.addEventListener('click', () => open(null));
  document.querySelectorAll('[data-vehicle-edit]').forEach(button => button.addEventListener('click', () => {
    open(JSON.parse(button.dataset.vehicle));
  }));
  document.querySelectorAll('[data-vehicle-close]').forEach(el => el.addEventListener('click', close));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) close(); });
  fields.deleteButton?.addEventListener('click', event => {
    if (!confirm('Smazat vozidlo včetně všech importovaných jízd?')) event.preventDefault();
  });
  connector.disconnect?.addEventListener('click', event => {
    if (!confirm('Odpojit OEM konektor? Uložené přístupové údaje budou odstraněny. U konektorů se souhlasem se sdílením mohou být podle podmínek poskytovatele odstraněna i data získaná z API.')) {
      event.preventDefault();
    }
  });

  const editId = Number(new URLSearchParams(window.location.search).get('edit') || 0);
  if (editId > 0) {
    const button = Array.from(document.querySelectorAll('[data-vehicle-edit]')).find(item => {
      try { return Number(JSON.parse(item.dataset.vehicle).id) === editId; } catch (error) { return false; }
    });
    if (button) button.click();
  }
})();
