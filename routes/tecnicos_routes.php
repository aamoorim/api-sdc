<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

// Função para validar senha
function validarSenha($senha) {
    if (strlen($senha) < 8) {
        return "A senha deve ter pelo menos 8 caracteres.";
    }
    if (preg_match('/\s/', $senha)) {
        return "A senha não pode conter espaços em branco.";
    }
    if (!preg_match('/[A-Z]/', $senha)) {
        return "A senha deve conter pelo menos uma letra maiúscula.";
    }
    if (!preg_match('/[a-z]/', $senha)) {
        return "A senha deve conter pelo menos uma letra minúscula.";
    }
    if (!preg_match('/[0-9]/', $senha)) {
        return "A senha deve conter pelo menos um número.";
    }
    if (!preg_match('/[\W_]/', $senha)) {
        return "A senha deve conter pelo menos um caractere especial.";
    }
    return true;
}

// Função para gerar senha aleatória forte
function gerarSenhaAleatoria($tamanho = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
    return substr(str_shuffle(str_repeat($chars, ceil($tamanho/strlen($chars)))), 0, $tamanho);
}

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
        
        if (empty($input['nome']) || empty($input['email']) || empty($input['cargo'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Nome, email e cargo são obrigatórios"]);
            exit;
        }
        
        // senha: se fornecida valida, senão gera aleatória
        if (!empty($input['senha'])) {
            $validacao = validarSenha($input['senha']);
            if ($validacao !== true) {
                http_response_code(400);
                echo json_encode(["erro" => $validacao]);
                exit;
            }
            $senha = $input['senha'];
        } else {
            $senha = gerarSenhaAleatoria(12);
        }

        $hash = password_hash($senha, PASSWORD_BCRYPT);
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, tipo_usuario) VALUES (:nome, :email, :senha, 'tecnico')");
            $stmt->execute([
                "nome" => $input['nome'],
                "email" => $input['email'],
                "senha" => $hash
            ]);
            $usuario_id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO tecnicos (usuario_id, cargo) VALUES (:usuario_id, :cargo)");
            $stmt->execute([
                "usuario_id" => $usuario_id,
                "cargo" => $input['cargo']
            ]);
            $tecnico_id = $pdo->lastInsertId();
            
            $pdo->commit();
            
            echo json_encode([
                "status" => "Técnico criado com sucesso", 
                "id" => $tecnico_id,
                "usuario_id" => $usuario_id,
                "senha_inicial" => $senha
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

    if (empty($input['nome']) || empty($input['email']) || empty($input['cargo'])) {
        http_response_code(400);
        echo json_encode(["erro" => "Nome, email e cargo são obrigatórios"]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT usuario_id FROM tecnicos WHERE id = :id");
        $stmt->execute(['id' => $tecnico_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            http_response_code(404);
            echo json_encode(["erro" => "Técnico não encontrado"]);
            exit;
        }

        $usuario_id = $result['usuario_id'];

        // Atualiza nome/email
        $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, email = :email WHERE id = :id");
        $stmt->execute([
            "nome" => $input['nome'],
            "email" => $input['email'],
            "id" => $usuario_id
        ]);

        // Atualiza cargo
        $stmt = $pdo->prepare("UPDATE tecnicos SET cargo = :cargo WHERE id = :id");
        $stmt->execute([
            "cargo" => $input['cargo'],
            "id" => $tecnico_id
        ]);

        // Atualiza senha somente se enviada e não vazia
        if (isset($input['senha']) && !empty(trim($input['senha']))) {
            $validacao = validarSenha($input['senha']);
            if ($validacao !== true) {
                http_response_code(400);
                echo json_encode(["erro" => $validacao]);
                exit;
            }
            $hash = password_hash($input['senha'], PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
            $stmt->execute(['senha' => $hash, 'id' => $usuario_id]);

            $pdo->commit();

            echo json_encode([
                "status" => "Técnico atualizado com sucesso",
                "senha_atualizada" => true
            ]);
            exit;
        }

        $pdo->commit();

        echo json_encode([
            "status" => "Técnico atualizado com sucesso",
            "senha_atualizada" => false
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

    
    // DELETE - Excluir técnico
    if ($method === "DELETE" && isset($uri[1])) {
        $tecnico_id = $uri[1];
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM chamados WHERE tecnico_id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['total'] > 0) {
                http_response_code(400);
                echo json_encode(["erro" => "Não é possível excluir técnico que possui chamados atribuídos"]);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT usuario_id FROM tecnicos WHERE id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                http_response_code(404);
                echo json_encode(["erro" => "Técnico não encontrado"]);
                exit;
            }
            
            $usuario_id = $result['usuario_id'];
            
            $stmt = $pdo->prepare("DELETE FROM tecnicos WHERE id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            
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
    
    http_response_code(404);
    echo json_encode(["erro" => "Ação não encontrada"]);
    exit;
}