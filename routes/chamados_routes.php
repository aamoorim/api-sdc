<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

if ($uri[0] === "chamados") {
    $payload = autenticar();
    
    // CLIENTE - Ver apenas seus chamados
    if ($payload->role === "cliente" && $method === "GET") {
        $stmt = $pdo->prepare("
            SELECT c.* 
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id 
            WHERE cl.usuario_id = :usuario_id 
            ORDER BY c.data_criacao DESC
        ");
        $stmt->execute(['usuario_id' => $payload->sub]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
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
    
    $stmt = $pdo->prepare("INSERT INTO chamados (titulo, descricao, cliente_id, status, data_criacao) VALUES (:titulo, :descricao, :cliente_id, 'aberto', NOW())");
    $stmt->execute([
        'titulo' => $input['titulo'],
        'descricao' => $input['descricao'],
        'cliente_id' => $cliente['id']  // <-- Usar o ID da tabela clientes
    ]);
    echo json_encode(["status" => "Chamado criado com sucesso", "id" => $pdo->lastInsertId()]);
    exit;
}
    
    // TÉCNICO - Ver chamados atribuídos a ele
    if ($payload->role === "tecnico" && $method === "GET" && !isset($uri[1])) {
        // Buscar o tecnico_id a partir do usuario_id
        $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
        $stmtTecnico->execute(['usuario_id' => $payload->sub]);
        $tecnico = $stmtTecnico->fetch(PDO::FETCH_ASSOC);

        if (!$tecnico) {
            http_response_code(403);
            echo json_encode(["erro" => "Usuário não é um técnico válido"]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT c.*, cl.empresa, u.nome as cliente_nome 
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id 
            JOIN usuarios u ON cl.usuario_id = u.id 
            WHERE c.tecnico_id = :tecnico_id 
            ORDER BY c.data_criacao DESC
        ");
        $stmt->execute(['tecnico_id' => $tecnico['id']]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    
    // TÉCNICO - Ver chamados em aberto (para se atribuir)
    if ($payload->role === "tecnico" && $method === "GET" && ($uri[1] ?? '') === "abertos") {
        $stmt = $pdo->query("
            SELECT c.*, cl.empresa, cl.setor, u.nome as cliente_nome 
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id 
            JOIN usuarios u ON cl.usuario_id = u.id 
            WHERE c.status = 'aberto' AND c.tecnico_id IS NULL 
            ORDER BY c.data_criacao DESC
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    // TÉCNICO - Se atribuir a um chamado
    if ($payload->role === "tecnico" && $method === "PUT" && ($uri[2] ?? '') === "atribuir") {
    $chamado_id = $uri[1];
    
    // Buscar o tecnico_id baseado no usuario_id do JWT
    $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
    $stmtTecnico->execute(['usuario_id' => $payload->sub]);
    $tecnico = $stmtTecnico->fetch();
    
    if (!$tecnico) {
        http_response_code(403);
        echo json_encode(["erro" => "Usuário não é um técnico válido"]);
        exit;
    }
    
    $tecnico_id = $tecnico['id']; // Este é o ID correto para usar
    
    // Verificar se o chamado está disponível
    $stmtChamado = $pdo->prepare("SELECT status FROM chamados WHERE id = :id");
    $stmtChamado->execute(['id' => $chamado_id]);
    $chamado = $stmtChamado->fetch();
    
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
    
    // Fazer a atribuição com o tecnico_id correto
    $stmt = $pdo->prepare("UPDATE chamados SET tecnico_id = :tecnico_id, status = 'em_andamento' WHERE id = :id");
    $result = $stmt->execute(['tecnico_id' => $tecnico_id, 'id' => $chamado_id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(["status" => "Chamado atribuído com sucesso"]);
    } else {
        http_response_code(400);
        echo json_encode(["erro" => "Falha ao atribuir chamado"]);
    }
    exit;
}

    // TÉCNICO - Encerrar chamado
    if ($payload->role === "tecnico" && $method === "PUT" && ($uri[2] ?? '') === "encerrar") {
    $chamado_id = $uri[1];
    $input = json_decode(file_get_contents("php://input"), true);
    
    // Buscar o tecnico_id baseado no usuario_id do token
    $stmt = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
    $stmt->execute(['usuario_id' => $payload->sub]);
    $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tecnico) {
        http_response_code(400);
        echo json_encode(["erro" => "Técnico não encontrado"]);
        exit;
    }
    
    // Verificar se o chamado existe e pertence ao técnico
    $stmt = $pdo->prepare("SELECT * FROM chamados WHERE id = :id AND tecnico_id = :tecnico_id");
    $stmt->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);
    
    if ($stmt->rowCount() == 0) {
        http_response_code(400);
        echo json_encode(["erro" => "Chamado não encontrado ou não é seu"]);
        exit;
    }
    
    // Atualizar status para encerrado
    $stmt = $pdo->prepare("UPDATE chamados SET status = 'encerrado' WHERE id = :id AND tecnico_id = :tecnico_id");
    $stmt->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);
    
    echo json_encode(["status" => "Chamado encerrado com sucesso"]);
    exit;
}
    
    // ADMIN - Ver todos os chamados
    if ($payload->role === "admin" && $method === "GET") {
        $stmt = $pdo->query("
            SELECT c.*, 
                   cl.empresa, cl.setor, 
                   uc.nome as cliente_nome,
                   ut.nome as tecnico_nome,
                   t.cargo as tecnico_cargo
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id 
            JOIN usuarios uc ON cl.usuario_id = uc.id 
            LEFT JOIN tecnicos t ON c.tecnico_id = t.id 
            LEFT JOIN usuarios ut ON t.usuario_id = ut.id 
            ORDER BY c.data_criacao DESC
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    // ADMIN - Deletar chamado (independente do status)
    if ($payload->role === "admin" && $method === "DELETE" && isset($uri[1])) {
        $chamado_id = $uri[1];
        
        try {
            // Verificar se o chamado existe
            $stmt = $pdo->prepare("SELECT id FROM chamados WHERE id = :id");
            $stmt->execute(['id' => $chamado_id]);
            
            if ($stmt->rowCount() == 0) {
                http_response_code(404);
                echo json_encode(["erro" => "Chamado não encontrado"]);
                exit;
            }

            // Deletar o chamado (independente do status)
            $stmt = $pdo->prepare("DELETE FROM chamados WHERE id = :id");
            $stmt->execute(['id' => $chamado_id]);
            
            echo json_encode(["status" => "Chamado deletado com sucesso"]);
            
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["erro" => "Erro interno do servidor: " . $e->getMessage()]);
        }
        exit;
    }
    
    // ADMIN - Editar chamado (independente do status)
    if ($payload->role === "admin" && $method === "PUT" && isset($uri[1])) {
        $chamado_id = $uri[1];
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['titulo']) || empty($input['descricao'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Informe 'titulo' e 'descricao'."]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE chamados 
                SET titulo = :titulo, descricao = :descricao 
                WHERE id = :id");
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
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["erro" => "Erro interno do servidor"]);
        }
        exit;
    }

    // Se chegou até aqui, não encontrou a rota
    http_response_code(404);
    echo json_encode(["erro" => "Ação não encontrada"]);
    exit;
}