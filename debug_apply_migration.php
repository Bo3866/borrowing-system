<?php
require_once __DIR__ . '/config/database.php';
$err = '';
$link = getMysqliConnection($err);
if (!$link) {
    echo 'DB_CONNECT_ERR: ' . $err . PHP_EOL;
    exit(1);
}

echo "Connected to DB.\n";

// Check existing columns
$cols = [];
$res = mysqli_query($link, "SHOW COLUMNS FROM checkin_logs");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $cols[] = $r['Field'];
    }
} else {
    echo 'SHOW COLUMNS ERR: ' . mysqli_error($link) . PHP_EOL;
    exit(1);
}

$alterParts = [];
if (!in_array('checked_in_equipment_id', $cols, true)) {
    $alterParts[] = 'ADD COLUMN checked_in_equipment_id BIGINT UNSIGNED NULL';
}

if (count($alterParts) > 0) {
    $sql = 'ALTER TABLE checkin_logs ' . implode(', ', $alterParts);
    if (mysqli_query($link, $sql)) {
        echo "ALTER OK: $sql\n";
    } else {
        echo 'ALTER ERR: ' . mysqli_error($link) . PHP_EOL;
    }
} else {
    echo "No column changes needed.\n";
}

// Ensure indexes exist
$existingIndexes = [];
$ir = mysqli_query($link, 'SHOW INDEX FROM checkin_logs');
if ($ir) {
    while ($ix = mysqli_fetch_assoc($ir)) {
        $existingIndexes[] = $ix['Key_name'];
    }
}

$indexStatements = [];
if (!in_array('idx_checked_in_equipment_id', $existingIndexes, true)) {
    $indexStatements[] = 'ALTER TABLE checkin_logs ADD INDEX idx_checked_in_equipment_id (checked_in_equipment_id)';
}

foreach ($indexStatements as $istmt) {
    if (mysqli_query($link, $istmt)) {
        echo "INDEX OK: $istmt\n";
    } else {
        echo 'INDEX ERR: ' . mysqli_error($link) . PHP_EOL;
    }
}

// Show final schema
echo "Final checkin_logs columns:\n";
$cr = mysqli_query($link, 'SHOW COLUMNS FROM checkin_logs');
while ($c = mysqli_fetch_assoc($cr)) {
    echo $c['Field'] . '\t' . $c['Type'] . PHP_EOL;
}

mysqli_close($link);
