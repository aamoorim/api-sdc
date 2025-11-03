<?php

require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/db.php';

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
        // Aqui fazemos o JOIN com a tabela de usuários
        $stmt = $pdo->prepare("
            SELECT 
                l.id,
                l.id_autor,
                u.nome AS autor_nome, -- 👈 puxando o nome do usuário
                l.acao,
                l.descricao,
                l.valor_antigo,
                l.valor_novo,
                l.data_hora
            FROM logs_auditoria l
            LEFT JOIN usuarios u ON u.id = l.id_autor
            ORDER BY l.data_hora DESC
        ");

        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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