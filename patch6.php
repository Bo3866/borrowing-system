<?php
$f = "c:/AppServ/www/borrowing-system/borrow.php";
$c = file_get_contents($f);

$c = str_replace(
"                    mysqli_stmt_close(\$reservationItemStmt);
                    mysqli_stmt_close(\$updateEquipmentStatusStmt);
                }
                    // 嘗試建立器材核簽紀錄（若申請人有有效證照）",
"                    mysqli_stmt_close(\$reservationItemStmt);
                    mysqli_stmt_close(\$updateEquipmentStatusStmt);
                    
                    // 嘗試建立器材核簽紀錄（若申請人有有效證照）",
$c);

// Also we should check if there's multiple of these string? Only one probably.
file_put_contents($f, $c);
?>
