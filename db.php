
<?php
$host = "localhost";
$dbname = "etrack";
$username = "root";
$password = "";

try {
    $db = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit("Database connection failed. Check MySQL settings.");
}
  