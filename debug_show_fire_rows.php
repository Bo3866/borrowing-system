<?php
require_once __DIR__ . '/config/database.php';

$error = '';
$link = getMysqliConnection($error);
if (!$link) {
    echo "DB_CONN_ERROR: " . $error . PHP_EOL;
    exit(1);
}

$sql = "SELECT reservation_id, has_fire, fire_activity_name, fire_activity, fire_date, fire_time_start, fire_time_end, fire_time, fire_start_time, fire_end_time, fire_location, fire_staff_json, fire_performers, fire_oilers, fire_extinguishers, fire_security, fire_emergency, fire_medical FROM reservations WHERE 
    ( (fire_activity_name IS NOT NULL AND TRIM(fire_activity_name) != '') OR
      (fire_activity IS NOT NULL AND TRIM(fire_activity) != '') OR
      (fire_date IS NOT NULL AND TRIM(fire_date) != '') OR
      (fire_time_start IS NOT NULL AND TRIM(fire_time_start) != '') OR
      (fire_time_end IS NOT NULL AND TRIM(fire_time_end) != '') OR
      (fire_time IS NOT NULL AND TRIM(fire_time) != '') OR
      (fire_start_time IS NOT NULL AND TRIM(fire_start_time) != '') OR
      (fire_end_time IS NOT NULL AND TRIM(fire_end_time) != '') OR
      (fire_location IS NOT NULL AND TRIM(fire_location) != '') OR
      (fire_staff_json IS NOT NULL AND TRIM(fire_staff_json) != '') OR
      (fire_performers IS NOT NULL AND TRIM(fire_performers) != '') OR
      (fire_oilers IS NOT NULL AND TRIM(fire_oilers) != '') OR
      (fire_extinguishers IS NOT NULL AND TRIM(fire_extinguishers) != '') OR
      (fire_security IS NOT NULL AND TRIM(fire_security) != '') OR
      (fire_emergency IS NOT NULL AND TRIM(fire_emergency) != '') OR
      (fire_medical IS NOT NULL AND TRIM(fire_medical) != '') OR
      (has_fire IS NOT NULL AND TRIM(has_fire) IN ('1','yes'))
    )
    ORDER BY reservation_id DESC
    LIMIT 20";

$res = mysqli_query($link, $sql);
$out = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = $r;
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
mysqli_close($link);
