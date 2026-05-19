<?php
$c = file_get_contents("amend_application.php");

$c = str_replace(
    "'vehicle_entry', 'setup_flags', 'flag_count', 'proposal_file'",
    "'vehicle_entry', 'has_alcohol', 'has_fire', 'has_sales', 'setup_flags', 'flag_count', 'proposal_file'",
    $c
);

$c = str_replace(
    "'vehicle_entry' => \$reservationRow['vehicle_entry'] ?? 'no',",
    "'vehicle_entry' => \$reservationRow['vehicle_entry'] ?? 'no',\n                    'has_alcohol' => \$reservationRow['has_alcohol'] ?? '',\n                    'has_fire' => \$reservationRow['has_fire'] ?? '',\n                    'has_sales' => \$reservationRow['has_sales'] ?? '',",
    $c
);

$c = str_replace(
    "'vehicle_entry' => trim((string)(\$_POST['vehicle_entry'] ?? 'no')),",
    "'vehicle_entry' => trim((string)(\$_POST['vehicle_entry'] ?? 'no')),\n                    'has_alcohol' => isset(\$_POST['has_alcohol']) ? '1' : '',\n                    'has_fire' => isset(\$_POST['has_fire']) ? '1' : '',\n                    'has_sales' => isset(\$_POST['has_sales']) ? '1' : '',",
    $c
);

file_put_contents("amend_application.php", $c);

