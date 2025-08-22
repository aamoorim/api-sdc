<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

if ($method === "POST" && ($uri[1] ?? '') === "login") {
    $input = json_decode(file_get_contents("php://input"), true);
    $email = $input['email'] ?? '';
    $senha = $input['senha'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($senha, $usuario['senha'])) {
        $token = gerarToken($usuario);
        echo json_encode([
            "token" => $token,
            "role"  => $usuario['tipo_usuario']
        ])  ;
    } else {
        http_response_code(401);
        echo json_encode(["erro" => "Credenciais inválidas"]);
    }
    exit;
}
