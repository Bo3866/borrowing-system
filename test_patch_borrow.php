<?php
$content = file_get_contents("borrow.php");
$old = "\$reservationStmt = mysqli_prepare(\$link, \$insertReservationSql);\n                if (!\$reservationStmt) {\n                    throw new RuntimeException('建立預約主檔失敗：' . mysqli_error(\$link));\n                }";
$new = "\$reservationStmt = mysqli_prepare(\$link, \$insertReservationSql);\n                if (!\$reservationStmt) {\n                    file_put_contents(__DIR__ . '/borrow_debug.log', \"SQL: \$insertReservationSql\\nError: \" . mysqli_error(\$link) . \"\\n\", FILE_APPEND);\n                    throw new RuntimeException('建立預約主檔失敗：' . mysqli_error(\$link));\n                }";
if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    file_put_contents("borrow.php", $content);
    echo "Patched borrow.php\n";
} else {
    echo "Could not find the target string in borrow.php\n";
}

