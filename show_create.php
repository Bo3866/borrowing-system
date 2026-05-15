<?php
require_once "config/database.php";
$pdo = getDatabaseConnection();
$res = $pdo->query("SHOW CREATE TABLE reservations");
echo $res->fetchColumn(1);

