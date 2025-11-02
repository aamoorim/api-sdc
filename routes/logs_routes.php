<?php 

// ROTA: GET /logs — Somente admin pode acessar
$payload = autenticar();

if ($path === '/logs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Verifica autenticação

    // Verifica se é admin
    if ($payload->role === 'admin') {
        http_response_code(403);
        echo json_encode(["error" => "Acesso negado: apenas administradores"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                l.id,
                l.id_autor,
                u.nome AS usuario_nome,
                l.acao,
                l.descricao,
                l.valor_antigo,
                l.valor_novo,
                l.data_hora
            FROM logs_auditoria l
            LEFT JOIN usuarios u ON l.id_autor = u.id
            ORDER BY l.data_hora DESC
        ");

        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($logs);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao buscar logs", "detalhes" => $e->getMessage()]);
    }

    exit;
}


?>;