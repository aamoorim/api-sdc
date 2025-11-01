<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../utils/log_auditoria.php';

if ($uri[0] === "tecnicos") {
    $payload = autenticar();
    
    if ($payload->role !== "admin") {
        http_response_code(403);
        echo json_encode(["erro" => "Acesso negado"]);
        exit;
    }
    
    // GET - Listar todos os técnicos
    if ($method === "GET") {
        $stmt = $pdo->query("
            SELECT t.id, t.cargo, u.nome, u.email, u.id as usuario_id
            FROM tecnicos t 
            JOIN usuarios u ON t.usuario_id = u.id 
            ORDER BY u.nome
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    // POST - Criar novo técnico
   if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    
    // Validação básica
    if (empty($input['nome']) || empty($input['email']) || empty($input['cargo'])) {
        http_response_code(400);
        echo json_encode(["erro" => "Nome, email e cargo são obrigatórios"]);
        exit;
    }
    
    // Usar senha fornecida ou gerar padrão baseada no nome
    if (!empty($input['senha'])) {
        $senha = $input['senha'];
    } else {
        $primeiro_nome = strtolower(explode(' ', $input['nome'])[0]);
        $senha = $primeiro_nome . rand(1000, 9999);
    }
    $hash = password_hash($senha, PASSWORD_BCRYPT);
    
    try {
        $pdo->beginTransaction();
        
        // Inserir na tabela usuarios
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nome, email, senha, tipo_usuario) 
            VALUES (:nome, :email, :senha, 'tecnico')
        ");
        $stmt->execute([
            "nome" => $input['nome'],
            "email" => $input['email'],
            "senha" => $hash
        ]);
        $usuario_id = $pdo->lastInsertId();
        
        // Inserir na tabela tecnicos
        $stmt = $pdo->prepare("
            INSERT INTO tecnicos (usuario_id, cargo) 
            VALUES (:usuario_id, :cargo)
        ");
        $stmt->execute([
            "usuario_id" => $usuario_id,
            "cargo" => $input['cargo']
        ]);
        $tecnico_id = $pdo->lastInsertId();
        
        $pdo->commit();

        // ✅ Registrar log de auditoria
        registrarLogAuditoria(
            $pdo,
            $payload->sub, // ID do usuário autenticado que fez a ação
            'criar tecnico',
            "Criou o técnico",
            null,
            [
                'usuario' => [
                    'nome' => $input['nome'],
                    'email' => $input['email'],
                    'tipo_usuario' => 'tecnico'
                ],
                'tecnico' => [
                    'cargo' => $input['cargo']
                ]
            ]
        );
        
        echo json_encode([
            "status" => "Técnico criado com sucesso", 
            "id" => $tecnico_id,
            "usuario_id" => $usuario_id,
            "senha_padrao" => $senha
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        
        if ($e->getCode() == 23000) {
            http_response_code(400);
            echo json_encode(["erro" => "Email já existe"]);
        } else {
            http_response_code(500);
            echo json_encode(["erro" => "Erro interno do servidor: " . $e->getMessage()]);
        }
    }
    exit;
}
    
    // PUT - Editar técnico
   if ($method === "PUT" && isset($uri[1])) {
    $tecnico_id = $uri[1];
    $input = json_decode(file_get_contents("php://input"), true);
    
    // Validação básica
    if (empty($input['nome']) || empty($input['email']) || empty($input['cargo'])) {
        http_response_code(400);
        echo json_encode(["erro" => "Nome, email e cargo são obrigatórios"]);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Buscar dados antigos do técnico antes de atualizar (para log)
        $stmt = $pdo->prepare("
            SELECT u.id as usuario_id, u.nome, u.email, t.cargo
            FROM tecnicos t
            JOIN usuarios u ON u.id = t.usuario_id
            WHERE t.id = :id
        ");
        $stmt->execute(['id' => $tecnico_id]);
        $valor_antigo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$valor_antigo) {
            http_response_code(404);
            echo json_encode(["erro" => "Técnico não encontrado"]);
            exit;
        }
        
        $usuario_id = $valor_antigo['usuario_id'];
        
        // Atualizar dados na tabela usuarios
        $stmt = $pdo->prepare("
            UPDATE usuarios SET nome = :nome, email = :email WHERE id = :id
        ");
        $stmt->execute([
            "nome" => $input['nome'],
            "email" => $input['email'],
            "id" => $usuario_id
        ]);
        
        // Atualizar dados na tabela tecnicos
        $stmt = $pdo->prepare("
            UPDATE tecnicos SET cargo = :cargo WHERE id = :id
        ");
        $stmt->execute([
            "cargo" => $input['cargo'],
            "id" => $tecnico_id
        ]);
        
        // Se foi fornecida nova senha, atualizar
        if (!empty($input['senha'])) {
            $hash = password_hash($input['senha'], PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
            $stmt->execute(['senha' => $hash, 'id' => $usuario_id]);
        }
        
        $pdo->commit();

        // ✅ Registrar log de auditoria
        registrarLogAuditoria(
            $pdo,
            $payload->sub, // ou $payload->id dependendo do seu JWT
            'editar',
            "Editou o técnico",
            $valor_antigo,
            [
                'nome' => $input['nome'],
                'email' => $input['email'],
                'cargo' => $input['cargo']
            ]
        );
        
        echo json_encode(["status" => "Técnico atualizado com sucesso"]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        
        if ($e->getCode() == 23000) {
            http_response_code(400);
            echo json_encode(["erro" => "Email já existe"]);
        } else {
            http_response_code(500);
            echo json_encode(["erro" => "Erro interno do servidor: " . $e->getMessage()]);
        }
    }
    exit;
}
    
    // DELETE - Excluir técnico
    if ($method === "DELETE" && isset($uri[1])) {
        $tecnico_id = $uri[1];
        
        try {
            $pdo->beginTransaction();
            
            // Verificar se o técnico tem chamados atribuídos
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM chamados WHERE tecnico_id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['total'] > 0) {
                http_response_code(400);
                echo json_encode(["erro" => "Não é possível excluir técnico que possui chamados atribuídos"]);
                exit;
            }
            
            // Buscar o usuario_id do técnico
            $stmt = $pdo->prepare("SELECT usuario_id FROM tecnicos WHERE id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                http_response_code(404);
                echo json_encode(["erro" => "Técnico não encontrado"]);
                exit;
            }
            
            $usuario_id = $result['usuario_id'];
            
            // Excluir da tabela tecnicos primeiro
            $stmt = $pdo->prepare("DELETE FROM tecnicos WHERE id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            
            // Depois excluir da tabela usuarios
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->execute(['id' => $usuario_id]);
            
            $pdo->commit();
            
            echo json_encode(["status" => "Técnico excluído com sucesso"]);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(["erro" => "Erro interno do servidor: " . $e->getMessage()]);
        }
        exit;
    }
    
    // Se chegou até aqui, rota não encontrada
    http_response_code(404);
    echo json_encode(["erro" => "Ação não encontrada"]);
    exit;
}