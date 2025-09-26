<?php
// ws/run_ws.php

require_once __DIR__ . '/ChatServer.php';
require_once __DIR__ . '/../config/db.php';    // $pdo
require_once __DIR__ . '/../config/jwt.php';   // validarToken(), JWT_SECRET

$addr = '0.0.0.0';   // escuta em todas as interfaces
$port = 9000;        // porta do WebSocket

$server = new ChatServer($addr, $port);

echo "========== Chat WebSocket ==========\n";
echo "Listening on {$addr}:{$port}\n";
echo "-----------------------------------\n";

// Rodar o loop principal
$server->run();
