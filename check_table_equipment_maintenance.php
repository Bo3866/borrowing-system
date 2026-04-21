<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=check_table_equipment_maintenance.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
try {
    $pdo = getDatabaseConnection();
} catch (Throwable $t) {
    echo 'DB 連線失敗：' . htmlspecialchars($t->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :tbl LIMIT 1");
    $tbl = 'equipment_maintenance';
    $stmt->execute(['tbl' => $tbl]);
    $exists = (bool)$stmt->fetchColumn();

    if (!$exists) {
        echo "表格 '" . htmlspecialchars($tbl, ENT_QUOTES, 'UTF-8') . "' 不存在於當前資料庫 (" . htmlspecialchars($pdo->query('SELECT DATABASE()')->fetchColumn(), ENT_QUOTES, 'UTF-8') . ").";
        exit;
    }

    $stmt2 = $pdo->prepare("SHOW CREATE TABLE equipment_maintenance");
    $stmt2->execute();
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    echo '<pre>' . htmlspecialchars($row['Create Table'], ENT_QUOTES, 'UTF-8') . '</pre>';
} catch (Throwable $t) {
    echo '查詢失敗：' . htmlspecialchars($t->getMessage(), ENT_QUOTES, 'UTF-8');
}
