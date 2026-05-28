<?php

// Paths
$borrowFile = __DIR__ . '/borrow.php';
$editFile = __DIR__ . '/edit_application.php';

$borrowContent = file_get_contents($borrowFile);
$editContent = file_get_contents($editFile);

// 1. Extract Period Slots & maps generation from borrow.php
preg_match('/\$periodSlots\s*=\s*\[.*?\];\s*\$periodOrder\s*=\s*array_keys\(\$periodSlots\);.*?\$existingEquipmentReservations\s*=\s*\[\];/s', $borrowContent, $m1);
preg_match('/if\s*\(\$dbError\s*===\s*\'\'\)\s*\{\s*\$equipmentSql\s*=\s*".*?\}\s*\}/s', $borrowContent, $m2);

$mapsData = $m1[0] . "\n" . $m2[0];

// Inject the mapsData into edit_application.php just before handling POST
$editContent = preg_replace('/(\/\/ load space items.*?mysqli_stmt_close\(\$spaceStmt\);\s*\})/s', "$1\n\n$mapsData", $editContent);

// 2. Rewrite POST handler in editContent
$newPostHandler = <<<'EOD'
                // Parse cart_items JSON
                $cartItemsRaw = trim((string)($_POST['cart_items'] ?? '[]'));
                $cartItems = json_decode($cartItemsRaw, true) ?: [];
                $cartEquipments = [];
                $cartSpaceId = null;

                foreach ($cartItems as $item) {
                    if (isset($item['type']) && $item['type'] === 'space') {
                        $cartSpaceId = trim((string)$item['code']);
                    } else {
                        $cartEquipments[] = $item;
                    }
                }

                // 2. Clear old spaces and unlock them
                $oldSpaceStmt = mysqli_prepare($link, 'SELECT space_id FROM space_reservation_items WHERE reservation_id = ?');
                mysqli_stmt_bind_param($oldSpaceStmt, 'i', $reservationId);
                mysqli_stmt_execute($oldSpaceStmt);
                $oldSpaceRes = mysqli_stmt_get_result($oldSpaceStmt);
                while ($os = mysqli_fetch_assoc($oldSpaceRes)) {
                    $freeSpaceStmt = mysqli_prepare($link, 'UPDATE spaces SET space_status = "1" WHERE space_id = ? AND space_status = "2"');
                    mysqli_stmt_bind_param($freeSpaceStmt, 's', $os['space_id']);
                    mysqli_stmt_execute($freeSpaceStmt);
                    mysqli_stmt_close($freeSpaceStmt);
                }
                mysqli_stmt_close($oldSpaceStmt);
                mysqli_query($link, "DELETE FROM space_reservation_items WHERE reservation_id = " . (int)$reservationId);

                // 3. Clear old equipments and unlock them
                $oldEquipStmt = mysqli_prepare($link, 'SELECT equipment_id FROM equipment_reservation_items WHERE reservation_id = ?');
                mysqli_stmt_bind_param($oldEquipStmt, 'i', $reservationId);
                mysqli_stmt_execute($oldEquipStmt);
                $oldEquipRes = mysqli_stmt_get_result($oldEquipStmt);
                while ($oe = mysqli_fetch_assoc($oldEquipRes)) {
                    $freeEquipStmt = mysqli_prepare($link, 'UPDATE equipments SET operation_status = 1 WHERE equipment_id = ? AND operation_status = 2');
                    mysqli_stmt_bind_param($freeEquipStmt, 'i', $oe['equipment_id']);
                    mysqli_stmt_execute($freeEquipStmt);
                    mysqli_stmt_close($freeEquipStmt);
                }
                mysqli_stmt_close($oldEquipStmt);
                mysqli_query($link, "DELETE FROM equipment_reservation_items WHERE reservation_id = " . (int)$reservationId);

                // 4. Insert new Space
                if (!empty($cartSpaceId)) {
                    $spaceConflictStmt = mysqli_prepare($link, 'SELECT COUNT(*) AS conflict_count FROM space_reservation_items sri JOIN reservations r ON r.reservation_id = sri.reservation_id WHERE sri.space_id = ? AND r.approval_status IN ("pending", "approved") AND NOT (r.borrow_end_at <= ? OR r.borrow_start_at >= ?)');
                    mysqli_stmt_bind_param($spaceConflictStmt, 'sss', $cartSpaceId, $borrow_start_at, $borrow_end_at);
                    mysqli_stmt_execute($spaceConflictStmt);
                    $confRes = mysqli_stmt_get_result($spaceConflictStmt);
                    $crow = $confRes ? mysqli_fetch_assoc($confRes) : null;
                    if ($crow && (int)$crow['conflict_count'] > 0) {
                        throw new RuntimeException("場地 {$cartSpaceId} 時段衝突，無法新增。");
                    }
                    mysqli_stmt_close($spaceConflictStmt);

                    $insertSpaceItemStmt = mysqli_prepare($link, 'INSERT INTO space_reservation_items (reservation_id, space_id) VALUES (?, ?)');
                    mysqli_stmt_bind_param($insertSpaceItemStmt, 'is', $reservationId, $cartSpaceId);
                    mysqli_stmt_execute($insertSpaceItemStmt);
                    mysqli_stmt_close($insertSpaceItemStmt);
                }

                // 5. Insert new Equipments
                if (!empty($cartEquipments)) {
                    $selectEquipmentStmt = mysqli_prepare($link, 'SELECT e.equipment_id FROM equipments e WHERE e.equipment_code = ? AND e.operation_status = 1 AND e.equipment_id NOT IN (SELECT eri.equipment_id FROM equipment_reservation_items eri JOIN reservations r ON r.reservation_id = eri.reservation_id WHERE r.approval_status IN ("pending", "approved") AND r.borrow_start_at < ? AND r.borrow_end_at > ?) ORDER BY e.equipment_id ASC LIMIT ?');
                    $insertEquipItemStmt = mysqli_prepare($link, 'INSERT INTO equipment_reservation_items (reservation_id, equipment_id) VALUES (?, ?)');
                    $markEquipUsedStmt = mysqli_prepare($link, 'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1');
                    
                    foreach ($cartEquipments as $item) {
                        $code = trim((string)$item['code']);
                        $qty = (int)$item['quantity'];
                        if ($qty <= 0) continue;

                        mysqli_stmt_bind_param($selectEquipmentStmt, 'sssi', $code, $borrow_end_at, $borrow_start_at, $qty);
                        mysqli_stmt_execute($selectEquipmentStmt);
                        $availRes = mysqli_stmt_get_result($selectEquipmentStmt);
                        $equipmentIds = [];
                        while ($rowEq = $availRes ? mysqli_fetch_assoc($availRes) : null) {
                            $equipmentIds[] = (int)$rowEq['equipment_id'];
                        }
                        if (count($equipmentIds) < $qty) {
                            throw new RuntimeException("器材 {$code} 可用數量不足，無法新增。");
                        }
                        foreach (array_slice($equipmentIds, 0, $qty) as $eid) {
                            mysqli_stmt_bind_param($insertEquipItemStmt, 'ii', $reservationId, $eid);
                            mysqli_stmt_execute($insertEquipItemStmt);
                            
                            // Optional: mark as used if you want strict locks
                            mysqli_stmt_bind_param($markEquipUsedStmt, 'i', $eid);
                            mysqli_stmt_execute($markEquipUsedStmt);
                        }
                    }
                    mysqli_stmt_close($selectEquipmentStmt);
                    mysqli_stmt_close($insertEquipItemStmt);
                    mysqli_stmt_close($markEquipUsedStmt);
                }
