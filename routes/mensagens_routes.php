<?php
// routes/mensagens_route.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // ajuste em produção

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

$pdo = $pdo ?? null;
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['error'=>'db not configured']);
  exit;
}

// get token from Authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? null);
$token = null;
if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $m)) {
  $token = $m[1];
}

$payload = false;
if ($token) {
  if (function_exists('validarToken')) {
    $payload = validarToken($token);
  } else {
    // fallback: no token validation
    $payload = true;
  }
}
if (!$payload) {
  http_response_code(401);
  echo json_encode(['error'=>'invalid token']);
  exit;
}

$chamado_id = isset($_GET['chamado_id']) ? (int)$_GET['chamado_id'] : null;
if (!$chamado_id) {
  http_response_code(400);
  echo json_encode(['error'=>'missing chamado_id']);
  exit;
}

$stmt = $pdo->prepare("SELECT id, chamado_id, usuario_id, mensagem, criado_em FROM mensagens WHERE chamado_id = :cid ORDER BY criado_em ASC, id ASC");
$stmt->execute([':cid' => $chamado_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>true,'mensagens'=>$rows]);