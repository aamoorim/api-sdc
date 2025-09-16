<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

if ($uri[0] === "chamados") {
    $payload = autenticar();  // Supõe que retorna objeto com ->sub (usuario_id), ->role, etc

    // CLIENTE - Ver apenas seus chamados
    if ($payload->role === "cliente" && $method === "GET") {
        // você pode ignorar clienteId que venha por query, sempre usar o usuário do token
        $usuario_id = $payload->sub;
        $stmt = $pdo->prepare("
            SELECT c.id, c.titulo, c.descricao, c.status, c.data_criacao,
                   cl.id AS cliente_id
            FROM chamados c
            JOIN clientes cl ON c.cliente_id = cl.id
            WHERE cl.usuario_id = :usuario_id
            ORDER BY c.data_criacao DESC
        ");
        $stmt->execute(['usuario_id' => $usuario_id]);
        $chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($chamados);
        exit;
    }

    // CLIENTE - Criar novo chamado
    if ($payload->role === "cliente" && $method === "POST") {
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['titulo']) || empty($input['descricao'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Título e descrição são obrigatórios"]);
            exit;
        }

        // Buscar o cliente_id baseado no usuario_id do token
        $stmt = $pdo->prepare("SELECT id FROM clientes WHERE usuario_id = :usuario_id");
        $stmt->execute(['usuario_id' => $payload->sub]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cliente) {
            http_response_code(400);
            echo json_encode(["erro" => "Cliente não encontrado"]);
            exit;
        }

        // Aqui definir status inicial que encaixe com frontend
        $status_inicial = 'espera';  // ou 'aberto', se frontend tratar como espera

        $stmt = $pdo->prepare("
            INSERT INTO chamados (titulo, descricao, cliente_id, status, data_criacao)
            VALUES (:titulo, :descricao, :cliente_id, :status, NOW())
        ");
        $stmt->execute([
            'titulo' => $input['titulo'],
            'descricao' => $input['descricao'],
            'cliente_id' => $cliente['id'],
            'status' => $status_inicial
        ]);

        $novo_id = $pdo->lastInsertId();

        // Buscar o chamado recém criado pra retornar objeto completo
        $stmt2 = $pdo->prepare("
            SELECT c.id, c.titulo, c.descricao, c.status, c.data_criacao,
                   cl.id AS cliente_id
            FROM chamados c
            JOIN clientes cl ON c.cliente_id = cl.id
            WHERE c.id = :id
        ");
        $stmt2->execute(['id' => $novo_id]);
        $chamado_novo = $stmt2->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode($chamado_novo);
        exit;
    }

    // Continua para tecnico, admin, etc...
    // ... suas outras rotas ...

    // Se rota não for reconhecida
    http_response_code(404);
    echo json_encode(["erro" => "Ação não encontrada"]);
    exit;
}
