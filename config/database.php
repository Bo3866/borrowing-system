<?php
declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $host = '127.0.0.1';
    // Use port 3307 to match mysqli usage elsewhere in the project
    $port = '3307';
    $database = 'borrowing_system';
    $username = 'root';
    // MySQL in this workspace uses an empty root password for local dev
    $password = '12345678';

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
