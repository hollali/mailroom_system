<?php

// Load .env file if it exists (no Composer needed)
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

$host     = $_ENV['DB_HOST']  ?? getenv('DB_HOST')  ?: 'localhost';
$user     = $_ENV['DB_USER']  ?? getenv('DB_USER')  ?: 'root';
$password = $_ENV['DB_PASS']  ?? getenv('DB_PASS')  ?: '';
$dbname   = $_ENV['DB_NAME']  ?? getenv('DB_NAME')  ?: 'mailroom_system';

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
?>