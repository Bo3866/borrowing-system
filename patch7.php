<?php
$f = "c:/AppServ/www/borrowing-system/borrow.php";
$c = file_get_contents($f);

$c = preg_replace(
    '/(mysqli_stmt_close\(\$updateEquipmentStatusStmt\);\s*)\}(\s*\/\/\s*嘗試建立器材核簽紀錄（若申請人有有效證照）)/um',
    '$1$2',
    $c
);

file_put_contents($f, $c);
?>
