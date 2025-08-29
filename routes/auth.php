<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

if ($method === "POST" && ($uri[1] ?? '') === "login") {
    $input = json_decode(file_get_contents("php://input"), true);
    $email = $input['email'] ?? '';
    $senha = $input['senha'] ?? '';
    
    if (empty($email) || empty($senha)) {
        http_response_code(400);
        echo json_encode(["erro" => "Email e senha são obrigatórios"]);
        exit;
    }
    
    $usuario = null;
    $tipo_usuario = null;
    
    // Buscar usuário na tabela usuarios primeiro
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        http_response_code(401);
        echo json_encode(["erro" => "Credenciais inválidas"]);
        exit;
    }
    
    // Usar o tipo_usuario que já está definido na tabela usuarios
    $usuario = $result;
    $tipo_usuario = $result['tipo_usuario']; // admin, cliente, ou tecnico
    
    // Para clientes e técnicos, buscar informações adicionais se necessário
    if ($tipo_usuario === 'cliente') {
        $stmt = $pdo->prepare("SELECT c.*, u.* FROM clientes c JOIN usuarios u ON c.usuario_id = u.id WHERE u.id = :id");
        $stmt->execute(['id' => $result['id']]);
        $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cliente_info) {
            $usuario = array_merge($usuario, ['empresa' => $cliente_info['empresa'], 'setor' => $cliente_info['setor']]);
        }
    } elseif ($tipo_usuario === 'tecnico') {
        $stmt = $pdo->prepare("SELECT t.*, u.* FROM tecnicos t JOIN usuarios u ON t.usuario_id = u.id WHERE u.id = :id");
        $stmt->execute(['id' => $result['id']]);
        $tecnico_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tecnico_info) {
            $usuario = array_merge($usuario, ['cargo' => $tecnico_info['cargo']]);
        }
    }
    
    if ($usuario && password_verify($senha, $usuario['senha'])) {
        $token = gerarToken($usuario, $tipo_usuario);
        echo json_encode([
            "token" => $token,
            "role" => $tipo_usuario,
            "nome" => $usuario['nome'],
            "email" => $usuario['email'],
            "id" => $usuario['id']
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["erro" => "Credenciais inválidas"]);
    }
    exit;
}

// Logout (opcional - para invalidar token no frontend)
if ($method === "POST" && ($uri[1] ?? '') === "logout") {
    echo json_encode(["status" => "Logout realizado com sucesso"]);
    exit;
}

// Verificar se token é válido
if ($method === "GET" && ($uri[1] ?? '') === "verify") {
    $payload = autenticar();
    echo json_encode([
        "valido" => true,
        "usuario" => [
            "id" => $payload->sub,
            "email" => $payload->email,
            "role" => $payload->role
        ]
    ]);
    exit;
}

http_response_code(404);
echo json_encode(["erro" => "Rota de autenticação não encontrada"]);