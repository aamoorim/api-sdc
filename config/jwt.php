<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function gerarToken($usuario, $tipo_usuario) {
    $payload = [
        "sub" => $usuario['id'],
        "email" => $usuario['email'],
        "role" => $tipo_usuario,
        "usuario_id" => $usuario['usuario_id'] ?? $usuario['id'], // Para clientes/técnicos que têm usuario_id
        "iat" => time(),
        "exp" => time() + (60) // 8 horas
    ];
    return JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
}

function validarToken($token) {
    try {
        return JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
    } catch (Exception $e) {
        return null;
    }
}

function autenticar() {
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(["erro" => "Token não fornecido"]);
        exit;
    }
    
    $token = str_replace("Bearer ", "", $headers['Authorization']);
    $payload = validarToken($token);
    
    if (!$payload) {
        http_response_code(401);
        echo json_encode(["erro" => "Token inválido ou expirado"]);
        exit;
    }
    
    return $payload;
}