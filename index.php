<?php
// index.php
header("Content-Type: application/json; charset=UTF-8");

// Lista de origens confiáveis
$allowed_origins = [
    "http://localhost:5173",         // ambiente de desenvolvimento
    "https://sistema-de-chamados-tau.vercel.app",     // produção
];

// Pega a origem da requisição
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Verifica se a origem está na lista
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true"); // importante para cookies ou tokens via headers
}

// Cabeçalhos CORS padrão
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Responde às requisições OPTIONS (pré-flight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

// Inicia roteamento
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove prefixo 
$path = preg_replace('#^/api-sdc/#', '', $path);
$path = trim($path, '/');

$uri = explode('/', $path);

// Roteamento básico
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