EOD;

$editContent = preg_replace('/\/\/ handle equipment\/space removals and additions.*?(?=mysqli_commit\(\$link\);)/s', $newPostHandler . "\n                ", $editContent);

// 3. Extract script block for JS from borrow.php
preg_match('/<script>.*?allEquipments\s*=\s*(.*?);.*?<\/script>/s', $borrowContent, $jsMatch);
if ($jsMatch) {
    // We'll append this script to the end of edit_application.php
    $jsToInject = $jsMatch[0];
    
    // Inject cart init into JS!
    $initCartJS = <<<JS
<?php
\$initialCart = [];
if (!empty(\$space_items)) {
    foreach (\$space_items as \$s) {
        \$initialCart[] = [
            'type' => 'space',
            'code' => \$s['space_id'],
            'name' => \$s['space_name'],
            'quantity' => 1
        ];
    }
}
\$eq_grouped = [];
foreach (\$equipment_items as \$e) {
    if (!isset(\$eq_grouped[\$e['equipment_code']])) {
        \$eq_grouped[\$e['equipment_code']] = [
            'type' => 'equipment',
            'code' => \$e['equipment_code'],
            'name' => \$e['equipment_name'],
            'quantity' => 0
        ];
    }
    \$eq_grouped[\$e['equipment_code']]['quantity']++;
}
foreach (\$eq_grouped as \$g) {
    \$initialCart[] = \$g;
}
?>
    let cartItems = <?php echo json_encode(\$initialCart, JSON_UNESCAPED_UNICODE); ?>;
    
    function saveDraft() {
        console.log("Draft saving disabled in edit mode");
    }
    function updateCart() {
        document.getElementById('cart_items_input').value = JSON.stringify(cartItems);
        renderCartUI();
    }
JS;
    
    $jsToInject = preg_replace('/let cartItems = \[\];/', $initCartJS, $jsToInject);
    
    // replace bottom script of edit_application.php with $jsToInject
    // But be careful to keep goToStep etc from edit_application.php.
    // Actually, goToStep in borrow.php is identical! We can just totally DUMP edit_application.php's script and use borrow's script!
    $editContent = preg_replace('/<script>.*?<\/script>(?![\s\S]*<script>)/s', $jsToInject, $editContent);
}

