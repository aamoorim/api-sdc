<?php
// routes/mensagens_routes.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error'=>'Method not allowed']);
    exit;
}

$chamado_id = intval($_GET['chamado_id'] ?? 0);
$token = $_GET['token'] ?? null;
if (!$chamado_id) {
    http_response_code(400);
    echo json_encode(['error'=>'missing chamado_id']);
    exit;
}

$payload = null;
if ($token) {
    if (function_exists('validarToken')) {
        $payload = validarToken($token);
    }
}

try {
    global $pdo;
    $sql = <<<SQL
SELECT m.id, m.mensagem, m.criado_em,
       u.id AS usuario_id, u.nome, u.tipo_usuario,
       c.empresa, c.setor, t.cargo
FROM mensagens m
JOIN usuarios u ON u.id = m.usuario_id
JOIN chamados ch ON ch.id = m.chamado_id
LEFT JOIN clientes c ON c.id = ch.cliente_id
LEFT JOIN tecnicos t ON t.id = ch.tecnico_id
WHERE m.chamado_id = :chamado_id
ORDER BY m.criado_em ASC
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':chamado_id'=>$chamado_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'mensagens'=>$rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error'=>'db_error','msg'=>$e->getMessage()]);
}
