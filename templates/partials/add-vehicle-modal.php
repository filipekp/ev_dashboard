<?php if (!empty($showAddVehicleModal)): ?>
  <div class="legacy-modal-backdrop" id="addVehicleModal">
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="addVehicleTitle">
      <div class="modal-head">
        <div>
          <small>NOVÉ VOZIDLO</small>
          <h2 id="addVehicleTitle">🚙 Přidat vozidlo</h2>
        </div>
        <a class="modal-close modal-close-link" href="index.php" aria-label="Zavřít">×</a>
      </div>
      <p>Vozidlo bude po založení automaticky přiřazeno k vašemu účtu. To je důležité hlavně pro Kia Connect importy, protože jejich XLSX soubory neobsahují VIN.</p>
      <form method="post" action="index.php" class="modal-form">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="create_self_vehicle">

        <label>Název vozidla
          <input name="name" placeholder="Kia EV3" required autofocus>
        </label>
        <label>Výrobce
          <select name="manufacturer" required>
            <option value="KIA">Kia</option>
            <option value="SKODA">Škoda</option>
            <option value="HYUNDAI">Hyundai</option>
            <option value="VOLKSWAGEN">Volkswagen</option>
            <option value="AUDI">Audi</option>
            <option value="TESLA">Tesla</option>
            <option value="OTHER">Jiný</option>
          </select>
        </label>
        <label>VIN
          <input name="vin" maxlength="32" autocomplete="off" required>
        </label>
        <label>Typ pohonu
          <select name="powertrain_type" id="selfVehiclePowertrain">
            <option value="BEV">BEV – elektromobil</option>
            <option value="PHEV">PHEV – plug-in hybrid</option>
            <option value="HEV">HEV – hybrid</option>
            <option value="PETROL">Benzín</option>
            <option value="DIESEL">Nafta</option>
            <option value="LPG">LPG</option>
            <option value="CNG">CNG</option>
          </select>
        </label>
        <label data-electric-field>Využitelná kapacita baterie (kWh)
          <input name="battery_kwh" type="number" step="0.1" min="0" placeholder="např. 78,0">
        </label>
        <label data-electric-field>Nominální kapacita baterie (kWh) <span>volitelné</span>
          <input name="battery_nominal_kwh" type="number" step="0.1" min="0" placeholder="pokud ji znáte">
        </label>
        <label data-electric-field>SoH z diagnostiky (%) <span>volitelné</span>
          <input name="soh_manual_pct" type="number" step="0.1" min="50" max="110" placeholder="např. 96,5">
        </label>
        <label data-electric-field>Domácí nabíjecí lokalita <span>volitelné</span>
          <input name="home_label" placeholder="např. Olomouc">
        </label>
        <label data-fuel-field>Objem nádrže (l) <span>pro spalovací/hybridní vůz</span>
          <input name="fuel_tank_l" type="number" step="0.1" min="0">
        </label>
        <label>SPZ <span>volitelné</span>
          <input name="registration_plate" maxlength="32">
        </label>
        <label>První registrace <span>volitelné</span>
          <input name="first_registration_date" type="date">
        </label>
        <label>Aktuální tachometr (km) <span>volitelné</span>
          <input name="odometer_km" type="number" step="0.1" min="0">
        </label>

        <div class="modal-actions">
          <a class="btn" href="index.php">Zrušit</a>
          <button class="btn primary">Přidat vozidlo</button>
        </div>
      </form>
    </section>
  </div>

<?php endif; ?>
