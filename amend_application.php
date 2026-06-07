<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=amend_application.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$displayName   = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);
$reservationId = (int)($_GET['reservation_id'] ?? 0);

if ($reservationId <= 0) {
    header('Location: return_management.php');
    exit;
}

$dbError      = '';
$link         = getMysqliConnection($dbError);
$amendError   = '';
$amendSuccess = '';
$revisionData = [];

if ($dbError === '') {
    // 取得所有欄位清單
    $availableCols = [];
    $colRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    if ($colRes) {
        while ($crow = mysqli_fetch_assoc($colRes)) {
            $availableCols[] = (string)$crow['Field'];
        }
        mysqli_free_result($colRes);
    }

    $wantedCols = [
        'reservation_id', 'user_id', 'approval_status', 'revision_data_json', 'revision_deadline',
        'organization_name', 'activity_name', 'participant_count', 'staff_count',
        'activity_coordinator', 'coordinator_phone', 'coordinator_other_contact',
        'vehicle_entry',
        'has_alcohol', 'has_fire', 'has_sales',
        'setup_flags',
        // 明火
        'fire_activity_name', 'fire_date', 'fire_start_time', 'fire_end_time', 'fire_location',
        'fire_staff_json', 'fire_performers', 'fire_oilers', 'fire_extinguishers',
        'fire_security', 'fire_emergency', 'fire_medical',
        // 攤位
        'sales_location', 'sales_count', 'sales_roster_json', 'sales_layout_map',
        // 企劃書
        'proposal_file', 'proposal_uploaded_at',
        // 時間（唯讀）
        'borrow_start_at', 'borrow_end_at',
    ];

    $selectCols = [];
    foreach ($wantedCols as $cn) {
        if (in_array($cn, $availableCols, true)) {
            $selectCols[] = 'r.' . $cn;
        }
    }

    if (empty($selectCols)) {
        $amendError = '資料表欄位不足，無法讀取申請資料。';
        $reservationRow = null;
    } else {
        $checkSql  = 'SELECT ' . implode(', ', $selectCols) . ' FROM reservations r WHERE r.reservation_id = ? AND r.user_id = ? LIMIT 1';
        $checkStmt = mysqli_prepare($link, $checkSql);
        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, 'is', $reservationId, $currentUserId);
            mysqli_stmt_execute($checkStmt);
            $checkResult    = mysqli_stmt_get_result($checkStmt);
            $reservationRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
            mysqli_stmt_close($checkStmt);
        } else {
            $reservationRow = null;
        }
    }

    // 器材 / 空間（僅顯示，不可修改）
    $equipmentItems = [];
    $equipStmt = mysqli_prepare($link, '
        SELECT eri.equipment_id, e.equipment_code, ec.equipment_name
        FROM equipment_reservation_items eri
        JOIN equipments e ON e.equipment_id = eri.equipment_id
        JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
        WHERE eri.reservation_id = ?
        ORDER BY ec.equipment_code ASC
    ');
    if ($equipStmt) {
        mysqli_stmt_bind_param($equipStmt, 'i', $reservationId);
        mysqli_stmt_execute($equipStmt);
        $equipResult = mysqli_stmt_get_result($equipStmt);
        while ($equipRow = $equipResult ? mysqli_fetch_assoc($equipResult) : null) {
            $equipmentItems[] = $equipRow;
        }
        mysqli_stmt_close($equipStmt);
    }

    $spaceItems = [];
    $spaceStmt = mysqli_prepare($link, '
        SELECT sri.space_id, s.space_name, s.capacity
        FROM space_reservation_items sri
        JOIN spaces s ON s.space_id = sri.space_id
        WHERE sri.reservation_id = ?
        ORDER BY s.space_id ASC
    ');
    if ($spaceStmt) {
        mysqli_stmt_bind_param($spaceStmt, 'i', $reservationId);
        mysqli_stmt_execute($spaceStmt);
        $spaceResult = mysqli_stmt_get_result($spaceStmt);
        while ($spaceRow = $spaceResult ? mysqli_fetch_assoc($spaceResult) : null) {
            $spaceItems[] = $spaceRow;
        }
        mysqli_stmt_close($spaceStmt);
    }

    if (!$reservationRow) {
        $amendError = '找不到該申請或無權限修改。';
    } elseif ($reservationRow['approval_status'] === 'pending') {
        $amendError = '您的補件已提交並進入審核，目前無法再修改。';
    } elseif ($reservationRow['approval_status'] !== 'need_revision') {
        $amendError = '該申請不在補件狀態，無法修改。';
    } else {
        // 原始特殊勾選（從 DB，不受 revisionData 影響）
        $originalHasAlcohol = (string)($reservationRow['has_alcohol'] ?? '0');
        $originalHasFire    = (string)($reservationRow['has_fire']    ?? '0');
        $originalHasSales   = (string)($reservationRow['has_sales']   ?? '0');
        // 旗幟：完全不開放補件修改
        // $originalSetupFlags = (string)($reservationRow['setup_flags'] ?? 'no');

        // 初始化 revisionData
        $revisionData = [
            'organization_name'         => $reservationRow['organization_name'] ?? '',
            'activity_name'             => $reservationRow['activity_name'] ?? '',
            'participant_count'         => $reservationRow['participant_count'] ?? '',
            'staff_count'               => $reservationRow['staff_count'] ?? 0,
            'activity_coordinator'      => $reservationRow['activity_coordinator'] ?? '',
            'coordinator_phone'         => $reservationRow['coordinator_phone'] ?? '',
            'coordinator_other_contact' => $reservationRow['coordinator_other_contact'] ?? '',
            'vehicle_entry'             => $reservationRow['vehicle_entry'] ?? 'no',
            'has_alcohol'               => $reservationRow['has_alcohol'] ?? '0',
            'has_fire'                  => $reservationRow['has_fire'] ?? '0',
            'has_sales'                 => $reservationRow['has_sales'] ?? '0',
            // 明火
            'fire_activity_name'        => $reservationRow['fire_activity_name'] ?? '',
            'fire_date'                 => $reservationRow['fire_date'] ?? '',
            'fire_start_time'           => $reservationRow['fire_start_time'] ?? '',
            'fire_end_time'             => $reservationRow['fire_end_time'] ?? '',
            'fire_location'             => $reservationRow['fire_location'] ?? '',
            'fire_staff_json'           => $reservationRow['fire_staff_json'] ?? null,
            'fire_performers'           => $reservationRow['fire_performers'] ?? null,
            'fire_oilers'               => $reservationRow['fire_oilers'] ?? null,
            'fire_extinguishers'        => $reservationRow['fire_extinguishers'] ?? null,
            'fire_security'             => $reservationRow['fire_security'] ?? null,
            'fire_emergency'            => $reservationRow['fire_emergency'] ?? null,
            'fire_medical'              => $reservationRow['fire_medical'] ?? null,
            // 攤位
            'sales_location'            => $reservationRow['sales_location'] ?? '',
            'sales_count'               => $reservationRow['sales_count'] ?? '',
            'sales_roster_json'         => $reservationRow['sales_roster_json'] ?? null,
            'sales_layout_map'          => $reservationRow['sales_layout_map'] ?? '',
            // 企劃書
            'proposal_file'             => $reservationRow['proposal_file'] ?? '',
            'proposal_uploaded_at'      => $reservationRow['proposal_uploaded_at'] ?? '',
            // 時間（唯讀）
            'borrow_start_at'           => $reservationRow['borrow_start_at'] ?? '',
            'borrow_end_at'             => $reservationRow['borrow_end_at'] ?? '',
        ];

        $revisionData['equipment_items'] = $equipmentItems;
        $revisionData['space_items']     = $spaceItems;

        // ── POST 處理 ──────────────────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            // 基本欄位
            $updatedFields = [
                'organization_name'         => trim((string)($_POST['organization_name'] ?? '')),
                'activity_name'             => trim((string)($_POST['activity_name'] ?? '')),
                'participant_count'         => trim((string)($_POST['participant_count'] ?? '')),
                'staff_count'               => (int)($_POST['staff_count'] ?? 0),
                'activity_coordinator'      => trim((string)($_POST['activity_coordinator'] ?? '')),
                'coordinator_phone'         => trim((string)($_POST['coordinator_phone'] ?? '')),
                'coordinator_other_contact' => trim((string)($_POST['coordinator_other_contact'] ?? '')),
                'vehicle_entry'             => isset($_POST['vehicle_entry']) ? 'yes' : 'no',
                // 特殊勾選（後面會強制覆蓋）
                'has_alcohol'               => isset($_POST['has_alcohol']) ? '1' : '0',
                'has_fire'                  => isset($_POST['has_fire']) ? '1' : '0',
                'has_sales'                 => isset($_POST['has_sales']) ? '1' : '0',
            ];

            // 限制：原本未勾選者不可新增
            if ($originalHasAlcohol !== '1') { $updatedFields['has_alcohol'] = '0'; }
            if ($originalHasFire    !== '1') { $updatedFields['has_fire']    = '0'; }
            if ($originalHasSales   !== '1') { $updatedFields['has_sales']   = '0'; }

            // ── 明火人員名單（只有原本有明火才開放）
            $updatedFireStaffJson = $revisionData['fire_staff_json']; // 預設保留原值
            $updatedFirePerformers = $revisionData['fire_performers'];
            $updatedFireOilers     = $revisionData['fire_oilers'];
            $updatedFireExtinguishers = $revisionData['fire_extinguishers'];
            $updatedFireSecurity   = $revisionData['fire_security'];
            $updatedFireEmergency  = $revisionData['fire_emergency'];
            $updatedFireMedical    = $revisionData['fire_medical'];

            if ($originalHasFire === '1') {
                $staffRoleMap = [
                    'fire_performers'    => 'fire_staff_performer',
                    'fire_oilers'        => 'fire_staff_oiler',
                    'fire_extinguishers' => 'fire_staff_extinguisher',
                    'fire_security'      => 'fire_staff_security',
                    'fire_emergency'     => 'fire_staff_emergency',
                    'fire_medical'       => 'fire_staff_medical',
                ];
                $staffData = [];
                foreach ($staffRoleMap as $jsonKey => $postKey) {
                    $names = [];
                    if (isset($_POST[$postKey]) && is_array($_POST[$postKey])) {
                        foreach ($_POST[$postKey] as $n) {
                            $n = trim((string)$n);
                            if ($n !== '') { $names[] = $n; }
                        }
                    }
                    $staffData[$jsonKey] = $names;
                }
                $updatedFireStaffJson      = json_encode($staffData, JSON_UNESCAPED_UNICODE);
                $updatedFirePerformers     = !empty($staffData['fire_performers'])    ? implode("\n", $staffData['fire_performers'])    : null;
                $updatedFireOilers         = !empty($staffData['fire_oilers'])        ? implode("\n", $staffData['fire_oilers'])        : null;
                $updatedFireExtinguishers  = !empty($staffData['fire_extinguishers']) ? implode("\n", $staffData['fire_extinguishers']) : null;
                $updatedFireSecurity       = !empty($staffData['fire_security'])      ? implode("\n", $staffData['fire_security'])      : null;
                $updatedFireEmergency      = !empty($staffData['fire_emergency'])     ? implode("\n", $staffData['fire_emergency'])     : null;
                $updatedFireMedical        = !empty($staffData['fire_medical'])       ? implode("\n", $staffData['fire_medical'])       : null;
            }

            // ── 攤位資料（只有原本有攤位才開放）
            $updatedSalesLocation   = $revisionData['sales_location'];
            $updatedSalesCount      = $revisionData['sales_count'];
            $updatedSalesRosterJson = $revisionData['sales_roster_json'];
            $uploadedSalesMapPath   = null;
            $uploadedSalesMapDbPath = null;

            if ($originalHasSales === '1') {
                $updatedSalesLocation = trim((string)($_POST['sales_location'] ?? ''));
                $updatedSalesCount    = (int)($_POST['sales_count'] ?? 0);
                if ($updatedSalesCount < 1)  { $updatedSalesCount = 1; }
                if ($updatedSalesCount > 20) { $updatedSalesCount = 20; }

                // 攤位清冊
                $salesRoster = [];
                if (isset($_POST['sales_booth_no']) && is_array($_POST['sales_booth_no'])) {
                    foreach ($_POST['sales_booth_no'] as $idx => $no) {
                        $no      = trim((string)$no);
                        $bname   = trim((string)($_POST['sales_booth_name'][$idx]    ?? ''));
                        $bmgr    = trim((string)($_POST['sales_booth_manager'][$idx] ?? ''));
                        $bphone  = trim((string)($_POST['sales_booth_phone'][$idx]   ?? ''));
                        $bcont   = trim((string)($_POST['sales_booth_content'][$idx] ?? ''));
                        if ($no !== '' || $bname !== '') {
                            $salesRoster[] = [
                                'booth_no'      => $no,
                                'booth_name'    => $bname,
                                'booth_manager' => $bmgr,
                                'booth_phone'   => $bphone,
                                'booth_content' => $bcont,
                            ];
                        }
                    }
                }
                $updatedSalesRosterJson = empty($salesRoster) ? null : json_encode($salesRoster, JSON_UNESCAPED_UNICODE);

                // 攤位圖冊上傳
                if (isset($_FILES['sales_layout_map']) && $_FILES['sales_layout_map']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $sFile = $_FILES['sales_layout_map'];
                    if ($sFile['error'] !== UPLOAD_ERR_OK) {
                        $amendError = '攤位圖冊上傳失敗（錯誤碼：' . (int)$sFile['error'] . '）。';
                    } else {
                        $maxBytes = 5 * 1024 * 1024;
                        if ((int)$sFile['size'] > $maxBytes) {
                            $amendError = '攤位圖冊大小不可超過 5MB。';
                        } else {
                            if (class_exists('finfo')) {
                                $finfo  = new finfo(FILEINFO_MIME_TYPE);
                                $smime  = (string)$finfo->file($sFile['tmp_name']);
                            } else {
                                $smime = (string)(mime_content_type($sFile['tmp_name']) ?: '');
                            }
                            if (!in_array($smime, ['image/jpeg', 'image/png'], true)) {
                                $amendError = '攤位圖冊僅支援 JPG 與 PNG 格式。';
                            } else {
                                $salesMapDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sales_maps';
                                if (!is_dir($salesMapDir) && !mkdir($salesMapDir, 0755, true) && !is_dir($salesMapDir)) {
                                    $amendError = '建立上傳目錄失敗。';
                                } else {
                                    $ext        = ($smime === 'image/png') ? 'png' : 'jpg';
                                    $targetName = time() . '_sales_map_' . $reservationId . '.' . $ext;
                                    $targetPath = $salesMapDir . DIRECTORY_SEPARATOR . $targetName;
                                    if (!move_uploaded_file($sFile['tmp_name'], $targetPath)) {
                                        $amendError = '攤位圖冊儲存失敗。';
                                    } else {
                                        $uploadedSalesMapPath   = $targetPath;
                                        $uploadedSalesMapDbPath = 'uploads/sales_maps/' . $targetName;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // ── 企劃書上傳 ──
            $uploadedProposalPath   = null;
            $uploadedProposalDbPath = null;
            $uploadedProposalAt     = null;

            if ($amendError === '' && isset($_FILES['proposal_file']) && $_FILES['proposal_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $proposalFile = $_FILES['proposal_file'];
                if ($proposalFile['error'] !== UPLOAD_ERR_OK) {
                    $amendError = '企劃書上傳失敗（錯誤碼：' . (int)$proposalFile['error'] . '）。';
                } else {
                    $maxBytes = 5 * 1024 * 1024;
                    if ((int)$proposalFile['size'] > $maxBytes) {
                        $amendError = '企劃書大小不可超過 5MB。';
                    } else {
                        if (class_exists('finfo')) {
                            $finfo2 = new finfo(FILEINFO_MIME_TYPE);
                            $pmime  = (string)$finfo2->file($proposalFile['tmp_name']);
                        } elseif (function_exists('mime_content_type')) {
                            $pmime  = (string)mime_content_type($proposalFile['tmp_name']);
                        } else {
                            $pext  = strtolower(pathinfo((string)$proposalFile['name'], PATHINFO_EXTENSION));
                            $pmime = ($pext === 'pdf') ? 'application/pdf' : '';
                        }

                        if ($pmime !== 'application/pdf') {
                            $amendError = '企劃書格式不支援，僅接受 PDF。';
                        } else {
                            $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'proposals';
                            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                                $amendError = '建立上傳目錄失敗。';
                            } else {
                                $safeBase   = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo((string)$proposalFile['name'], PATHINFO_FILENAME));
                                $targetName = time() . '_' . $safeBase . '.pdf';
                                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
                                if (!move_uploaded_file($proposalFile['tmp_name'], $targetPath)) {
                                    $amendError = '企劃書儲存失敗。';
                                } else {
                                    $uploadedProposalPath   = $targetPath;
                                    $uploadedProposalDbPath = 'uploads/proposals/' . $targetName;
                                    $uploadedProposalAt     = date('Y-m-d H:i:s');
                                }
                            }
                        }
                    }
                }
            }

            // ── 驗證必填 ──
            if ($amendError === '') {
                if ($updatedFields['organization_name'] === '') {
                    $amendError = '請填寫單位名稱。';
                } elseif ($updatedFields['activity_name'] === '') {
                    $amendError = '請填寫活動名稱。';
                } elseif ($updatedFields['activity_coordinator'] === '') {
                    $amendError = '請填寫活動負責人。';
                } elseif ($updatedFields['coordinator_phone'] === '') {
                    $amendError = '請填寫聯絡電話。';
                }
            }

            // ── 寫入 DB ──
            if ($amendError === '') {
                mysqli_begin_transaction($link);
                try {
                    // 基本欄位更新
                    $baseUpdateCols  = [];
                    $baseUpdateVals  = [];
                    $baseUpdateTypes = '';

                    $fieldsToWrite = array_merge($updatedFields, [
                        'fire_staff_json'    => $updatedFireStaffJson,
                        'fire_performers'    => $updatedFirePerformers,
                        'fire_oilers'        => $updatedFireOilers,
                        'fire_extinguishers' => $updatedFireExtinguishers,
                        'fire_security'      => $updatedFireSecurity,
                        'fire_emergency'     => $updatedFireEmergency,
                        'fire_medical'       => $updatedFireMedical,
                        'sales_location'     => $updatedSalesLocation,
                        'sales_count'        => $updatedSalesCount,
                        'sales_roster_json'  => $updatedSalesRosterJson,
                    ]);

                    foreach ($fieldsToWrite as $key => $value) {
                        if (!in_array($key, $availableCols, true)) { continue; }
                        $baseUpdateCols[]  = "{$key} = ?";
                        $baseUpdateVals[]  = $value;
                        $baseUpdateTypes  .= is_int($value) ? 'i' : 's';
                    }

                    $baseUpdateVals[]  = $reservationId;
                    $baseUpdateTypes  .= 'i';

                    $baseSql  = 'UPDATE reservations SET ' . implode(', ', $baseUpdateCols) . ', approval_status = "pending", updated_at = NOW() WHERE reservation_id = ?';
                    $baseStmt = mysqli_prepare($link, $baseSql);
                    if (!$baseStmt) {
                        throw new RuntimeException('準備更新語句失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($baseStmt, $baseUpdateTypes, ...$baseUpdateVals);
                    mysqli_stmt_execute($baseStmt);
                    mysqli_stmt_close($baseStmt);

                    // 攤位圖冊
                    if ($uploadedSalesMapDbPath !== null && in_array('sales_layout_map', $availableCols, true)) {
                        $mapStmt = mysqli_prepare($link, 'UPDATE reservations SET sales_layout_map = ?, updated_at = NOW() WHERE reservation_id = ?');
                        if (!$mapStmt) { throw new RuntimeException('準備更新攤位圖冊欄位失敗：' . mysqli_error($link)); }
                        mysqli_stmt_bind_param($mapStmt, 'si', $uploadedSalesMapDbPath, $reservationId);
                        mysqli_stmt_execute($mapStmt);
                        mysqli_stmt_close($mapStmt);
                    }

                    // 企劃書
                    if ($uploadedProposalDbPath !== null) {
                        if (in_array('proposal_file', $availableCols, true) && in_array('proposal_uploaded_at', $availableCols, true)) {
                            $pStmt = mysqli_prepare($link, 'UPDATE reservations SET proposal_file = ?, proposal_uploaded_at = ?, updated_at = NOW() WHERE reservation_id = ?');
                            if (!$pStmt) { throw new RuntimeException('準備更新企劃書欄位失敗：' . mysqli_error($link)); }
                            mysqli_stmt_bind_param($pStmt, 'ssi', $uploadedProposalDbPath, $uploadedProposalAt, $reservationId);
                            mysqli_stmt_execute($pStmt);
                            mysqli_stmt_close($pStmt);
                        } else {
                            throw new RuntimeException('資料表尚未建立 proposal_file / proposal_uploaded_at 欄位。');
                        }
                    }

                    mysqli_commit($link);
                    $amendSuccess = '補件已提交，已重新進入審核流程。';

                    // 更新 revisionData 顯示用
                    $revisionData = array_merge($revisionData, $updatedFields, [
                        'fire_staff_json'   => $updatedFireStaffJson,
                        'sales_location'    => $updatedSalesLocation,
                        'sales_count'       => $updatedSalesCount,
                        'sales_roster_json' => $updatedSalesRosterJson,
                    ]);
                    if ($uploadedSalesMapDbPath   !== null) { $revisionData['sales_layout_map']    = $uploadedSalesMapDbPath; }
                    if ($uploadedProposalDbPath   !== null) { $revisionData['proposal_file']        = $uploadedProposalDbPath; }
                    if ($uploadedProposalAt       !== null) { $revisionData['proposal_uploaded_at'] = $uploadedProposalAt; }

                } catch (Throwable $e) {
                    mysqli_rollback($link);
                    if ($uploadedSalesMapPath   !== null && is_file($uploadedSalesMapPath))   { @unlink($uploadedSalesMapPath); }
                    if ($uploadedProposalPath   !== null && is_file($uploadedProposalPath))   { @unlink($uploadedProposalPath); }
                    $amendError = $e->getMessage();
                }
            }

            // 寄送通知信
            if ($amendSuccess !== '') {
                $userEmail = null;
                $uStmt = mysqli_prepare($link, 'SELECT email FROM users WHERE user_id = ? LIMIT 1');
                if ($uStmt) {
                    mysqli_stmt_bind_param($uStmt, 's', $currentUserId);
                    mysqli_stmt_execute($uStmt);
                    $ures = mysqli_stmt_get_result($uStmt);
                    if ($urow = mysqli_fetch_assoc($ures)) { $userEmail = $urow['email']; }
                    mysqli_stmt_close($uStmt);
                }
                if (!empty($userEmail)) {
                    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                        require_once __DIR__ . '/lib/PHPMailer/Exception.php';
                        require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
                        require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
                    }
                    require_once __DIR__ . '/config/mail.php';
                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        if (empty($MAIL_ENABLED) || empty($MAIL_USERNAME) || empty($MAIL_PASSWORD)) {
                            throw new RuntimeException('郵件設定未啟用或未完成。');
                        }
                        $mailFrom = !empty($MAIL_FROM) ? $MAIL_FROM : $MAIL_USERNAME;
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $MAIL_USERNAME;
                        $mail->Password   = $MAIL_PASSWORD;
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                        $mail->Port       = 465;
                        $mail->CharSet    = 'UTF-8';
                        $mail->setFrom($mailFrom, $MAIL_FROM_NAME ?? '器材借用系統');
                        $mail->addAddress($userEmail, $displayName);
                        $mail->isHTML(true);
                        $mail->Subject = '【系統通知】申請補件已提交';
                        $mail->Body    = "您好，{$displayName}：<br><br>您的申請（單號：{$reservationId}）補件已提交成功，目前狀態為<b>「審核中」</b>。管理團隊將儘速處理，審核結果會再通知您。<br><br>感謝您的使用！";
                        $mail->AltBody = "您好，{$displayName}：\n\n您的申請（單號：{$reservationId}）補件已提交成功，目前狀態為「審核中」。管理團隊將儘速處理，審核結果會再通知您。\n\n感謝您的使用！";
                        $mail->send();
                    } catch (Exception $e) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " 補件通知寄送失敗 (to: {$userEmail}): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
            }
        }
    }
}

// 解析明火人員 JSON 供回填（已有資料時）
$fireStaffDecoded = [];
if (!empty($revisionData['fire_staff_json'])) {
    $tmp = json_decode((string)$revisionData['fire_staff_json'], true);
    if (is_array($tmp)) { $fireStaffDecoded = $tmp; }
}

// 解析攤位清冊 JSON 供回填
$salesRosterDecoded = [];
if (!empty($revisionData['sales_roster_json'])) {
    $tmp = json_decode((string)$revisionData['sales_roster_json'], true);
    if (is_array($tmp)) { $salesRosterDecoded = $tmp; }
}

// 補件前各區段是否可編輯
$canEditAlcohol = isset($originalHasAlcohol) && ($originalHasAlcohol === '1');
$canEditFire    = isset($originalHasFire)    && ($originalHasFire    === '1');
$canEditSales   = isset($originalHasSales)   && ($originalHasSales   === '1');
// 旗幟完全不開放

// 明火時間輔助
$fireStartH = ''; $fireStartM = ''; $fireEndH = ''; $fireEndM = '';
if (!empty($revisionData['fire_start_time'])) {
    [$fireStartH, $fireStartM] = explode(':', $revisionData['fire_start_time'] . ':00');
}
if (!empty($revisionData['fire_end_time'])) {
    [$fireEndH, $fireEndM] = explode(':', $revisionData['fire_end_time'] . ':00');
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>補件修改｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        .amend-header {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .amend-header h3 { margin: 0 0 5px 0; color: #1565c0; }
        .amend-header p  { margin: 0; color: #555; font-size: 14px; }
        .section-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .section-card h4 {
            color: #1e40af;
            margin: 0 0 12px 0;
            font-size: 15px;
            font-weight: bold;
            border-bottom: 1px solid #e8eef7;
            padding-bottom: 8px;
        }
        .readonly-block {
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 12px 15px;
            color: #555;
            font-size: 14px;
        }
        .disabled-note { font-size: 13px; color: #999; margin: 4px 0 8px; }
        table.staff-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.staff-table th { background: #f3f6fc; color: #333; padding: 8px; font-weight: 600; border: 1px solid #d4dbe8; }
        table.staff-table td { padding: 6px 8px; border: 1px solid #d4dbe8; }
        .btn-add-staff {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: 8px; padding: 6px 14px;
            background: #1e40af; color: #fff;
            border: none; border-radius: 5px; cursor: pointer; font-size: 13px;
        }
        .btn-add-staff:hover { background: #1e3a8a; }
        .btn-del-row { background: #dc3545; color:#fff; border:none; border-radius:4px; padding:3px 8px; cursor:pointer; font-size:12px; }
        table.booth-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.booth-table th { background: #f3f6fc; color: #333; padding: 8px; font-weight: 600; border: 1px solid #d4dbe8; }
        table.booth-table td { padding: 6px 8px; border: 1px solid #d4dbe8; }
        .btn-add-booth {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: 10px; padding: 7px 16px;
            background: #374151; color: #fff;
            border: none; border-radius: 5px; cursor: pointer; font-size: 13px;
        }
        .btn-add-booth:hover { background: #1f2937; }
        .loc-option-group { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 8px; }
        .loc-option-group label { display: flex; align-items: center; gap: 7px; cursor: pointer; }
        .amend-success-box {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            border-radius: 6px;
            padding: 18px 20px;
            margin-bottom: 16px;
        }
        .amend-success-box p { margin: 0 0 8px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/nav.php'; ?>
    <div class="container">
        <main class="main-content">
            <section class="borrow-page">
                <h2>補件修改</h2>

                <?php if ($dbError !== ''): ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if ($amendError !== ''): ?>
                    <div class="login-alert"><?php echo htmlspecialchars($amendError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if ($amendSuccess !== ''): ?>
                    <div class="amend-success-box">
                        <p><strong><?php echo htmlspecialchars($amendSuccess, ENT_QUOTES, 'UTF-8'); ?></strong></p>
                        <p style="color:#555; font-size:14px;">您的補件資料已提交，審核人員將重新審核此申請，結果將以 Email 通知您。</p>
                        <a href="return_management.php" class="btn-primary" style="display:inline-block; margin-top:8px;">返回申請列表</a>
                    </div>
                <?php endif; ?>

                <?php if ($dbError === '' && $amendSuccess === '' && $amendError === '' || ($amendError !== '' && $amendSuccess === '' && $dbError === '')): ?>
                    <?php if ($amendError === '' || ($amendError !== '' && !empty($revisionData))): ?>
                    <div class="amend-header">
                        <h3>補件說明</h3>
                        <p>審核人員要求您修改此申請。請更新下方表單後重新提交，系統將重新進入審核流程。<br>
                           <strong style="color:#c62828;">※ 補件提交後即無法再次修改，請確認後再送出。</strong></p>
                    </div>

                    <section class="card borrow-form-card">
                        <form method="post" enctype="multipart/form-data" class="borrow-form"
                              action="amend_application.php?reservation_id=<?php echo (int)$reservationId; ?>" novalidate>
                            <h3 class="step-title" style="margin-bottom: 10px;">修改申請內容</h3>

                            <?php if ($amendError !== ''): ?>
                                <div class="login-alert" style="margin-bottom:14px;"><?php echo htmlspecialchars($amendError, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>

                            <!-- ══ 基本資料 ══ -->
                            <div class="section-card">
                                <h4>基本資料</h4>

                                <div class="form-group">
                                    <label for="organization_name">單位名稱 / 主辦社團 <span style="color:red">*</span></label>
                                    <input type="text" id="organization_name" name="organization_name" class="form-control"
                                           placeholder="請輸入主辦單位名稱"
                                           value="<?php echo htmlspecialchars((string)($revisionData['organization_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="activity_name">活動名稱 <span style="color:red">*</span></label>
                                    <input type="text" id="activity_name" name="activity_name" class="form-control"
                                           placeholder="請輸入活動名稱"
                                           value="<?php echo htmlspecialchars((string)($revisionData['activity_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div style="display:flex; gap:15px; flex-wrap:wrap; margin-bottom:15px;">
                                    <div class="form-group" style="flex:1; min-width:150px;">
                                        <label for="participant_count">活動對象人數 <span style="color:red">*</span></label>
                                        <select id="participant_count" name="participant_count" class="form-control" required style="padding:8px;">
                                            <option value="" <?php echo (($revisionData['participant_count'] ?? '') === '') ? 'selected' : ''; ?>>請選擇</option>
                                            <?php foreach (['50人以下','50~100人','100~200人','200人以上'] as $opt): ?>
                                            <option value="<?php echo $opt; ?>" <?php echo (($revisionData['participant_count'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1; min-width:150px;">
                                        <label for="staff_count">工作人員人數 <span style="color:red">*</span></label>
                                        <input type="number" id="staff_count" name="staff_count" class="form-control" placeholder="請輸入人數" min="1"
                                               value="<?php echo htmlspecialchars((string)($revisionData['staff_count'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                    </div>
                                </div>

                                <div style="display:flex; gap:15px; flex-wrap:wrap; margin-bottom:15px;">
                                    <div class="form-group" style="flex:1; min-width:150px; margin-bottom:0;">
                                        <label for="activity_coordinator">活動負責人 <span style="color:red">*</span></label>
                                        <input type="text" id="activity_coordinator" name="activity_coordinator" class="form-control"
                                               placeholder="請輸入活動負責人姓名"
                                               value="<?php echo htmlspecialchars((string)($revisionData['activity_coordinator'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                    </div>
                                    <div class="form-group" style="flex:1; min-width:150px; margin-bottom:0;">
                                        <label for="coordinator_phone">聯絡電話 <span style="color:red">*</span></label>
                                        <input type="text" id="coordinator_phone" name="coordinator_phone" class="form-control"
                                               placeholder="請輸入聯絡電話"
                                               value="<?php echo htmlspecialchars((string)($revisionData['coordinator_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="coordinator_other_contact">其他聯絡方式</label>
                                    <input type="text" id="coordinator_other_contact" name="coordinator_other_contact" class="form-control"
                                           placeholder="請輸入其他聯絡方式（如 Email）"
                                           value="<?php echo htmlspecialchars((string)($revisionData['coordinator_other_contact'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>

                            <!-- ══ 特殊需求 ══ -->
                            <div class="section-card">
                                <h4>特殊需求</h4>
                                <?php if (!$canEditAlcohol && !$canEditFire && !$canEditSales): ?>
                                    <p class="disabled-note">※ 以下酒精、明火、攤販項目申請時未勾選，補件時不可新增。</p>
                                <?php endif; ?>
                                <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
                                    <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                        <input type="checkbox" name="vehicle_entry" value="yes" <?php echo (($revisionData['vehicle_entry'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                        <span>需要車輛進場</span>
                                    </label>
                                    <label style="display:flex; align-items:center; gap:8px; margin:0; <?php echo !$canEditAlcohol ? 'opacity:0.5; cursor:not-allowed;' : ''; ?>">
                                        <input type="checkbox" name="has_alcohol" value="1"
                                               <?php echo ($canEditAlcohol && ($revisionData['has_alcohol'] ?? '0') === '1') ? 'checked' : ''; ?>
                                               <?php echo !$canEditAlcohol ? 'disabled' : ''; ?>>
                                        <span>有酒精</span><?php if (!$canEditAlcohol): ?><small style="color:#aaa;">（原未勾選）</small><?php endif; ?>
                                    </label>
                                    <label style="display:flex; align-items:center; gap:8px; margin:0; <?php echo !$canEditFire ? 'opacity:0.5; cursor:not-allowed;' : ''; ?>">
                                        <input type="checkbox" name="has_fire" value="1"
                                               <?php echo ($canEditFire && ($revisionData['has_fire'] ?? '0') === '1') ? 'checked' : ''; ?>
                                               <?php echo !$canEditFire ? 'disabled' : ''; ?>>
                                        <span>有明火</span><?php if (!$canEditFire): ?><small style="color:#aaa;">（原未勾選）</small><?php endif; ?>
                                    </label>
                                    <label style="display:flex; align-items:center; gap:8px; margin:0; <?php echo !$canEditSales ? 'opacity:0.5; cursor:not-allowed;' : ''; ?>">
                                        <input type="checkbox" name="has_sales" value="1"
                                               <?php echo ($canEditSales && ($revisionData['has_sales'] ?? '0') === '1') ? 'checked' : ''; ?>
                                               <?php echo !$canEditSales ? 'disabled' : ''; ?>>
                                        <span>需擺攤販售</span><?php if (!$canEditSales): ?><small style="color:#aaa;">（原未勾選）</small><?php endif; ?>
                                    </label>
                                </div>

                                <!-- 旗幟：完全唯讀 -->
                                <div style="margin-top:10px;">
                                    <label>申請插立旗幟</label>
                                    <div class="readonly-block" style="margin-top:6px;">
                                        <?php
                                        $sfVal = $reservationRow['setup_flags'] ?? 'no';
                                        echo ($sfVal === 'yes') ? '是（不開放補件修改）' : '否';
                                        ?>
                                    </div>
                                    <input type="hidden" name="setup_flags" value="<?php echo htmlspecialchars((string)($reservationRow['setup_flags'] ?? 'no'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <p class="disabled-note">※ 旗幟相關資料不開放補件修改。</p>
                                </div>
                            </div>

                            <!-- ══ 明火人員名單 ══ -->
                            <?php if ($canEditFire): ?>
                            <div class="section-card">
                                <h4>明火工作人員名單</h4>
                                <p class="disabled-note" style="color:#555;">請更新各角色人員名單（可新增或刪除）。</p>
                                <?php
                                $fireRoles = [
                                    'fire_staff_performer[]'    => ['key' => 'fire_performers',    'label' => '表演者',   'tableId' => 'amend_table_performer'],
                                    'fire_staff_oiler[]'        => ['key' => 'fire_oilers',        'label' => '上油人員', 'tableId' => 'amend_table_oiler'],
                                    'fire_staff_extinguisher[]' => ['key' => 'fire_extinguishers', 'label' => '滅火人員', 'tableId' => 'amend_table_extinguisher'],
                                    'fire_staff_security[]'     => ['key' => 'fire_security',      'label' => '安保人員', 'tableId' => 'amend_table_security'],
                                    'fire_staff_emergency[]'    => ['key' => 'fire_emergency',     'label' => '緊急應變人員', 'tableId' => 'amend_table_emergency'],
                                    'fire_staff_medical[]'      => ['key' => 'fire_medical',       'label' => '醫護人員', 'tableId' => 'amend_table_medical'],
                                ];
                                $fireStaffJsonKey = [
                                    'fire_staff_performer[]'    => 'fire_performers',
                                    'fire_staff_oiler[]'        => 'fire_oilers',
                                    'fire_staff_extinguisher[]' => 'fire_extinguishers',
                                    'fire_staff_security[]'     => 'fire_security',
                                    'fire_staff_emergency[]'    => 'fire_emergency',
                                    'fire_staff_medical[]'      => 'fire_medical',
                                ];
                                foreach ($fireRoles as $inputName => $meta):
                                    $jsonKey   = $fireStaffJsonKey[$inputName];
                                    $existing  = (array)($fireStaffDecoded[$jsonKey] ?? []);
                                    if (empty($existing)) { $existing = ['']; } // 預設一列空白
                                    $tableId   = $meta['tableId'];
                                ?>
                                <div style="margin-bottom:18px;">
                                    <label style="font-weight:600; color:#333;"><?php echo $meta['label']; ?></label>
                                    <table class="staff-table" id="<?php echo $tableId; ?>" style="margin-top:6px;">
                                        <thead><tr><th>姓名</th><th style="width:60px;">操作</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($existing as $nm): ?>
                                            <tr>
                                                <td><input type="text" name="<?php echo $inputName; ?>" class="form-control" placeholder="請輸入姓名" value="<?php echo htmlspecialchars((string)$nm, ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td style="text-align:center;"><button type="button" class="btn-del-row" onclick="delStaffRow(this)">刪除</button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <button type="button" class="btn-add-staff" onclick="addAmendStaffRow('<?php echo $tableId; ?>', '<?php echo $inputName; ?>')">＋ 新增人員</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <!-- ══ 攤位詳細資料 ══ -->
                            <?php if ($canEditSales): ?>
                            <div class="section-card">
                                <h4>攤位詳細資料</h4>

                                <div class="form-group">
                                    <label>攤位地點 <span style="color:red">*</span></label>
                                    <div class="loc-option-group">
                                        <?php
                                        $locs = ['風華再現廣場 - 單側', '風華再現廣場 - 雙側', '真善美聖廣場'];
                                        foreach ($locs as $loc):
                                            $lid = 'loc_' . md5($loc);
                                        ?>
                                        <label>
                                            <input type="radio" id="<?php echo $lid; ?>" name="sales_location"
                                                   value="<?php echo htmlspecialchars($loc, ENT_QUOTES, 'UTF-8'); ?>"
                                                   <?php echo (($revisionData['sales_location'] ?? '') === $loc) ? 'checked' : ''; ?>>
                                            <?php echo htmlspecialchars($loc, ENT_QUOTES, 'UTF-8'); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="sales_count">攤位數量 (至多 20) <span style="color:red">*</span></label>
                                    <input type="number" id="sales_count" name="sales_count" class="form-control"
                                           placeholder="請輸入數量" min="1" max="20"
                                           value="<?php echo htmlspecialchars((string)($revisionData['sales_count'] ?: 1), ENT_QUOTES, 'UTF-8'); ?>"
                                           oninput="if(this.value>20)this.value=20; if(this.value!==''&&this.value<1)this.value=1;"
                                           onchange="syncBoothCount(this.value)">
                                </div>

                                <!-- 攤位圖冊 -->
                                <div style="margin-bottom:18px;">
                                    <h4 style="border:none; padding:0; margin-bottom:8px; font-size:14px;">位置照片 / 攤位配置圖</h4>
                                    <p style="color:#64748b; font-size:13px; margin-bottom:8px;">請上傳攤位配置圖（接受 JPG、PNG 格式，上限 5MB）。</p>
                                    <?php if (!empty($revisionData['sales_layout_map'])): ?>
                                    <div style="margin-bottom:8px; font-size:13px; color:#444;">
                                        目前圖冊：
                                        <a href="<?php echo htmlspecialchars((string)$revisionData['sales_layout_map'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                            <?php echo htmlspecialchars(basename((string)$revisionData['sales_layout_map']), ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <span id="amend_sales_map_display" style="font-size:14px; color:#1554b9; font-weight:500; display:block; margin-bottom:5px;"></span>
                                    <input type="file" id="sales_layout_map" name="sales_layout_map" class="form-control"
                                           accept="image/png, image/jpeg, image/jpg" style="padding:6px;"
                                           onchange="if(this.files.length>0){document.getElementById('amend_sales_map_display').innerText='已選擇：'+this.files[0].name;}else{document.getElementById('amend_sales_map_display').innerText='';}">
                                </div>

                                <!-- 攤位清冊 -->
                                <h4 style="border:none; padding:0; margin-bottom:8px; font-size:14px;">攤位清冊</h4>
                                <p style="color:#64748b; font-size:13px; margin-bottom:12px;">請列出各攤位名冊。可點擊「＋ 新增攤位」增加列數。</p>
                                <div style="overflow-x:auto;">
                                    <table class="booth-table" id="amend_booth_table">
                                        <thead>
                                            <tr>
                                                <th style="width:10%;">攤位編號</th>
                                                <th style="width:22%;">攤位名稱</th>
                                                <th style="width:18%;">負責人</th>
                                                <th style="width:18%;">聯絡電話</th>
                                                <th style="width:24%;">販售內容</th>
                                                <th style="width:8%;">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody id="amend_booth_tbody">
                                        <?php
                                        $boothRows = !empty($salesRosterDecoded) ? $salesRosterDecoded : [[]];
                                        foreach ($boothRows as $br): ?>
                                            <tr>
                                                <td><input type="text" name="sales_booth_no[]" class="form-control" placeholder="數字" value="<?php echo htmlspecialchars((string)($br['booth_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="sales_booth_name[]" class="form-control" placeholder="名稱" value="<?php echo htmlspecialchars((string)($br['booth_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="sales_booth_manager[]" class="form-control" placeholder="姓名" value="<?php echo htmlspecialchars((string)($br['booth_manager'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="sales_booth_phone[]" class="form-control" placeholder="電話" value="<?php echo htmlspecialchars((string)($br['booth_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="sales_booth_content[]" class="form-control" placeholder="販售內容" value="<?php echo htmlspecialchars((string)($br['booth_content'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td style="text-align:center;"><button type="button" class="btn-del-row" onclick="delBoothRow(this)">刪除</button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" class="btn-add-booth" onclick="addAmendBoothRow()" style="margin-top:12px;">＋ 新增攤位</button>
                            </div>
                            <?php endif; ?>

                            <!-- ══ 企劃書 ══ -->
                            <div class="section-card">
                                <h4>活動企劃書</h4>
                                <p style="color:#64748b; font-size:13px; margin-bottom:8px;">若需補交或更新企劃書，請上傳 PDF 檔，大小上限 5MB。</p>
                                <?php if (!empty($revisionData['proposal_file'])): ?>
                                <div style="margin-bottom:8px; font-size:13px; color:#444;">
                                    目前檔案：
                                    <a href="<?php echo htmlspecialchars((string)$revisionData['proposal_file'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars(basename((string)$revisionData['proposal_file']), ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                                <input type="file" id="proposal_file" name="proposal_file" class="form-control" accept=".pdf,application/pdf">
                            </div>

                            <!-- ══ 唯讀：時間 ══ -->
                            <div class="section-card">
                                <h4>活動時間 <small style="font-weight:normal; color:#999;">（不可修改）</small></h4>
                                <div style="display:flex; gap:15px; flex-wrap:wrap;">
                                    <div class="form-group" style="flex:1; min-width:180px; margin-bottom:0;">
                                        <label>活動開始時間</label>
                                        <input type="datetime-local" class="form-control" readonly style="background:#f0f0f0; cursor:not-allowed;"
                                               value="<?php
                                               $bs = $revisionData['borrow_start_at'] ?? '';
                                               echo htmlspecialchars(str_replace(' ', 'T', substr($bs, 0, 16)), ENT_QUOTES, 'UTF-8');
                                               ?>">
                                    </div>
                                    <div class="form-group" style="flex:1; min-width:180px; margin-bottom:0;">
                                        <label>活動結束時間</label>
                                        <input type="datetime-local" class="form-control" readonly style="background:#f0f0f0; cursor:not-allowed;"
                                               value="<?php
                                               $be = $revisionData['borrow_end_at'] ?? '';
                                               echo htmlspecialchars(str_replace(' ', 'T', substr($be, 0, 16)), ENT_QUOTES, 'UTF-8');
                                               ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- ══ 唯讀：場地 / 器材 ══ -->
                            <?php if (!empty($spaceItems)): ?>
                            <div class="section-card">
                                <h4>預約空間 <small style="font-weight:normal; color:#999;">（不可修改）</small></h4>
                                <div class="readonly-block">
                                    <?php foreach ($spaceItems as $space): ?>
                                    <div style="padding:6px 0; border-bottom:1px solid #e0e0e0;">
                                        <strong><?php echo htmlspecialchars($space['space_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        （容納人數：<?php echo htmlspecialchars((string)($space['capacity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>）
                                        — 代號：<?php echo htmlspecialchars($space['space_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($equipmentItems)): ?>
                            <div class="section-card">
                                <h4>預約器材 <small style="font-weight:normal; color:#999;">（不可修改）</small></h4>
                                <div class="readonly-block">
                                    <?php foreach ($equipmentItems as $equip): ?>
                                    <div style="padding:6px 0; border-bottom:1px solid #e0e0e0;">
                                        <strong><?php echo htmlspecialchars($equip['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        — 代號：<?php echo htmlspecialchars($equip['equipment_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                        | 器材編號：<?php echo htmlspecialchars((string)($equip['equipment_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- 送出按鈕 -->
                            <div style="display:flex; gap:10px; margin-top:24px; justify-content:flex-end;">
                                <button type="button" class="btn-secondary" onclick="location.href='return_management.php'">取消</button>
                                <button type="submit" class="btn-primary">提交補件</button>
                            </div>
                        </form>
                    </section>
                    <?php endif; ?>
                <?php endif; ?>

            </section>
        </main>
    </div>

    <script>
    // ── 明火人員操作 ──────────────────────────────────
    function addAmendStaffRow(tableId, inputName) {
        const tbody = document.querySelector('#' + tableId + ' tbody');
        if (!tbody) return;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="${inputName}" class="form-control" placeholder="請輸入姓名"></td>
            <td style="text-align:center;"><button type="button" class="btn-del-row" onclick="delStaffRow(this)">刪除</button></td>`;
        tbody.appendChild(tr);
    }
    function delStaffRow(btn) {
        const tr = btn.closest('tr');
        const tbody = tr.parentElement;
        if (tbody.rows.length <= 1) {
            // 保留一列，清空內容
            tbody.querySelectorAll('input').forEach(i => i.value = '');
            return;
        }
        tr.remove();
    }

    // ── 攤位操作 ──────────────────────────────────────
    function addAmendBoothRow() {
        const tbody = document.getElementById('amend_booth_tbody');
        if (!tbody) return;
        if (tbody.rows.length >= 20) { alert('攤位數量最多限制 20 個喔！'); return; }
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="sales_booth_no[]" class="form-control" placeholder="數字"></td>
            <td><input type="text" name="sales_booth_name[]" class="form-control" placeholder="名稱"></td>
            <td><input type="text" name="sales_booth_manager[]" class="form-control" placeholder="姓名"></td>
            <td><input type="text" name="sales_booth_phone[]" class="form-control" placeholder="電話"></td>
            <td><input type="text" name="sales_booth_content[]" class="form-control" placeholder="販售內容"></td>
            <td style="text-align:center;"><button type="button" class="btn-del-row" onclick="delBoothRow(this)">刪除</button></td>`;
        tbody.appendChild(tr);
        syncCountDisplay();
    }
    function delBoothRow(btn) {
        const tr = btn.closest('tr');
        const tbody = tr.parentElement;
        if (tbody.rows.length <= 1) {
            tbody.querySelectorAll('input').forEach(i => i.value = '');
            return;
        }
        tr.remove();
        syncCountDisplay();
    }
    function syncCountDisplay() {
        const tbody = document.getElementById('amend_booth_tbody');
        const countInput = document.getElementById('sales_count');
        if (tbody && countInput) { countInput.value = tbody.rows.length; }
    }
    function syncBoothCount(val) {
        const tbody = document.getElementById('amend_booth_tbody');
        if (!tbody) return;
        let target = Math.max(1, Math.min(20, parseInt(val) || 1));
        while (tbody.rows.length < target) { addAmendBoothRow(); }
        while (tbody.rows.length > target) { tbody.deleteRow(tbody.rows.length - 1); }
    }
    </script>
</body>
</html>