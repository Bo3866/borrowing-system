<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
session_start();
// DEBUG: show errors during development
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

@file_put_contents(__DIR__ . '/view_reservation_debug.log', date('c') . " start view_reservation for _GET=" . json_encode($_GET, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=view_reservation.php');
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
    @file_put_contents(__DIR__ . '/view_reservation_debug.log', date('c') . " invalid reservation_id=" . json_encode($_GET, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);
if ($dbError !== '' || !$link) {
    $msg = "資料庫連線失敗：" . $dbError;
    echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    @file_put_contents(__DIR__ . '/view_reservation_debug.log', date('c') . " dbError: " . json_encode($dbError, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit;
}

// get all columns from reservations and select the row
$cols = [];
$colRes = mysqli_query($link, "SHOW COLUMNS FROM reservations");
if ($colRes) {
    while ($crow = mysqli_fetch_assoc($colRes)) {
        $cols[] = $crow['Field'];
    }
}
@file_put_contents(__DIR__ . '/view_reservation_debug.log', date('c') . " reservation columns: " . json_encode($cols, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
if (empty($cols)) {
    echo "找不到 reservations 欄位。";
    exit;
}

$selectSql = 'SELECT ' . implode(', ', array_map(function($c){ return "`".$c."`"; }, $cols)) . ' FROM reservations WHERE reservation_id = ? LIMIT 1';
$stmt = mysqli_prepare($link, $selectSql);
if (!$stmt) {
    $err = mysqli_error($link);
    echo "準備查詢失敗：" . htmlspecialchars($err, ENT_QUOTES, 'UTF-8');
    @file_put_contents(__DIR__ . '/view_reservation_debug.log', date('c') . " prepare failed: " . json_encode($err, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $reservationId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);
@file_put_contents(__DIR__ . '/view_reservation_debug.log', date('c') . " fetched row: " . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
if (!$row) {
    echo "找不到該筆申請。";
    exit;
}

// fetch items
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
    <title>申請詳細 - <?php echo htmlspecialchars($reservationId, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
</head>
<body>
<?php include __DIR__ . '/nav.php'; ?>
<div class="container">
    <main class="main-content">
        <section class="card borrow-form-card">
            <h2>申請詳細（編號：<?php echo htmlspecialchars($reservationId, ENT_QUOTES, 'UTF-8'); ?>）</h2>
            <p><a href="approve.php">回到審核列表</a></p>

            <form class="borrow-form" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label>單位名稱 / 主辦社團</label>
                    <input type="text" value="<?php echo htmlspecialchars($row['organization_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div>
                    <label>活動名稱</label>
                    <input type="text" value="<?php echo htmlspecialchars($row['activity_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>

                <div>
                    <label>參與人數</label>
                    <input type="text" value="<?php echo htmlspecialchars($row['participant_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div>
                    <label>工作人員人數</label>
                    <input type="text" value="<?php echo htmlspecialchars($row['staff_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>

                <!-- 會長 / 承辦人 欄位已移除 -->
                <div>
                    <label>聯絡電話</label>
                    <input type="text" value="<?php echo htmlspecialchars($row['coordinator_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>

                <div style="grid-column:1/3;">
                    <label>活動用途 / 備註</label>
                    <textarea rows="3" style="width:100%;" disabled><?php echo htmlspecialchars($row['purpose'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
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
                                <li><?php echo $it['type'] === 'space' ? '【空間】' : '【器材】'; ?> <?php echo htmlspecialchars($it['code'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php } ?>
                        </ul>
                    <?php } ?>
                </div>

                <div style="grid-column:1/3;">
                    <label>活動企劃書</label>
                    <?php if (!empty($row['proposal_file'])) { $pf = $row['proposal_file']; $bn = htmlspecialchars(basename((string)$pf), ENT_QUOTES, 'UTF-8'); ?>
                        <div><a href="<?php echo htmlspecialchars((string)$pf, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="btn-primary" style="display:inline-block;padding:0.4rem 0.8rem;border-radius:6px;color:#fff;text-decoration:none;background:#3b82f6;">開啟企劃書：<?php echo $bn; ?></a></div>
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
