<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

if ($uri[0] === "tecnicos") {
    $payload = autenticar();
    if ($payload->role !== "admin") {
        http_response_code(403);
        echo json_encode(["erro" => "Acesso negado"]);
        exit;
    }

    if ($method === "GET") {
        $stmt = $pdo->query("SELECT id, nome, email, cargo FROM usuarios WHERE role = 'tecnico'");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($method === "POST") {
        $input = json_decode(file_get_contents("php://input"), true);
        $hash = password_hash($input['senha'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome,email,senha,cargo,role) VALUES (:nome,:email,:senha,:cargo,'tecnico')");
        $stmt->execute([
            "nome" => $input['nome'],
            "email" => $input['email'],
            "senha" => $hash,
            "cargo" => $input['cargo']
        ]);
        echo json_encode(["status" => "tecnico criado"]);
        exit;
    }
}
