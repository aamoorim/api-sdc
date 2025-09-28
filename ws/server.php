<?php
// ws/server.php
declare(strict_types=1);

require __DIR__ . '/../config/db.php'; // seu db.php retorna $pdo
require __DIR__ . '/ChatServer.php';

$server = new ChatServer('127.0.0.1', 9000, $pdo);
$server->run();
