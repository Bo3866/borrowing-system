<?php
require_once "config/database.php";
$candidates = getDatabaseConnectionCandidates();
$sql = file_get_contents("add_missing_columns.sql");

foreach ($candidates as $config) {
    try {
        $link = mysqli_connect($config["host"], $config["username"], $config["password"], $config["database"], $config["port"]);
        if ($link) {
            echo "Connected to port {$config["port"]}\n";
            if (mysqli_multi_query($link, $sql)) {
                do {
                    if ($res = mysqli_store_result($link)) {
                        mysqli_free_result($res);
                    }
                } while (mysqli_more_results($link) && mysqli_next_result($link));
                echo "Successfully executed SQL on port {$config["port"]}\n";
            } else {
                echo "Failed to execute SQL on port {$config["port"]}: " . mysqli_error($link) . "\n";
            }
            mysqli_close($link);
        } else {
            echo "Failed to connect to port {$config["port"]}: " . mysqli_connect_error() . "\n";
        }
    } catch(Exception $e) {
        echo "Exception on port {$config["port"]}: " . $e->getMessage() . "\n";
    }
}

