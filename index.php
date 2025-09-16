<?php
// Cabeçalhos para CORS
header("Content-Type: application/json; charset=UTF-8");

// Permitir qualquer origem 
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
} else {
    header("Access-Control-Allow-Origin: *");
}

// Métodos permitidos e cabeçalhos personalizados
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

// Responder a requisições de OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- Roteamento ---

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove prefixo se estiver usando RewriteBase
$path = preg_replace('#^/api-sdc/#', '', $path);
$path = trim($path, '/');

$uri = explode('/', $path);

switch ($uri[0] ?? '') {
    case 'auth':
        require __DIR__ . "/routes/auth.php";
        break;
    case 'chamados':
        require __DIR__ . "/routes/chamados_routes.php";
        break;
    case 'clientes':
        require __DIR__ . "/routes/clientes_routes.php";
        break;
    case 'tecnicos':
        require __DIR__ . "/routes/tecnicos_routes.php";
        break;
    default:
        http_response_code(404);
        echo json_encode(["erro" => "Rota não encontrada"]);
        break;
}
