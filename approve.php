<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'lib/PHPMailer/PHPMailer.php';
require 'lib/PHPMailer/SMTP.php';
require 'lib/PHPMailer/Exception.php';
require_once __DIR__ . '/config/database.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=approve.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$currentRole = (string)($_SESSION['role_name'] ?? '');

// Allow manager roles (treat roles a, b, c, d as managers alongside role 3)
$allowedRoles = ['2', '3', 'a', 'b', 'c', 'd'];
if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    echo "<p style=\"padding:1rem;background:#ffecec;border-radius:6px;\">存取被拒：此功能僅限課指組老師。</p>";
    exit;
}


$dbError = '';
$link = getMysqliConnection($dbError);

function pickExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservation_ids'], $_POST['action'])) {
    $reservationIdsRaw = explode(',', $_POST['reservation_ids']);
    $reservationIds = [];
    foreach ($reservationIdsRaw as $rid) {
        $r = (int)$rid;
        if ($r > 0) $reservationIds[] = $r;
    }
    
    if ($_POST['action'] === 'approve') {
        $action = 'approved';
    } elseif ($_POST['action'] === 'request_revision') {
        $action = 'need_revision';
    } else {
        $action = 'rejected';
    }
    $cannedMessage = trim((string)($_POST['canned_message'] ?? ''));
    $freeformComment = trim((string)($_POST['comment'] ?? ''));
    $commentParts = [];
    if ($cannedMessage !== '') {
        $commentParts[] = $cannedMessage;
    }
    if ($freeformComment !== '') {
        $commentParts[] = $freeformComment;
    }
    $comment = !empty($commentParts) ? implode("\n", $commentParts) : null;

    if ($link && !empty($reservationIds)) {
        mysqli_begin_transaction($link);
        try {
            $totalAffected = 0;

            // detect whether reservations table has approval_stage column
            $hasApprovalStage = false;
            $colCheck = mysqli_query($link, "SHOW COLUMNS FROM reservations LIKE 'approval_stage'");
            if ($colCheck && mysqli_num_rows($colCheck) > 0) {
                $hasApprovalStage = true;
            }

            // determine current approver stage for this user
            $currentStageForUser = $currentRole === '2' ? '3' : $currentRole; // role '2' can act as final '3'

            $notifyApproved = [];
            $notifyRejected = [];
            $notifyNeedRevision = [];
            $userEmailNameMap = [];

            // prepare a diagnostic query to collect current states for each reservation
            $diagStmt = mysqli_prepare($link, 'SELECT reservation_id, approval_status, approval_stage, updated_at FROM reservations WHERE reservation_id = ?');
            $diagnostics = [];

            foreach ($reservationIds as $reservationId) {
                if ($diagStmt) {
                    mysqli_stmt_bind_param($diagStmt, 'i', $reservationId);
                    mysqli_stmt_execute($diagStmt);
                    $dres = mysqli_stmt_get_result($diagStmt);
                    $diagnostics[$reservationId] = $dres ? mysqli_fetch_assoc($dres) : null;
                }
                // If approval_stage exists, enforce stage-based approval
                if ($hasApprovalStage) {
                    $selStmt = mysqli_prepare($link, 'SELECT approval_stage, approval_status FROM reservations WHERE reservation_id = ? FOR UPDATE');
                    if (!$selStmt) {
                        throw new RuntimeException('取得審核階段失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($selStmt, 'i', $reservationId);
                    mysqli_stmt_execute($selStmt);
                    $selRes = mysqli_stmt_get_result($selStmt);
                    $row = $selRes ? mysqli_fetch_assoc($selRes) : null;
                    mysqli_stmt_close($selStmt);

                    if (!$row || $row['approval_status'] !== 'pending') {
                        continue; // already processed or missing
                    }

                    $currentApprovalStage = (string)($row['approval_stage'] ?? '3');

                    // Only allow user to process if their role matches the current approval stage
                    $canProcess = false;
                    // allow these roles to request revision regardless of stage
                    if ($_POST['action'] === 'request_revision' && in_array($currentRole, ['a', 'b', 'c', '3'], true)) {
                        $canProcess = true;
                    } else {
                        if ($currentRole === '2') {
                            $canProcess = ($currentApprovalStage === '3');
                        } elseif ($currentRole === '3') {
                            // role 3 can process both 'd' (課指組) and '3' (最終)
                            $canProcess = in_array($currentApprovalStage, ['d', '3'], true);
                        } else {
                            $canProcess = ($currentRole === $currentApprovalStage);
                        }
                    }
                    if (!$canProcess) {
                        error_log(sprintf('approve.php: skip reservation %d - role %s cannot process stage %s for action %s', $reservationId, $currentRole, $currentApprovalStage, $_POST['action']));
                        continue;
                    }

                    if ($_POST['action'] === 'request_revision') {
                        // Build a snapshot of existing reservation columns (only columns that exist)
                        $cols = [];
                        $colRes = mysqli_query($link, "SHOW COLUMNS FROM reservations");
                        if ($colRes) {
                            while ($crow = mysqli_fetch_assoc($colRes)) {
                                $cols[] = $crow['Field'];
                            }
                        }

                        $candidates = [
                            'organization_name','activity_name','participant_count','staff_count',
                            'club_president','activity_coordinator','coordinator_department','coordinator_phone',
                            'coordinator_other_contact','vehicle_entry','setup_flags','purpose',
                            'borrow_start_at','borrow_end_at','space_id','proposal_file','proposal_uploaded_at'
                        ];
                        $useCols = array_values(array_intersect($candidates, $cols));
                        $snapshotData = null;
                        if (!empty($useCols)) {
                            $selSql = 'SELECT ' . implode(', ', $useCols) . ' FROM reservations WHERE reservation_id = ? FOR UPDATE';
                            $selS = mysqli_prepare($link, $selSql);
                            if ($selS) {
                                mysqli_stmt_bind_param($selS, 'i', $reservationId);
                                mysqli_stmt_execute($selS);
                                $rres = mysqli_stmt_get_result($selS);
                                $snapshotData = $rres ? mysqli_fetch_assoc($rres) : null;
                                mysqli_stmt_close($selS);
                            }
                        }

                        $revisionDataJson = $snapshotData ? json_encode($snapshotData, JSON_UNESCAPED_UNICODE) : null;

                        // update approval_status and store revision snapshot atomically
                        $updateSql = 'UPDATE reservations SET approval_status = ?, updated_at = NOW(), revision_deadline = CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), " 23:59:59"), revision_data_json = ? WHERE reservation_id = ? AND approval_status = "pending"';
                        $updateStmt = mysqli_prepare($link, $updateSql);
                        if (!$updateStmt) {
                            error_log('approve.php: prepare UPDATE request_revision failed: ' . mysqli_error($link));
                            throw new RuntimeException('準備要求補件失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($updateStmt, 'ssi', $action, $revisionDataJson, $reservationId);
                        error_log(sprintf('approve.php: about to execute request_revision UPDATE reservation=%d user=%s role=%s pre_status=%s pre_stage=%s', $reservationId, $currentUserId, $currentRole, $row['approval_status'] ?? 'NULL', $currentApprovalStage));
                        mysqli_stmt_execute($updateStmt);
                        $affected = mysqli_stmt_affected_rows($updateStmt);
                        if ($affected <= 0) {
                            error_log(sprintf('approve.php: UPDATE affected=0 for reservation %d action=request_revision status=%s stage=%s mysqli_err=%s', $reservationId, $row['approval_status'] ?? 'NULL', $currentApprovalStage, mysqli_error($link)));
                        }
                        mysqli_stmt_close($updateStmt);
                        if ($affected > 0) {
                            $totalAffected++;
                            $notifyNeedRevision[] = $reservationId;
                        }
                    } elseif ($_POST['action'] === 'reject') {
                        $updateStmt = mysqli_prepare($link, 'UPDATE reservations SET approval_status = ?, updated_at = NOW(), rejection_reason = ? WHERE reservation_id = ? AND approval_status = "pending"');
                        if (!$updateStmt) throw new RuntimeException('準備拒絕失敗：' . mysqli_error($link));
                        mysqli_stmt_bind_param($updateStmt, 'ssi', $action, $comment, $reservationId);
                        mysqli_stmt_execute($updateStmt);
                        $affected = mysqli_stmt_affected_rows($updateStmt);
                        mysqli_stmt_close($updateStmt);
                        if ($affected > 0) {
                            $totalAffected++;
                            $notifyRejected[] = $reservationId;
                            // restore equipments for this reservation
                            $restoreStmt = mysqli_prepare(
                                $link,
                                'UPDATE equipments e JOIN equipment_reservation_items eri ON e.equipment_id = eri.equipment_id SET e.operation_status = 1 WHERE eri.reservation_id = ? AND e.operation_status = 2'
                            );
                            if (!$restoreStmt) {
                                throw new RuntimeException('還原器材狀態失敗：' . mysqli_error($link));
                            }
                            mysqli_stmt_bind_param($restoreStmt, 'i', $reservationId);
                            mysqli_stmt_execute($restoreStmt);
                            mysqli_stmt_close($restoreStmt);
                        }
                    } else { // approve -> advance stage or final approve
                        $stages = ['a','b','c','d','3'];
                        $idx = array_search($currentApprovalStage, $stages, true);
                        if ($idx === false) $idx = count($stages) - 1; // treat unknown as final
                        if ($idx === count($stages) - 1) {
                            // final approval
                            $updateStmt = mysqli_prepare($link, 'UPDATE reservations SET approval_status = "approved", updated_at = NOW() WHERE reservation_id = ? AND approval_status = "pending"');
                            if (!$updateStmt) throw new RuntimeException('準備最終核准失敗：' . mysqli_error($link));
                            mysqli_stmt_bind_param($updateStmt, 'i', $reservationId);
                            mysqli_stmt_execute($updateStmt);
                            $affected = mysqli_stmt_affected_rows($updateStmt);
                            mysqli_stmt_close($updateStmt);
                            if ($affected > 0) {
                                $totalAffected++;
                                $notifyApproved[] = $reservationId;
                            }
                        } else {
                            $nextStage = $stages[$idx + 1];

                            // If the current user is role 3 and they're processing a prior stage (e.g. 'd'),
                            // treat their approval as final approval (role 3 is final approver).
                            if ($currentRole === '3' && in_array($currentApprovalStage, ['d', '3'], true)) {
                                $finalStmt = mysqli_prepare($link, 'UPDATE reservations SET approval_status = "approved", updated_at = NOW() WHERE reservation_id = ? AND approval_status = "pending"');
                                if (!$finalStmt) throw new RuntimeException('準備最終核准失敗：' . mysqli_error($link));
                                mysqli_stmt_bind_param($finalStmt, 'i', $reservationId);
                                mysqli_stmt_execute($finalStmt);
                                $affected = mysqli_stmt_affected_rows($finalStmt);
                                mysqli_stmt_close($finalStmt);
                                if ($affected > 0) {
                                    $totalAffected++;
                                    $notifyApproved[] = $reservationId;
                                }
                            } else {
                                $updateStmt = mysqli_prepare($link, 'UPDATE reservations SET approval_stage = ?, updated_at = NOW() WHERE reservation_id = ? AND approval_status = "pending"');
                                if (!$updateStmt) throw new RuntimeException('準備更新下一審階段失敗：' . mysqli_error($link));
                                mysqli_stmt_bind_param($updateStmt, 'si', $nextStage, $reservationId);
                                mysqli_stmt_execute($updateStmt);
                                $affected = mysqli_stmt_affected_rows($updateStmt);
                                mysqli_stmt_close($updateStmt);
                                if ($affected > 0) {
                                    $totalAffected++;
                                    // not final — do not notify applicant yet
                                }
                            }
                        }
                    }

                    // if affected, write approval log
                    if (!empty($affected) && $affected > 0) {
                        $logStmt = mysqli_prepare($link, 'INSERT INTO approval_logs (reservation_id, reviewer_id, review_result, review_comment) VALUES (?, ?, ?, ?)');
                        if (!$logStmt) {
                            throw new RuntimeException('建立審核紀錄失敗：' . mysqli_error($link));
                        }
                        // keep request_revision distinct from reject/approve in the audit log
                        if ($_POST['action'] === 'approve') {
                            $reviewResult = 'approved';
                        } elseif ($_POST['action'] === 'request_revision') {
                            $reviewResult = 'need_revision';
                        } else {
                            $reviewResult = 'rejected';
                        }
                        mysqli_stmt_bind_param($logStmt, 'isss', $reservationId, $currentUserId, $reviewResult, $comment);
                        mysqli_stmt_execute($logStmt);
                        mysqli_stmt_close($logStmt);

                        // collect applicant email/name for final notifications
                        if (in_array($reservationId, $notifyApproved, true) || in_array($reservationId, $notifyRejected, true) || in_array($reservationId, $notifyNeedRevision, true)) {
                            $userQuery = mysqli_prepare($link, 'SELECT u.email, u.full_name FROM users u JOIN reservations r ON u.user_id = r.user_id WHERE r.reservation_id = ?');
                            if ($userQuery) {
                                mysqli_stmt_bind_param($userQuery, 'i', $reservationId);
                                mysqli_stmt_execute($userQuery);
                                $userResult = mysqli_stmt_get_result($userQuery);
                                if ($userData = mysqli_fetch_assoc($userResult)) {
                                    $userEmailNameMap[$userData['email']] = $userData['full_name'];
                                }
                                mysqli_stmt_close($userQuery);
                            }
                        }
                    }

                    continue; // proceed to next reservation
                }

                // If no approval_stage column, fallback to existing behavior (single-step)
                if ($action === 'need_revision') {
                    // 當要求補件時，保存原始申請數據快照（只保存申請表單欄位）
                    $snapshotSql = 'SELECT 
                        organization_name, activity_name, participant_count, staff_count, 
                        club_president, activity_coordinator, coordinator_department, coordinator_phone, 
                          coordinator_other_contact, vehicle_entry, has_alcohol, has_fire, has_sales, setup_flags,
                        purpose, borrow_start_at, borrow_end_at,
                        space_id
                    FROM reservations WHERE reservation_id = ?';
                    $snapshotStmt = mysqli_prepare($link, $snapshotSql);
                    if (!$snapshotStmt) {
                        throw new RuntimeException('查詢原始申請數據失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($snapshotStmt, 'i', $reservationId);
                    mysqli_stmt_execute($snapshotStmt);
                    $snapshotResult = mysqli_stmt_get_result($snapshotStmt);
                    $snapshotData = $snapshotResult ? mysqli_fetch_assoc($snapshotResult) : null;
                    mysqli_stmt_close($snapshotStmt);
                    
                    $revisionDataJson = null;
                    if ($snapshotData) {
                        $revisionDataJson = json_encode($snapshotData, JSON_UNESCAPED_UNICODE);
                    }
                    
                    $updateStmt = mysqli_prepare($link, 'UPDATE reservations SET approval_status = ?, updated_at = NOW(), revision_deadline = CONCAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), " 23:59:59"), revision_data_json = ? WHERE reservation_id = ? AND approval_status = "pending"');
                    if (!$updateStmt) {
                        throw new RuntimeException('更新預約狀態準備失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($updateStmt, 'ssi', $action, $revisionDataJson, $reservationId);
                } else {
                    if ($action === 'rejected') {
                        $updateStmt = mysqli_prepare($link, 'UPDATE reservations SET approval_status = ?, updated_at = NOW(), rejection_reason = ? WHERE reservation_id = ? AND approval_status = "pending"');
                        if (!$updateStmt) {
                            throw new RuntimeException('更新預約狀態準備失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($updateStmt, 'ssi', $action, $comment, $reservationId);
                    } else {
                        $updateStmt = mysqli_prepare($link, 'UPDATE reservations SET approval_status = ?, updated_at = NOW() WHERE reservation_id = ? AND approval_status = "pending"');
                        if (!$updateStmt) {
                            throw new RuntimeException('更新預約狀態準備失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($updateStmt, 'si', $action, $reservationId);
                    }
                }

                mysqli_stmt_execute($updateStmt);
                $affected = mysqli_stmt_affected_rows($updateStmt);
                mysqli_stmt_close($updateStmt);

                if ($affected > 0) {
                    $totalAffected++;
                    $logStmt = mysqli_prepare($link, 'INSERT INTO approval_logs (reservation_id, reviewer_id, review_result, review_comment) VALUES (?, ?, ?, ?)');
                    if (!$logStmt) {
                        throw new RuntimeException('建立審核紀錄失敗：' . mysqli_error($link));
                    }
                    $reviewResult = $action;
                    mysqli_stmt_bind_param($logStmt, 'isss', $reservationId, $currentUserId, $reviewResult, $comment);
                    mysqli_stmt_execute($logStmt);
                    mysqli_stmt_close($logStmt);

                    // 若為拒絕，需將相關器材狀態還原為可借 (operation_status = 1)
                    if ($action === 'rejected') {
                        $restoreStmt = mysqli_prepare(
                            $link,
                            'UPDATE equipments e JOIN equipment_reservation_items eri ON e.equipment_id = eri.equipment_id SET e.operation_status = 1 WHERE eri.reservation_id = ? AND e.operation_status = 2'
                        );
                        if (!$restoreStmt) {
                            throw new RuntimeException('還原器材狀態失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($restoreStmt, 'i', $reservationId);
                        mysqli_stmt_execute($restoreStmt);
                        mysqli_stmt_close($restoreStmt);
                        $notifyRejected[] = $reservationId;
                    }

                    // 取得申請人資訊以寄送郵件（僅針對最終狀態/原單步驟流程）
                    if ($action === 'rejected' || $action === 'need_revision' || $action === 'approved') {
                        $userQuery = mysqli_prepare($link, 'SELECT u.email, u.full_name FROM users u JOIN reservations r ON u.user_id = r.user_id WHERE r.reservation_id = ?');
                        if ($userQuery) {
                            mysqli_stmt_bind_param($userQuery, 'i', $reservationId);
                            mysqli_stmt_execute($userQuery);
                            $userResult = mysqli_stmt_get_result($userQuery);
                            if ($userData = mysqli_fetch_assoc($userResult)) {
                                $userEmailNameMap[$userData['email']] = $userData['full_name'];
                            }
                            mysqli_stmt_close($userQuery);
                        }
                    }
                }
            }

            if ($totalAffected <= 0) {
                $details = [];
                foreach ($reservationIds as $rid) {
                    if (!isset($diagnostics[$rid]) || $diagnostics[$rid] === null) {
                        $details[] = "#{$rid}=<no row>";
                    } else {
                        $d = $diagnostics[$rid];
                        $details[] = sprintf('#%d=status=%s,stage=%s,updated_at=%s', $rid, $d['approval_status'] ?? 'NULL', $d['approval_stage'] ?? 'NULL', $d['updated_at'] ?? 'NULL');
                    }
                }
                throw new RuntimeException('更新失敗：申請可能已被審核或您無權處理所選項目。詳細：' . implode(' ; ', $details));
            }

            mysqli_commit($link);

            // Build action message
            if ($_POST['action'] === 'request_revision') {
                $actionMsg = '已要求申請人補件。';
            } elseif ($_POST['action'] === 'reject') {
                $actionMsg = '已拒絕此申請。';
            } else {
                // For approve, some may be advanced and some may be final approved
                $actionMsg = '審核處理完成。';
            }

            // Send notification emails per final-result groups
            require_once __DIR__ . '/config/mail.php';
            $mail = new PHPMailer(true);
            try {
                if (empty($MAIL_ENABLED) || empty($MAIL_USERNAME) || empty($MAIL_PASSWORD)) {
                    throw new RuntimeException('郵件設定未啟用或未完成，請檢查 config/mail.php');
                }
                $mailFrom = !empty($MAIL_FROM) ? $MAIL_FROM : $MAIL_USERNAME;
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = $MAIL_USERNAME;
                $mail->Password   = $MAIL_PASSWORD;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;
                $mail->CharSet    = 'UTF-8';

                $mail->setFrom($mailFrom, $MAIL_FROM_NAME ?? '器材借用系統');

                // Approved
                if (count($notifyApproved) > 0) {
                    foreach ($userEmailNameMap as $email => $name) {
                        $mail->addAddress($email, $name);
                    }
                    $mail->isHTML(true);
                    $mail->Subject = '您的借用申請已核准';
                    $idsStr = implode(', ', $notifyApproved);
                    $mail->Body    = "您好：<br><br>您提出的借用申請（編號：{$idsStr}）已被核准。<br><br>審核意見：<br>" . nl2br(htmlspecialchars($comment ?? '無')) . "<br><br>感謝您！";
                    $mail->AltBody = "您好：\n\n您提出的借用申請（編號：{$idsStr}）已被核准。\n\n審核意見：\n" . htmlspecialchars($comment ?? '無') . "\n\n感謝您！";
                    $mail->send();
                    // clear recipients for next group
                    $mail->clearAllRecipients();
                }

                // Need revision
                if (count($notifyNeedRevision) > 0) {
                    foreach ($userEmailNameMap as $email => $name) {
                        $mail->addAddress($email, $name);
                    }
                    $mail->isHTML(true);
                    $mail->Subject = '您的借用申請需要補件';
                    $idsStr = implode(', ', $notifyNeedRevision);
                    $mail->Body    = "您好：<br><br>您提出的借用申請（編號：{$idsStr}）需要補件，請依通知補件。<br><br>審核意見：<br>" . nl2br(htmlspecialchars($comment ?? '無')) . "<br><br>感謝您！";
                    $mail->AltBody = "您好：\n\n您提出的借用申請（編號：{$idsStr}）需要補件，請依通知補件。\n\n審核意見：\n" . htmlspecialchars($comment ?? '無') . "\n\n感謝您！";
                    $mail->send();
                    $mail->clearAllRecipients();
                }

                // Rejected
                if (count($notifyRejected) > 0) {
                    foreach ($userEmailNameMap as $email => $name) {
                        $mail->addAddress($email, $name);
                    }
                    $mail->isHTML(true);
                    $mail->Subject = '您的借用申請已被拒絕';
                    $idsStr = implode(', ', $notifyRejected);
                    $mail->Body    = "您好：<br><br>您提出的借用申請（編號：{$idsStr}）已被拒絕。<br><br>審核意見：<br>" . nl2br(htmlspecialchars($comment ?? '無')) . "<br><br>感謝您！";
                    $mail->AltBody = "您好：\n\n您提出的借用申請（編號：{$idsStr}）已被拒絕。\n\n審核意見：\n" . htmlspecialchars($comment ?? '無') . "\n\n感謝您！";
                    $mail->send();
                    $mail->clearAllRecipients();
                }
            } catch (Exception $e) {
                $actionMsg .= " 但通知信寄送失敗： {$mail->ErrorInfo}";
            }

        } catch (Throwable $e) {
            mysqli_rollback($link);
            $actionMsg = '處理失敗：' . $e->getMessage();
        }
    }
}

// Fetch pending reservations (support reservations.applicant_id OR reservations.user_id)
$pending = [];

if (isset($dbError) && $dbError !== '') {
    // DB error already set; skip fetching
} else {
    // Collect reservation columns and pick applicant column
    $reservationColumns = [];
    $columnResult = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    if ($columnResult) {
        while ($columnRow = mysqli_fetch_assoc($columnResult)) {
            $reservationColumns[] = (string)$columnRow['Field'];
        }
    }
    // DEBUG: dump reservation columns for troubleshooting
    @file_put_contents(__DIR__ . '/approve_debug.log', date('c') . " reservationColumns: " . json_encode($reservationColumns, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

    // 使用現行資料表欄位 `user_id`
    $applicantColumn = 'user_id';

    // 如果 reservations 表有 approval_stage 欄位，僅顯示屬於當前審核階段的待審申請
    $hasApprovalStage = in_array('approval_stage', $reservationColumns, true);
    $approvalStageFilter = '';
    if ($hasApprovalStage) {
        // role '2' acts as final '3'; role '3' should be able to see both 'd' and '3' stages
        if ($currentRole === '2') {
            $expectedStages = ['3'];
        } elseif ($currentRole === '3') {
            $expectedStages = ['d', '3'];
        } else {
            $expectedStages = [$currentRole];
        }

        if ($link) {
            $escaped = array_map(function($s) use ($link) { return "'" . mysqli_real_escape_string($link, (string)$s) . "'"; }, $expectedStages);
            $inList = implode(',', $escaped);
            $approvalStageFilter = " AND r.approval_stage IN (" . $inList . ")";
        } else {
            $escaped = array_map(function($s) { return "'" . addslashes((string)$s) . "'"; }, $expectedStages);
            $inList = implode(',', $escaped);
            $approvalStageFilter = " AND r.approval_stage IN (" . $inList . ")";
        }
    }

    if (!in_array($applicantColumn, $reservationColumns, true)) {
        $dbError = '資料表 reservations 缺少 user_id，無法顯示審核資料。';
    } else {
        $submittedAtExpr = in_array('submitted_at', $reservationColumns, true) ? 'r.submitted_at' : 'r.created_at';
        $sql = sprintf(
            "SELECT r.reservation_id, r.`%s` AS applicant_user_id, %s AS submitted_at, r.borrow_start_at, r.borrow_end_at, u.full_name, u.email
             FROM reservations r
             JOIN users u ON r.`%s` = u.user_id
             WHERE r.approval_status = 'pending' %s
             ORDER BY %s ASC
             LIMIT 200",
            $applicantColumn,
            $submittedAtExpr,
            $applicantColumn,
            $approvalStageFilter,
            $submittedAtExpr
        );

        $res = mysqli_query($link, $sql);
        if ($res) {
            $groupedPending = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $groupKey = $row['applicant_user_id'] . '_' . $row['submitted_at'];
                if (!isset($groupedPending[$groupKey])) {
                    $groupedPending[$groupKey] = [
                        'applicant_user_id' => $row['applicant_user_id'],
                        'full_name' => $row['full_name'],
                        'submitted_at' => $row['submitted_at'],
                        'borrow_start_at' => $row['borrow_start_at'],
                        'borrow_end_at' => $row['borrow_end_at'],
                        'reservation_ids' => [],
                        'items' => [],
                        'details' => []
                    ];
                }
                $groupedPending[$groupKey]['reservation_ids'][] = $row['reservation_id'];
                
                // Fetch items for this reservation_id and merge them
                $items = fetchItems($link, (int)$row['reservation_id']);
                foreach ($items as $it) {
                    $groupedPending[$groupKey]['items'][] = $it;
                }
                // Fetch full reservation details (include only existing columns)
                $details = fetchReservationDetails($link, (int)$row['reservation_id']);
                if (!empty($details)) {
                    $groupedPending[$groupKey]['details'][$row['reservation_id']] = $details;
                    // DEBUG: write per-reservation details to log
                    @file_put_contents(__DIR__ . '/approve_debug.log', date('c') . " details reservation_id={$row['reservation_id']}: " . json_encode($details, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            }
            $pending = array_values($groupedPending);
        } else {
            $dbError = '讀取待審核申請失敗：' . mysqli_error($link);
        }
    }
}

function fetchItems(mysqli $link, int $reservationId): array
{
    $items = [];
    $stmt = mysqli_prepare($link, 'SELECT eri.equipment_item_id, e.equipment_id, e.equipment_code, ec.equipment_name FROM equipment_reservation_items eri JOIN equipments e ON eri.equipment_id = e.equipment_id JOIN equipment_categories ec ON e.equipment_code = ec.equipment_code WHERE eri.reservation_id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $reservationId);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        if ($r) {
            while ($it = mysqli_fetch_assoc($r)) {
                $items[] = [
                    'item_type' => 'equipment',
                            'item_id' => (int)$it['equipment_id'],
                    'item_code' => (string)$it['equipment_code'],
                    'item_name' => (string)$it['equipment_name']
                ];
            }
        }
        mysqli_stmt_close($stmt);
    }

    $spaceStmt = mysqli_prepare($link, 'SELECT sri.space_item_id, s.space_id, s.space_name FROM space_reservation_items sri JOIN spaces s ON sri.space_id = s.space_id WHERE sri.reservation_id = ?');
    if ($spaceStmt) {
        mysqli_stmt_bind_param($spaceStmt, 'i', $reservationId);
        mysqli_stmt_execute($spaceStmt);
        $spaceResult = mysqli_stmt_get_result($spaceStmt);
        if ($spaceResult) {
            while ($space = mysqli_fetch_assoc($spaceResult)) {
                $items[] = [
                    'item_type' => 'space',
                    'item_code' => (string)$space['space_id'],
                    'item_name' => (string)$space['space_name']
                ];
            }
        }
        mysqli_stmt_close($spaceStmt);
    }

    return $items;
}

function fetchReservationDetails(mysqli $link, int $reservationId): array
{
    $items = [];
    // candidate columns we want to show if they exist
    $candidates = [
        'organization_name','activity_name','participant_count','staff_count',
        'club_president','activity_coordinator','coordinator_department','coordinator_phone',
        'coordinator_other_contact','vehicle_entry','setup_flags','purpose',
        'borrow_start_at','borrow_end_at','space_id','proposal_file','proposal_uploaded_at',
        'has_alcohol','has_fire','has_sales','proposal_file','proposal_uploaded_at','rejection_reason',
        'revision_deadline','revision_data_json'
    ];

    $cols = [];
    $colRes = mysqli_query($link, "SHOW COLUMNS FROM reservations");
    if ($colRes) {
        while ($crow = mysqli_fetch_assoc($colRes)) {
            $cols[] = $crow['Field'];
        }
    }

    $useCols = array_values(array_intersect($candidates, $cols));
    if (empty($useCols)) return [];

    $selSql = 'SELECT ' . implode(', ', $useCols) . ' FROM reservations WHERE reservation_id = ?';
    $selS = mysqli_prepare($link, $selSql);
    if (!$selS) return [];
    mysqli_stmt_bind_param($selS, 'i', $reservationId);
    mysqli_stmt_execute($selS);
    $rres = mysqli_stmt_get_result($selS);
    $snapshot = $rres ? mysqli_fetch_assoc($rres) : null;
    mysqli_stmt_close($selS);

    return $snapshot ? $snapshot : [];
}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>審核面板｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        /* Approve panel tweaks */
        :root{--primary:#2563eb;--accent:#0ea5a4;--muted:#475569;--card-bg:#ffffff;--panel-bg:#fbfbff}
        .admin-application-card { padding:20px; border-radius:12px; background:var(--card-bg); box-shadow:0 6px 20px rgba(2,6,23,0.06); border:1px solid #e6eef8; font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .admin-application-card .card-header { display:flex; justify-content:space-between; align-items:center; gap:12px; }
        .admin-application-card .card-header .left { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .admin-application-card strong { color:#0f172a; font-size:1.02rem; }
        .admin-application-card .meta { color:var(--muted); font-size:0.95rem; }
        .detail-toggle { margin-top:0.6rem; }
        .detail-toggle.btn-primary { padding:8px 14px; background:var(--accent); color:#fff; border-radius:10px; border:none; font-weight:600; font-size:0.95rem; }
        .detail-toggle.btn-primary:hover { background:#028b86; }
        .admin-application-card .btn-primary { background:var(--primary); color:#fff; border:none; padding:8px 12px; font-weight:600; border-radius:8px; }
        .admin-application-card .btn-primary:hover { background:#1e40af; }
        .admin-application-card .btn-secondary { background:#f8fafc; color:#0f172a; border:1px solid #e2e8f0; padding:8px 12px; border-radius:8px; }
        .btn-warning { background:#f59e0b; color:#000; border:none; padding:8px 12px; border-radius:8px; font-weight:600; }
        .btn-warning:hover { background:#d97706; }
        .detail-panel { background:var(--panel-bg); border:1px solid #eef2ff; padding:14px; border-radius:10px; margin-top:0.5rem; }
        .admin-application-card table td { border:1px solid #f0f0f0; padding:10px; }
        /* action form */
        .action-form { margin-top:0.6rem; display:flex; gap:0.75rem; align-items:flex-end; }
        .action-comment { flex:1; }
        .comment-textarea { width:100%; min-height:56px; padding:8px 10px; border:1px solid #e6eef8; border-radius:8px; font-family:inherit; }
        .action-buttons { display:flex; flex-direction:column; gap:0.5rem; }
        /* small helpers */
        .submitted-time { color:var(--muted); font-size:0.9rem; }
        ul { margin:0.25rem 0 0 1.2rem; }
        @media (max-width:900px) { .action-form { flex-direction:column; } .action-buttons { flex-direction:row; gap:0.5rem; } }
    </style>
 </head>
<body>
    <!-- 導覽列 放到 container 之外以讓背景拉滿整個視窗 -->
        <?php include __DIR__ . '/nav.php'; ?>

    <div class="container">

        <main class="main-content">
            <section class="card">
                <h2>審核面板（待審核申請）</h2>

                <?php if ($actionMsg !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if (isset($dbError) && $dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } else { ?>

                    <?php if (count($pending) === 0) { ?>
                        <p>目前沒有待審核的申請。</p>
                    <?php } else { ?>
                        <style>
                            .approve-table { width:100%; border-collapse:collapse; font-family:Segoe UI,Helvetica,Arial,sans-serif; }
                            .approve-table th, .approve-table td { padding:10px 12px; border-bottom:1px solid #eef2f7; vertical-align:middle; }
                            .approve-table thead th { background:#f9fafb; color:#334155; font-weight:600; text-align:left; }
                            .approve-table tbody tr:nth-child(even) { background:#fbfdfe; }
                            .approve-actions { display:flex; flex-direction:column; gap:8px; align-items:center; }
                            .approve-actions form { width:100%; display:flex; flex-direction:column; gap:8px; align-items:center; }
                            .approve-actions select, .approve-actions textarea { width:180px; }
                            .approve-actions .small-btn { width:100%; padding:6px 8px; border-radius:6px; border:none; cursor:pointer; }
                            .approve-actions .approve-btn { background:#4f46e5; color:#fff; }
                            .approve-actions .revise-btn { background:#f59e0b; color:#000; }
                            .approve-actions .reject-btn { background:#e2e8f0; color:#000; }
                            .detail-box { background:#0ea5e9; color:#fff; padding:8px 12px; border-radius:8px; font-weight:600; border:none; cursor:pointer; box-shadow:0 2px 6px rgba(14,165,233,0.18); }
                            .detail-box:hover { opacity:0.95; }
                            .detail-link { display:inline-block; background:#0ea5e9; color:#fff; padding:6px 10px; border-radius:8px; font-weight:700; text-decoration:none; }
                            .detail-link:hover { opacity:0.95; }
                        </style>

                        <div class="borrow-table-wrapper overflow-x-auto">
                            <table class="approve-table return-management-table">
                                <thead>
                                    <tr>
                                        <th style="width:60px;text-align:center">進度</th>
                                        <th>申請人</th>
                                        <th>借用時段</th>
                                        <th>借用項目</th>
                                        <th>申請詳情</th>
                                        <th style="width:220px;text-align:center">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending as $p) {
                                        $firstRid = count($p['reservation_ids'])>0 ? (int)$p['reservation_ids'][0] : 0;
                                        $resourceParts = [];
                                        if (!empty($p['items'])) {
                                            $names = [];
                                            foreach ($p['items'] as $it) {
                                                $names[] = $it['item_name'] ?? $it['item_code'] ?? '';
                                            }
                                            if (!empty($names)) $resourceParts[] = '器材: ' . implode(', ', array_unique($names));
                                        }
                                        $resourceText = count($resourceParts) > 0 ? implode(' | ', $resourceParts) : '-';
                                    ?>
                                    <tr id="row-<?php echo $firstRid; ?>" data-id="<?php echo $firstRid; ?>">
                                        <td style="text-align:center;">▶</td>
                                        <td>
                                            <div style="font-weight:600;color:#0f172a"><?php echo htmlspecialchars($p['full_name'] . ' (' . $p['applicant_user_id'] . ')', ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div style="color:#64748b;font-size:12px"><?php echo htmlspecialchars((string)($p['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td style="font-size:13px;color:#0f172a;">
                                            <?php echo htmlspecialchars((string)($p['borrow_start_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><br>
                                            ～ <?php echo htmlspecialchars((string)($p['borrow_end_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                        <td style="font-size:13px;color:#0f172a;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($resourceText, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:13px;">
                                            <?php $detailUrl = 'approve_detail.php?reservation_id=' . urlencode((string)$firstRid); ?>
                                            <a class="detail-link" href="<?php echo htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">查看申請詳情</a>
                                            <div style="font-size:12px;color:#64748b;margin-top:6px;"></div>
                                        </td>
                                        <td style="text-align:center;vertical-align:middle;">
                                            <div class="approve-actions">
                                                <form method="post" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="reservation_ids" value="<?php echo htmlspecialchars(implode(',', $p['reservation_ids']), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <select name="canned_message">
                                                        <option value="">— 退回原因 —</option>
                                                        <option value="活動用途不明">活動用途不明</option>
                                                        <option value="活動企劃書資訊不足">活動企劃書資訊不足</option>
                                                    </select>
                                                    <textarea name="comment" rows="2" placeholder="審核備註（可選）"></textarea>
                                                    <button type="submit" name="action" value="approve" class="small-btn approve-btn" onclick="return confirm('確認要核准此批申請？')">核准</button>
                                                    <button type="submit" name="action" value="request_revision" class="small-btn revise-btn" onclick="return confirm('確認要要求補件？')">要求補件</button>
                                                    <button type="submit" name="action" value="reject" class="small-btn reject-btn" onclick="return confirm('確認要拒絕？')">拒絕</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } ?>

                <?php } ?>
            </section>
        </main>
        <footer class="footer"><p>&copy; 2026 校園資源租借系統。所有權利保留。</p></footer>
    </div>
</body>
</html>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('btnManualRemind');
    if (!btn) return;
    btn.addEventListener('click', function() {
        if (!confirm('確定要立即檢查逾期並發送催繳通知嗎？（系統仍會依設定冷卻時間避免重複寄送）')) return;
        btn.disabled = true;
        const orig = btn.textContent;
        btn.textContent = '處理中...';
        fetch('manual_remind.php', { method: 'POST', credentials: 'same-origin' })
            .then(r => r.json())
            .then(js => {
                let msg = '';
                if (js.ok) {
                    msg = js.output ? js.output : '已完成檢查（無輸出）';
                } else {
                    msg = '執行失敗: ' + (js.error || JSON.stringify(js));
                }
                alert(msg);
            })
            .catch(err => {
                alert('呼叫失敗，請檢查伺服器日誌：' + err);
            })
            .finally(() => { btn.disabled = false; btn.textContent = orig; });
    });
    // Toggle detail panels for approval cards (supports legacy btn-link and new .detail-toggle)
    Array.from(document.querySelectorAll('button.btn-link[data-target], button.detail-toggle[data-target]')).forEach(function(b){
        b.addEventListener('click', function(){
            const targetId = b.getAttribute('data-target');
            const panel = document.getElementById(targetId);
            if (!panel) return;
            const isVisible = !(panel.style.display === 'none' || panel.style.display === '');
            if (!isVisible) {
                panel.style.display = 'block';
                b.textContent = '收合資訊';
            } else {
                panel.style.display = 'none';
                // restore initial label depending on button type
                if (b.classList.contains('detail-toggle')) {
                    b.textContent = '申請詳細資料';
                } else {
                    b.textContent = '詳細資訊';
                }
            }
        });
    });
});
</script>
<script>
function openDetail(id) {
    if (!id) return;
    window.location.href = 'approve_detail.php?reservation_id=' + encodeURIComponent(id);
}
</script>
