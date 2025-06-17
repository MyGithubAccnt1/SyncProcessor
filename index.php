<?php

$host = "jti-main-database.c3igsey6qfjc.ap-southeast-2.rds.amazonaws.com";
$dbname = "postgres";
$username = "postgres";
$password = "Journeytech";
$port = "5432";

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected successfully to the database.";

} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}