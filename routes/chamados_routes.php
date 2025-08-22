<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

if ($uri[0] === "chamados") {
    $payload = autenticar();

    if ($payload->role === "cliente" && $method === "GET") {
        $stmt = $pdo->prepare("SELECT * FROM chamados WHERE cliente_id = :id");
        $stmt->execute(['id' => $payload->sub]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($payload->role === "tecnico" && $method === "GET") {
        $stmt = $pdo->prepare("SELECT * FROM chamados WHERE tecnico_id = :id");
        $stmt->execute(['id' => $payload->sub]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($payload->role === "admin" && $method === "GET") {
        $stmt = $pdo->query("SELECT * FROM chamados");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
}
