<?php
$content = file_get_contents("borrow.php");
$content = str_replace(
    "throw new RuntimeException('建立預約主檔失敗：' . mysqli_error(\$link));",
    "file_put_contents(__DIR__ . '/borrow_debug.log', \"SQL: \$insertReservationSql\\nError: \" . mysqli_error(\$link) . \"\\n\", FILE_APPEND);\n                    throw new RuntimeException('建立預約主檔失敗：' . mysqli_error(\$link));",
    $content
);
file_put_contents("borrow.php", $content);
echo "Patched.\n";

