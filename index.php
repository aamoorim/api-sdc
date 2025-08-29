<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];

// Com RewriteBase /api-sdc/, a URI já vem sem o prefixo
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove /api-sdc/ se estiver presente
$path = preg_replace('#^/api-sdc/#', '', $path);
$path = trim($path, '/');

$uri = explode('/', $path);

// DEBUG (remova depois que funcionar)
error_log("DEBUG - REQUEST_URI: " . $_SERVER['REQUEST_URI']);
error_log("DEBUG - PATH: " . $path);
error_log("DEBUG - URI array: " . print_r($uri, true));

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
        echo json_encode([
            "erro" => "Rota não encontrada",
            "debug" => [
                "uri_recebida" => $uri,
                "uri0" => ($uri[0] ?? 'vazio'),
                "path_processado" => $path,
                "request_uri_original" => $_SERVER['REQUEST_URI']
            ]
        ]);
        break;
}
?>