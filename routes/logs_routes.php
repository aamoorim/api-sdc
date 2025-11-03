<?php

require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/db.php';

// Autentica usuário pelo JWT
$payload = autenticar();
$method = $_SERVER['REQUEST_METHOD'];

// GET - Buscar logs (somente admin)
if ($method === "GET") {

    // Verifica se é admin
    if (!isset($payload->role) || $payload->role !== "admin") {
        http_response_code(403);
        echo json_encode(["error" => "Acesso negado: apenas administradores"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                id_autor,
                acao,
                descricao,
                valor_antigo,
                valor_novo,
                data_hora
            FROM logs_auditoria
            ORDER BY data_hora DESC
        ");
        
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($logs);
        exit;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Erro ao buscar logs",
            "detalhes" => $e->getMessage()
        ]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["error" => "Método não permitido"]);
exit;

?>
