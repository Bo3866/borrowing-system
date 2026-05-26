<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
session_start();

// Enable error display for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

@file_put_contents(__DIR__ . '/approve_detail_debug.log', date('c') . " start GET=" . json_encode($_GET, JSON_UNESCAPED_UNICODE) . " SESSION=" . json_encode($_SESSION, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

function safe_html($value, $flags = ENT_QUOTES, $encoding = 'UTF-8') {
    return htmlspecialchars((string)$value, $flags, $encoding);
}

function is_yes($value): bool {
    $v = strtolower(trim((string)$value));
    if ($v === 'yes' || $v === '1' || $v === 'true') return true;
    return false;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=approve_detail.php');
    exit;
}
$currentRole = (string)($_SESSION['role_name'] ?? '');
$allowedRoles = ['2','3','a','b','c','d'];
if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    echo "<p style=\"padding:1rem;background:#ffecec;border-radius:6px;\">存取被拒：此功能僅限課指組老師。</p>";
    exit;
}

$reservationId = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;
if ($reservationId <= 0) {
    echo "無效的 reservation_id";
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);
if ($dbError !== '' || !$link) {
    $msg = "資料庫連線失敗：" . $dbError;
    echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    exit;
}

// load all reservation columns dynamically
$cols = [];
$colRes = mysqli_query($link, "SHOW COLUMNS FROM reservations");
if ($colRes) {
    while ($crow = mysqli_fetch_assoc($colRes)) {
        $cols[] = $crow['Field'];
    }
}
if (empty($cols)) {
    echo "找不到 reservations 欄位。";
    exit;
}

$selectSql = 'SELECT ' . implode(', ', array_map(function($c){ return "`".$c."`"; }, $cols)) . ' FROM reservations WHERE reservation_id = ? LIMIT 1';
$stmt = mysqli_prepare($link, $selectSql);
if (!$stmt) {
    $err = mysqli_error($link);
    echo "準備查詢失敗：" . htmlspecialchars($err, ENT_QUOTES, 'UTF-8');
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $reservationId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);
if (!$row) {
    echo "找不到該筆申請。";
    exit;
}

// fetch equipment and space items
$items = [];
$eqStmt = mysqli_prepare($link, 'SELECT eri.equipment_item_id, e.equipment_id, e.equipment_code, ec.equipment_name FROM equipment_reservation_items eri JOIN equipments e ON eri.equipment_id = e.equipment_id JOIN equipment_categories ec ON e.equipment_code = ec.equipment_code WHERE eri.reservation_id = ?');
if ($eqStmt) {
    mysqli_stmt_bind_param($eqStmt, 'i', $reservationId);
    mysqli_stmt_execute($eqStmt);
    $r = mysqli_stmt_get_result($eqStmt);
    if ($r) {
        while ($it = mysqli_fetch_assoc($r)) {
            $items[] = ['type'=>'equipment','code'=>$it['equipment_code'],'name'=>$it['equipment_name']];
        }
    }
    mysqli_stmt_close($eqStmt);
}
$spStmt = mysqli_prepare($link, 'SELECT sri.space_item_id, s.space_id, s.space_name FROM space_reservation_items sri JOIN spaces s ON sri.space_id = s.space_id WHERE sri.reservation_id = ?');
if ($spStmt) {
    mysqli_stmt_bind_param($spStmt, 'i', $reservationId);
    mysqli_stmt_execute($spStmt);
    $r = mysqli_stmt_get_result($spStmt);
    if ($r) {
        while ($it = mysqli_fetch_assoc($r)) {
            $items[] = ['type'=>'space','code'=>$it['space_id'],'name'=>$it['space_name']];
        }
    }
    mysqli_stmt_close($spStmt);
}

?><!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>審核詳情 - <?php echo safe_html((string)$reservationId, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        /* 簡單美化 approve detail */
        .card.borrow-form-card { background:#fbfdff; border:1px solid #e6eef8; box-shadow:0 2px 8px rgba(2,6,23,0.04); padding:20px; border-radius:10px; }
        .borrow-form label { display:block; font-weight:600; color:#0f172a; margin-bottom:6px; }
        .borrow-form input[disabled], .borrow-form textarea[disabled] { background:#f8fafc; border:1px solid #e2eef9; padding:8px 10px; border-radius:8px; color:#0f172a; }
        .borrow-form input[disabled] { height:40px; }
        .borrow-form textarea[disabled] { padding:10px; min-height:64px; }
        .borrow-form .btn-primary { background:#2563eb; color:#fff; padding:6px 10px; border-radius:8px; text-decoration:none; }
        .borrow-form .btn-secondary { background:#f8fafc; color:#0f172a; padding:6px 10px; border-radius:8px; border:1px solid #e2e8f0; text-decoration:none; }
        .borrow-form ul { margin:0.4rem 0 0 1.2rem; }
        .meta-label { color:#475569; font-size:0.95rem; margin-bottom:4px; }
        @media (max-width:900px) { .borrow-form { grid-template-columns:1fr !important; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/nav.php'; ?>
<div class="container">
    <main class="main-content">
        <section class="card borrow-form-card">
            <h2>審核詳情（編號：<?php echo safe_html((string)$reservationId, ENT_QUOTES, 'UTF-8'); ?>）</h2>
            <p><a href="approve.php">回到審核列表</a></p>

            <form class="borrow-form" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label>單位名稱 / 主辦社團</label>
                    <input type="text" value="<?php echo safe_html($row['organization_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div>
                    <label>活動名稱</label>
                    <input type="text" value="<?php echo safe_html($row['activity_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>


                <div>
                    <label>參與人數</label>
                    <input type="text" value="<?php echo safe_html($row['participant_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div>
                    <label>工作人員人數</label>
                    <input type="text" value="<?php echo safe_html($row['staff_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>

                <?php
                // 針對資料表中以 'yes' / 'no' 或 '1' 標記的欄位，只顯示值為 yes 的項目
                $booleanFields = [
                    'vehicle_entry' => ['label' => '車輛進入校園', 'extras' => []],
                    'setup_flags' => ['label' => '插立旗幟', 'extras' => ['flag_count','flag_organization_name','flag_activity_name','flag_responsible_person','flag_contact_phone']],
                    'has_alcohol' => ['label' => '含酒精活動', 'extras' => ['alcohol_coordinator','alcohol_president']],
                    'has_fire' => ['label' => '含明火', 'extras' => []],
                    'has_sales' => ['label' => '含販售/攤販', 'extras' => []],
                ];

                // 中文欄位標籤對照
                $extrasLabels = [
                    'flag_count' => '旗幟數量',
                    'flag_organization_name' => '旗幟申請單位',
                    'flag_activity_name' => '旗幟活動名稱',
                    'flag_responsible_person' => '旗幟負責人',
                    'flag_contact_phone' => '旗幟連絡電話',
                    'alcohol_coordinator' => '酒精活動負責人',
                    'alcohol_president' => '酒精活動社團社長'
                ];

                foreach ($booleanFields as $col => $meta) {
                    if (!in_array($col, $cols, true)) continue;
                    $val = $row[$col] ?? '';
                    if (!is_yes($val)) continue; // 只有 yes/1 才顯示
                ?>
                <div>
                    <label><?php echo safe_html($meta['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="text" value="<?php echo '是'; ?>" disabled>
                </div>
                <?php
                    // 顯示相關擴充欄位（若存在且有值）
                    foreach ($meta['extras'] as $extra) {
                        if (!in_array($extra, $cols, true)) continue;
                        $extraVal = $row[$extra] ?? '';
                        if (trim((string)$extraVal) === '') continue;
                        $label = $extrasLabels[$extra] ?? ucwords(str_replace('_',' ',$extra));
                ?>
                <div>
                    <label class="meta-label"><?php echo safe_html($label, ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="text" value="<?php echo safe_html($extraVal, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <?php
                    }
                }
                ?>

                <?php
                // 顯示明火相關欄位（改為單欄位 label + input 格式，並確保人員至少出現一列）
                $hasFireCol = in_array('has_fire', $cols, true) ? 'has_fire' : null;
                // 若 has_fire 標記為 yes，或任一 fire_* 欄位有內容，皆顯示明火區塊
                $anyFireField = false;
                $fireFieldCandidates = ['fire_activity_name','fire_activity','fire_date','fire_day','fire_time_start','fire_time_end','fire_time','fire_start_time','fire_end_time','fire_location','fire_staff_json','fire_performers','fire_oilers','fire_extinguishers','fire_security','fire_emergency','fire_medical'];
                foreach ($fireFieldCandidates as $f) {
                    if (in_array($f, $cols, true) && trim((string)($row[$f] ?? '')) !== '') { $anyFireField = true; break; }
                }
                if (( $hasFireCol && is_yes($row[$hasFireCol] ?? '') ) || $anyFireField) {
                    $fireActivity = trim((string)($row['fire_activity_name'] ?? $row['fire_activity'] ?? ''));
                    $fireDate = trim((string)($row['fire_date'] ?? $row['fire_day'] ?? ''));
                    $fireTimeStartRaw = trim((string)($row['fire_time_start'] ?? $row['fire_time'] ?? $row['fire_start_time'] ?? ''));
                    $fireTimeEndRaw = trim((string)($row['fire_time_end'] ?? $row['fire_end_time'] ?? ''));
                    $fireLocation = trim((string)($row['fire_location'] ?? ''));

                    // 格式化時間，去掉秒（若為 HH:MM:SS）
                    $fmtTime = function($t) {
                        if ($t === '') return '';
                        if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $t, $m)) return $m[1];
                        return $t;
                    };
                    $fireTimeStart = $fmtTime($fireTimeStartRaw);
                    $fireTimeEnd = $fmtTime($fireTimeEndRaw);

                    // 取得人員資料：優先取 fire_staff_json，否則個別欄位
                    $fireStaffLabels = [
                        'fire_performers' => '表演人員',
                        'fire_oilers' => '上油人員',
                        'fire_extinguishers' => '滅火人員',
                        'fire_security' => '維安人員',
                        'fire_emergency' => '緊急狀況處理人員',
                        'fire_medical' => '醫療人員'
                    ];

                    $staffData = [];
                    if (in_array('fire_staff_json', $cols, true) && trim((string)($row['fire_staff_json'] ?? '')) !== '') {
                        $decoded = json_decode($row['fire_staff_json'], true);
                        if (is_array($decoded)) {
                            // 預期若為關聯陣列，嘗試對應 key；若為平面陣列，放入 performers
                            $isAssoc = array_values($decoded) !== $decoded;
                            if ($isAssoc) {
                                foreach ($fireStaffLabels as $k => $_) {
                                    $staffData[$k] = is_array($decoded[$k] ?? null) ? $decoded[$k] : (array)($decoded[$k] ?? []);
                                }
                            } else {
                                $staffData['fire_performers'] = $decoded;
                                foreach ($fireStaffLabels as $k => $_) if (!isset($staffData[$k])) $staffData[$k] = [];
                            }
                        }
                    } else {
                        foreach (array_keys($fireStaffLabels) as $k) {
                            if (in_array($k, $cols, true) && trim((string)($row[$k] ?? '')) !== '') {
                                $val = $row[$k];
                                $decoded = json_decode($val, true);
                                if (is_array($decoded)) {
                                    $staffData[$k] = $decoded;
                                } else {
                                    $tmp = preg_split('/[\r\n,;]+/', (string)$val);
                                    $staffData[$k] = array_values(array_filter(array_map('trim', $tmp), function($v){ return $v !== ''; }));
                                }
                            } else {
                                // 欄位存在但空，或欄位不存在，先當成空陣列，之後至少會顯示一列空白輸入
                                $staffData[$k] = [];
                            }
                        }
                    }

                ?>
                <div>
                    <label>明火活動名稱</label>
                    <input type="text" value="<?php echo safe_html($fireActivity, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div>
                    <label>明火日期</label>
                    <input type="text" value="<?php echo safe_html($fireDate, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div>
                    <label>活動時間</label>
                    <input type="text" value="<?php echo safe_html(($fireTimeStart ?: '') . ($fireTimeStart && $fireTimeEnd ? ' ~ ' : '') . ($fireTimeEnd ?: ''), ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div style="grid-column:1/3;">
                    <label>活動地點</label>
                    <input type="text" value="<?php echo safe_html($fireLocation, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>

                <?php
                    // 顯示人員群組：即使為空，也至少顯示一個空輸入框
                    foreach ($fireStaffLabels as $key => $label) {
                        // 若資料表完全沒有這個欄位，也可能是我們仍要顯示空欄位？只在欄位存在或 fire_staff_json 存在時顯示
                        if (!in_array($key, $cols, true) && !in_array('fire_staff_json', $cols, true)) continue;
                        $list = $staffData[$key] ?? [];
                        if (!is_array($list)) $list = [];
                        if (empty($list)) $list = [''];
                ?>
                    <div style="grid-column:1/3;margin-top:0.5rem;padding:0.5rem;border-radius:6px;background:#fafbfd;border:1px solid #eef6ff;">
                        <label class="meta-label"><?php echo safe_html($label, ENT_QUOTES, 'UTF-8'); ?></label>
                        <?php foreach ($list as $p) { ?>
                            <div style="margin:6px 0;"><input type="text" value="<?php echo safe_html($p, ENT_QUOTES, 'UTF-8'); ?>" disabled style="width:40%;"></div>
                        <?php } ?>
                    </div>
                <?php } // end foreach staff groups ?>
                <?php
                } // end if has_fire
                ?>

                <div>
                    <label>承辦單位 / 科別</label>
                    <input type="text" value="<?php echo safe_html($row['coordinator_department'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div>
                    <label>聯絡電話</label>
                    <input type="text" value="<?php echo safe_html($row['coordinator_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>

                <div style="grid-column:1/3;">
                    <label>活動用途 / 備註</label>
                    <textarea rows="3" style="width:100%;" disabled><?php echo safe_html($row['purpose'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <?php
                $bs = $row['borrow_start_at'] ? strtotime($row['borrow_start_at']) : null;
                $be = $row['borrow_end_at'] ? strtotime($row['borrow_end_at']) : null;
                $bs_date = $bs ? date('Y-m-d', $bs) : '';
                $bs_time = $bs ? date('H:i', $bs) : '';
                $be_date = $be ? date('Y-m-d', $be) : '';
                $be_time = $be ? date('H:i', $be) : '';
                ?>
                <div>
                    <label>借用開始</label>
                    <input type="text" value="<?php echo $bs_date . ' ' . $bs_time; ?>" disabled>
                </div>
                <div>
                    <label>借用結束</label>
                    <input type="text" value="<?php echo $be_date . ' ' . $be_time; ?>" disabled>
                </div>

                <div style="grid-column:1/3;">
                    <label>申請項目</label>
                    <?php if (empty($items)) { ?>
                        <div>無申請項目</div>
                    <?php } else { ?>
                        <ul>
                            <?php foreach ($items as $it) { ?>
                                <li><?php echo $it['type'] === 'space' ? '【空間】' : '【器材】'; ?> <?php echo safe_html($it['code'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo safe_html($it['name'], ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php } ?>
                        </ul>
                    <?php } ?>
                </div>

                <div style="grid-column:1/3;">
                    <label>活動企劃書</label>
                    <?php if (!empty($row['proposal_file'])) { $pf = $row['proposal_file']; $bn = safe_html(basename((string)$pf), ENT_QUOTES, 'UTF-8'); ?>
                        <div><a href="<?php echo safe_html((string)$pf, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="btn-primary" style="display:inline-block;padding:0.4rem 0.8rem;border-radius:6px;color:#fff;text-decoration:none;background:#3b82f6;">開啟企劃書：<?php echo $bn; ?></a></div>
                    <?php } else { echo '<div><em>未上傳企劃書</em></div>'; } ?>
                </div>

                <div style="grid-column:1/3;text-align:right;margin-top:0.5rem;">
                    <a href="approve.php" class="btn-secondary" style="text-decoration:none;padding:0.5rem 0.8rem;border-radius:6px;">返回審核列表</a>
                </div>
            </form>
        </section>
    </main>
</div>
</body>
</html>
