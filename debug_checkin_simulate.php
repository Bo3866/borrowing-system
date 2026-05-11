<?php
require_once __DIR__ . '/config/database.php';
$err = '';
$link = getMysqliConnection($err);
if (!$link) { echo 'DB_CONNECT_ERR: ' . $err . PHP_EOL; exit(1); }

mysqli_begin_transaction($link);
try {
    // Create test user
    $testUserId = 'test_user_ci';
    $res = mysqli_prepare($link, 'SELECT user_id FROM users WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($res, 's', $testUserId);
    mysqli_stmt_execute($res);
    $r = mysqli_stmt_get_result($res);
    $exists = $r && mysqli_fetch_assoc($r);
    mysqli_stmt_close($res);

    if (!$exists) {
        $pw = password_hash('password', PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($link, 'INSERT INTO users (user_id, full_name, password, email) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'ssss', $testUserId, $testUserId, $pw, $testUserId.'@example.test');
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo "Inserted test user: $testUserId\n";
    } else {
        echo "Test user already exists: $testUserId\n";
    }

    // Create a equipment
    $equipmentCode = 'TESTCODE';
    $equipmentName = 'Test Equipment';
    $stmt = mysqli_prepare($link, 'INSERT INTO equipment_categories (equipment_code, equipment_name) VALUES (?, ?)');
    mysqli_stmt_bind_param($stmt, 'ss', $equipmentCode, $equipmentName);
    mysqli_execute_or_throw:
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Create equipment row
    $stmt = mysqli_prepare($link, 'INSERT INTO equipments (equipment_code, operation_status) VALUES (?, 1)');
    mysqli_stmt_bind_param($stmt, 's', $equipmentCode);
    mysqli_stmt_execute($stmt);
    $equipmentId = mysqli_insert_id($link);
    mysqli_stmt_close($stmt);
    echo "Created equipment id: $equipmentId\n";

    // Create space
    $spaceName = 'Test Space';
    $stmt = mysqli_prepare($link, 'INSERT INTO spaces (space_name, space_status) VALUES (?, "1")');
    mysqli_stmt_bind_param($stmt, 's', $spaceName);
    mysqli_stmt_execute($stmt);
    $spaceId = mysqli_insert_id($link);
    mysqli_stmt_close($stmt);
    echo "Created space id: $spaceId\n";

    // Create reservation
    $now = date('Y-m-d H:i:s');
    $later = date('Y-m-d H:i:s', time() + 3600);
    $stmt = mysqli_prepare($link, 'INSERT INTO reservations (user_id, borrow_start_at, borrow_end_at, approval_status, submitted_at) VALUES (?, ?, ?, "approved", ?)');
    mysqli_stmt_bind_param($stmt, 'ssss', $testUserId, $now, $later, $now);
    mysqli_stmt_execute($stmt);
    $reservationId = mysqli_insert_id($link);
    mysqli_stmt_close($stmt);
    echo "Created reservation id: $reservationId\n";

    // Link equipment to reservation
    $stmt = mysqli_prepare($link, 'INSERT INTO equipment_reservation_items (reservation_id, equipment_id) VALUES (?, ?)');
    mysqli_stmt_bind_param($stmt, 'ii', $reservationId, $equipmentId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo "Linked equipment to reservation\n";

    // Link space to reservation
    $stmt = mysqli_prepare($link, 'INSERT INTO space_reservation_items (reservation_id, space_id) VALUES (?, ?)');
    mysqli_stmt_bind_param($stmt, 'ii', $reservationId, $spaceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo "Linked space to reservation\n";

    mysqli_commit($link);
} catch (Throwable $e) {
    mysqli_rollback($link);
    echo 'Setup error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Now attempt equipment checkin
try {
    $stmt = mysqli_prepare($link, 'INSERT INTO checkin_logs (reservation_id, user_id, checked_in_equipment_id, checkin_source) VALUES (?, ?, ?, "equipment")');
    mysqli_stmt_bind_param($stmt, 'iss', $reservationId, $testUserId, $equipmentId);
    $res = mysqli_stmt_execute($stmt);
    if (!$res) {
        echo 'Equipment insert error: ' . mysqli_error($link) . PHP_EOL;
    } else {
        echo "Equipment checkin inserted.\n";
    }
    mysqli_stmt_close($stmt);
} catch (Throwable $e) {
    echo 'Equipment insert exception: ' . $e->getMessage() . PHP_EOL;
}

// Then attempt space checkin
try {
    $stmt = mysqli_prepare($link, 'INSERT INTO checkin_logs (reservation_id, user_id, checkin_source) VALUES (?, ?, "qr")');
    mysqli_stmt_bind_param($stmt, 'is', $reservationId, $testUserId);
    $res = mysqli_stmt_execute($stmt);
    if (!$res) {
        echo 'Space insert error: ' . mysqli_error($link) . PHP_EOL;
    } else {
        echo "Space checkin inserted.\n";
    }
    mysqli_stmt_close($stmt);
} catch (Throwable $e) {
    echo 'Space insert exception: ' . $e->getMessage() . PHP_EOL;
}

// Show checkin_logs rows for this reservation
$res = mysqli_prepare($link, 'SELECT * FROM checkin_logs WHERE reservation_id = ?');
mysqli_stmt_bind_param($res, 'i', $reservationId);
mysqli_stmt_execute($res);
$rr = mysqli_stmt_get_result($res);
while ($row = mysqli_fetch_assoc($rr)) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
mysqli_stmt_close($res);

mysqli_close($link);
