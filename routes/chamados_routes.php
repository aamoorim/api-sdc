<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

if ($uri[0] === "chamados") {
    // autenticar retorna payload com sub (usuario_id), role, etc
    $payload = autenticar();  
    // método HTTP (string “GET”, “POST”, “PUT”, “DELETE” etc)
    // assumindo que $method e $uri já estão definidos antes
    // E que $pdo é sua instância PDO configurada

    //// --------------------
    //// CLIENTE
    //// --------------------

    // Cliente: ver apenas seus chamados
    if ($payload->role === "cliente" && $method === "GET") {
        $usuario_id = $payload->sub;
        $stmt = $pdo->prepare("
            SELECT c.id,
                   c.titulo,
                   c.descricao,
                   c.status,
                   c.data_criacao,
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

    // Cliente: criar novo chamado
    if ($payload->role === "cliente" && $method === "POST") {
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['titulo']) || empty($input['descricao'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Título e descrição são obrigatórios"]);
            exit;
        }

        // Obter cliente_id via usuario_id do token
        $stmt = $pdo->prepare("SELECT id FROM clientes WHERE usuario_id = :usuario_id");
        $stmt->execute(['usuario_id' => $payload->sub]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cliente) {
            http_response_code(400);
            echo json_encode(["erro" => "Cliente não encontrado"]);
            exit;
        }

        // definir status inicial compatível com frontend
        $status_inicial = 'espera';

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

        // Buscar o chamado recém criado para retornar com dados completos
        $stmt2 = $pdo->prepare("
            SELECT c.id,
                   c.titulo,
                   c.descricao,
                   c.status,
                   c.data_criacao,
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

    //// --------------------
    //// TÉCNICO
    //// --------------------

    // Técnico: ver chamados atribuídos a ele
    if ($payload->role === "tecnico" && $method === "GET" && !isset($uri[1])) {
        $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
        $stmtTecnico->execute(['usuario_id' => $payload->sub]);
        $tecnico = $stmtTecnico->fetch(PDO::FETCH_ASSOC);

        if (!$tecnico) {
            http_response_code(403);
            echo json_encode(["erro" => "Usuário não é um técnico válido"]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT c.id,
                   c.titulo,
                   c.descricao,
                   c.status,
                   c.data_criacao,
                   cl.empresa,
                   u.nome AS cliente_nome
            FROM chamados c
            JOIN clientes cl ON c.cliente_id = cl.id
            JOIN usuarios u ON cl.usuario_id = u.id
            WHERE c.tecnico_id = :tecnico_id
            ORDER BY c.data_criacao DESC
        ");
        $stmt->execute(['tecnico_id' => $tecnico['id']]);
        $chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($chamados);
        exit;
    }

    // Técnico: ver chamados em aberto (para se atribuir)
    if ($payload->role === "tecnico" && $method === "GET" && ($uri[1] ?? '') === "abertos") {
        $stmt = $pdo->query("
            SELECT c.id,
                   c.titulo,
                   c.descricao,
                   c.status,
                   c.data_criacao,
                   cl.empresa,
                   cl.setor,
                   u.nome AS cliente_nome
            FROM chamados c
            JOIN clientes cl ON c.cliente_id = cl.id
            JOIN usuarios u ON cl.usuario_id = u.id
            WHERE c.status = 'aberto' AND c.tecnico_id IS NULL
            ORDER BY c.data_criacao DESC
        ");
        $chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($chamados);
        exit;
    }

    // Técnico: atribuir-se a um chamado
    if ($payload->role === "tecnico" && $method === "PUT" && ($uri[2] ?? '') === "atribuir") {
        $chamado_id = $uri[1];

        $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
        $stmtTecnico->execute(['usuario_id' => $payload->sub]);
        $tecnico = $stmtTecnico->fetch(PDO::FETCH_ASSOC);

        if (!$tecnico) {
            http_response_code(403);
            echo json_encode(["erro" => "Usuário não é um técnico válido"]);
            exit;
        }

        // Verificar existência do chamado
        $stmtChamado = $pdo->prepare("SELECT status FROM chamados WHERE id = :id");
        $stmtChamado->execute(['id' => $chamado_id]);
        $chamado = $stmtChamado->fetch(PDO::FETCH_ASSOC);

        if (!$chamado) {
            http_response_code(404);
            echo json_encode(["erro" => "Chamado não encontrado"]);
            exit;
        }
        if ($chamado['status'] !== 'aberto') {
            http_response_code(400);
            echo json_encode(["erro" => "Chamado não está disponível para atribuição"]);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE chamados
            SET tecnico_id = :tecnico_id, status = 'andamento'
            WHERE id = :id
        ");
        $stmt->execute([
            'tecnico_id' => $tecnico['id'],
            'id' => $chamado_id
        ]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(["status" => "Chamado atribuído com sucesso"]);
        } else {
            http_response_code(400);
            echo json_encode(["erro" => "Falha ao atribuir chamado"]);
        }
        exit;
    }

    // Técnico: encerrar chamado
    if ($payload->role === "tecnico" && $method === "PUT" && ($uri[2] ?? '') === "encerrar") {
        $chamado_id = $uri[1];

        $input = json_decode(file_get_contents("php://input"), true);

        // Verificar técnico válido
        $stmt = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
        $stmt->execute(['usuario_id' => $payload->sub]);
        $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tecnico) {
            http_response_code(400);
            echo json_encode(["erro" => "Técnico não encontrado"]);
            exit;
        }

        // Verificar se o chamado existe e pertence ao técnico
        $stmtChamado = $pdo->prepare("SELECT * FROM chamados WHERE id = :id AND tecnico_id = :tecnico_id");
        $stmtChamado->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);

        if ($stmtChamado->rowCount() == 0) {
            http_response_code(400);
            echo json_encode(["erro" => "Chamado não encontrado ou não é seu"]);
            exit;
        }

        // Atualizar status para encerrado
        $stmt = $pdo->prepare("UPDATE chamados SET status = 'finalizado' WHERE id = :id AND tecnico_id = :tecnico_id");
        $stmt->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(["status" => "Chamado encerrado com sucesso"]);
        } else {
            http_response_code(400);
            echo json_encode(["erro" => "Falha ao encerrar chamado"]);
        }
        exit;
    }

    //// --------------------
    //// ADMIN
    //// --------------------

    // Admin: ver todos os chamados
    if ($payload->role === "admin" && $method === "GET") {
        $stmt = $pdo->query("
            SELECT c.id,
                   c.titulo,
                   c.descricao,
                   c.status,
                   c.data_criacao,
                   cl.empresa,
                   cl.setor,
                   uc.nome AS cliente_nome,
                   ut.nome AS tecnico_nome
            FROM chamados c
            JOIN clientes cl ON c.cliente_id = cl.id
            JOIN usuarios uc ON cl.usuario_id = uc.id
            LEFT JOIN tecnicos t ON c.tecnico_id = t.id
            LEFT JOIN usuarios ut ON t.usuario_id = ut.id
            ORDER BY c.data_criacao DESC
        ");
        $chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($chamados);
        exit;
    }

    // Admin: deletar chamado
    if ($payload->role === "admin" && $method === "DELETE" && isset($uri[1])) {
        $chamado_id = $uri[1];

        $stmt = $pdo->prepare("SELECT id FROM chamados WHERE id = :id");
        $stmt->execute(['id' => $chamado_id]);
        if ($stmt->rowCount() == 0) {
            http_response_code(404);
            echo json_encode(["erro" => "Chamado não encontrado"]);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM chamados WHERE id = :id");
        $stmt->execute(['id' => $chamado_id]);
        echo json_encode(["status" => "Chamado deletado com sucesso"]);
        exit;
    }

    // Admin: editar chamado (titulo e descrição)
    if ($payload->role === "admin" && $method === "PUT" && isset($uri[1])) {
        $chamado_id = $uri[1];
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['titulo']) || empty($input['descricao'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Informe 'titulo' e 'descricao'."]);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE chamados
            SET titulo = :titulo, descricao = :descricao
            WHERE id = :id
        ");
        $stmt->execute([
            'titulo' => $input['titulo'],
            'descricao' => $input['descricao'],
            'id' => $chamado_id
        ]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["erro" => "Chamado não encontrado"]);
        } else {
            echo json_encode(["status" => "Chamado atualizado com sucesso"]);
        }
        exit;
    }

    //// --------------------
    //// Se nenhuma rota casou
    //// --------------------
    http_response_code(404);
    echo json_encode(["erro" => "Ação não encontrada"]);
    exit;
}
