<?php
header("Content-Type: application/json; charset=UTF-8");

$method = $_SERVER['REQUEST_METHOD'];
$uri = explode("/", trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), "/"));

// Ex: /auth/login -> ["auth", "login"]

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
