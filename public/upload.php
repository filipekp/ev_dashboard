<?php
    declare(strict_types=1);
    require dirname(__DIR__) . '/src/bootstrap.php';
    $user = $app->auth()->requireLogin();
    $importer = $app->importer();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csv'])) {
        redirect('index.php');
    }
    $selectedVehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $vehicleId         = 0;
    $newVehicle        = FALSE;
    
    try {
        verifyCsrf();
        if ($_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload CSV selhal.');
        }
        if ($_FILES['csv']['size'] > 20 * 1024 * 1024) {
            throw new RuntimeException('CSV je příliš velké (max. 20 MB).');
        }
        
        $tmpPath      = (string)$_FILES['csv']['tmp_name'];
        $originalName = (string)($_FILES['csv']['name'] ?? '');
        $meta         = $importer->inspect($tmpPath, $originalName);
        $csvVin       = $meta['vin'];
        
        if ($csvVin !== NULL) {
            // VIN v názvu CSV je autoritativní: nejprve hledáme vozidlo podle VIN,
            // nezávisle na tom, které vozidlo je právě otevřené v dashboardu.
            $q = $pdo->prepare('SELECT id FROM vehicles WHERE UPPER(vin)=? LIMIT 1');
            $q->execute([$csvVin]);
            $vehicleId = (int)$q->fetchColumn();
            
            if ($vehicleId > 0) {
                if (!$app->auth()->canAccessVehicle($user, $vehicleId)) {
                    throw new RuntimeException('CSV patří již existujícímu vozidlu VIN ' . $csvVin . ', ke kterému nemáte přístup. Požádejte správce o přiřazení vozidla.');
                }
            } else {
                // Vozidlo ještě neexistuje. Vytvoříme bezpečný předvyplněný záznam,
                // přiřadíme ho aktuálnímu uživateli a po importu zobrazíme modal k doplnění údajů.
                $name    = (string)$meta['suggested_name'];
                $battery = (float)$meta['battery_kwh'];
                $nominal = (float)$meta['battery_nominal_kwh'];
                
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare('INSERT INTO vehicles(name,vin,battery_kwh,battery_nominal_kwh) VALUES(?,?,?,?)');
                    $ins->execute([
                        $name,
                        $csvVin,
                        $battery,
                        $nominal
                    ]);
                    $vehicleId = (int)$pdo->lastInsertId();
                    $assign    = $pdo->prepare('INSERT IGNORE INTO user_vehicles(user_id,vehicle_id) VALUES(?,?)');
                    $assign->execute([
                        (int)$user['id'],
                        $vehicleId
                    ]);
                    $pdo->commit();
                    $newVehicle = TRUE;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        } else {
            // Starší / ručně přejmenovaný export bez VIN v názvu: zachováme původní chování.
            $vehicleId = $selectedVehicleId;
            if (!$vehicleId || !$app->auth()->canAccessVehicle($user, $vehicleId)) {
                throw new RuntimeException('Z názvu CSV se nepodařilo rozpoznat VIN. Vyberte vozidlo, ke kterému má být CSV importováno, nebo ponechte původní název exportu obsahující VIN.');
            }
        }
        
        $r                      = $importer->import($vehicleId, $tmpPath, $originalName ?: NULL);
        $formatLabel            = ($r['format'] ?? '') === 'citigo_iv' ? 'Citigo iV' : 'MyŠkoda';
        $_SESSION['vehicle_id'] = $vehicleId;
        
        if ($newVehicle) {
            $_SESSION['new_vehicle_id'] = $vehicleId;
            flash(sprintf('Rozpoznáno nové vozidlo VIN %s. Import dokončen (%s): %d nových jízd, %d přeskočeno. Doplňte údaje o vozidle.', $csvVin, $formatLabel, $r['inserted'], $r['skipped']));
            redirect('index.php?vehicle_id=' . $vehicleId . '&new_vehicle=1');
        }
        
        flash(sprintf('Vozidlo bylo automaticky rozpoznáno. Import dokončen (%s): %d nových jízd, %d přeskočeno.', $formatLabel, $r['inserted'], $r['skipped']));
    } catch (Throwable $e) {
        // Pokud vznikl nový záznam, ale samotný import selhal a žádná jízda se neuložila,
        // uklidíme provizorní vozidlo, aby v administraci nezůstával prázdný záznam.
        if ($newVehicle && $vehicleId > 0) {
            try {
                $q = $pdo->prepare('SELECT COUNT(*) FROM trips WHERE vehicle_id=?');
                $q->execute([$vehicleId]);
                if ((int)$q->fetchColumn() === 0) {
                    $pdo->prepare('DELETE FROM vehicles WHERE id=?')->execute([$vehicleId]);
                }
            } catch (Throwable $cleanupError) { /* původní chyba je důležitější */
            }
        }
        flash('Chyba importu: ' . $e->getMessage(), 'error');
    }
    redirect('index.php' . ($vehicleId > 0 ? '?vehicle_id=' . $vehicleId : ''));