// 4. Extract Step 3 HTML from borrow.php
preg_match('/<div class="step-content" id="step-content-3">.*?<\/form>/s', $borrowContent, $htmlMatch);
if ($htmlMatch) {
    $step3Html = $htmlMatch[0];
    
    // In edit_application.php, replace <div class="step-content" id="step-content-3"> ... down to </form>
    $editContent = preg_replace('/<div class="step-content" id="step-content-3">.*?<\/form>/s', $step3Html, $editContent);
    
    // Also, edit_application.php uses different submit button. So we need to put back the edit form button:
    // the original edit step 3 bottom had:
    /*
        <div class="form-actions" style="margin-top: 30px; text-align: center;">
            <button type="button" class="btn btn-secondary nav-btn" onclick="goToStep(2)" ...>上一步</button>
            <button type="submit" class="btn btn-primary" ...>儲存修改</button>
        </div>
    */
    $editButtons = <<<HTML
        <div class="form-actions" style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
            <button type="button" class="btn btn-secondary nav-btn" onclick="goToStep(2)" style="width: 150px; padding: 12px; font-size: 16px; border-radius: 8px;">上一步</button>
            <button type="submit" class="btn btn-primary" style="width: 150px; padding: 12px; font-size: 16px; border-radius: 8px; box-shadow: 0 4px 6px rgba(59,130,246,0.3);">儲存修改</button>
        </div>
        <input type="hidden" name="cart_items" id="cart_items_input" value="">
    </form>
HTML;
    $editContent = preg_replace('/<div class="form-actions".*?<\/form>/s', $editButtons, $editContent);
}

// Write back to edit_application_new.php to verify
file_put_contents(__DIR__ . '/edit_application_new.php', $editContent);
echo "Generated edit_application_new.php\n";
