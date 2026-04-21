<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=run_schema.php');
    exit;
}

$schemaFile = __DIR__ . DIRECTORY_SEPARATOR . 'schema.sql';
if (!is_file($schemaFile)) {
    echo 'schema.sql 找不到於 ' . htmlspecialchars($schemaFile, ENT_QUOTES, 'UTF-8');
    exit;
}

$sql = file_get_contents($schemaFile);
if ($sql === false) {
    echo '無法讀取 schema.sql';
    exit;
}

$host = '127.0.0.1';
$port = 3306;
$username = 'root';
$password = '12345678';

$mysqli = @new mysqli($host, $username, $password, '', $port);
if ($mysqli->connect_errno) {
    echo '無法連線 MySQL：' . htmlspecialchars($mysqli->connect_error, ENT_QUOTES, 'UTF-8');
    exit;
}

// mysqli::multi_query 支援執行多個 SQL 語句
if (!$mysqli->multi_query($sql)) {
    echo '執行 schema 失敗：' . htmlspecialchars($mysqli->error, ENT_QUOTES, 'UTF-8');
    $mysqli->close();
    exit;
}

$messages = [];
$ok = true;
$idx = 0;

do {
    if ($result = $mysqli->store_result()) {
        // 如果是 SELECT 之類的，可略過
        $result->free();
    } else {
        if ($mysqli->errno) {
            $messages[] = "第 {$idx} 段錯誤: " . $mysqli->error;
            $ok = false;
        }
    }
    $idx++;
} while ($mysqli->more_results() && $mysqli->next_result());

$mysqli->close();

if ($ok) {
    echo 'schema.sql 執行完成，資料表與初始資料應已建立。';
} else {
    echo '執行過程有錯誤：<br>' . implode('<br>', array_map('htmlspecialchars', $messages));
}
