<?php
$link = mysqli_connect('localhost', 'root', '12345678', 'borrowing_system');
if (!$link) {
    echo '資料庫連線失敗：' . mysqli_connect_error() . "\n";
    exit;
}
mysqli_set_charset($link, 'utf8mb4');

// 檢查 space_reservation_items 結構
$result = mysqli_query($link, "DESCRIBE space_reservation_items");
echo "=== space_reservation_items 結構 ===\n";
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo $row['Field'] . " | " . $row['Type'] . " | " . $row['Null'] . " | " . $row['Key'] . "\n";
    }
}

// 檢查是否有資料
echo "\n=== space_reservation_items 現有資料數 ===\n";
$countResult = mysqli_query($link, "SELECT COUNT(*) as cnt FROM space_reservation_items");
if ($countResult) {
    $row = mysqli_fetch_assoc($countResult);
    echo "共 " . $row['cnt'] . " 筆\n";
}

// 顯示前5筆
echo "\n=== space_reservation_items 前5筆資料 ===\n";
$dataResult = mysqli_query($link, "SELECT * FROM space_reservation_items LIMIT 5");
if ($dataResult) {
    while ($row = mysqli_fetch_assoc($dataResult)) {
        print_r($row);
    }
}

mysqli_close($link);
?>
