<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

// Rota para /tecnicos
if ($uri[0] === "tecnicos") {
    $payload = autenticar(); // Verifica e decodifica o token JWT

    // Apenas admins podem acessar essa rota
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

        // Verifica campos obrigatórios
        if (empty($input['nome']) || empty($input['email']) || empty($input['cargo'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Nome, email e cargo são obrigatórios"]);
            exit;
        }

        // Usa senha fornecida ou gera padrão
        $senha = !empty($input['senha']) ? $input['senha'] : strtolower(explode(' ', $input['nome'])[0]) . rand(1000, 9999);
        $hash = password_hash($senha, PASSWORD_BCRYPT);

        try {
            $pdo->beginTransaction();

            // Insere na tabela usuarios
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, tipo_usuario) VALUES (:nome, :email, :senha, 'tecnico')");
            $stmt->execute([
                "nome" => $input['nome'],
                "email" => $input['email'],
                "senha" => $hash
            ]);
            $usuario_id = $pdo->lastInsertId();

            // Insere na tabela tecnicos
            $stmt = $pdo->prepare("INSERT INTO tecnicos (usuario_id, cargo) VALUES (:usuario_id, :cargo)");
            $stmt->execute([
                "usuario_id" => $usuario_id,
                "cargo" => $input['cargo']
            ]);
            $tecnico_id = $pdo->lastInsertId();

            $pdo->commit();

            // Retorna dados criados (o frontend pode usar sem recarregar a página)
            echo json_encode([
                "status" => "Técnico criado com sucesso",
                "id" => $tecnico_id,
                "usuario_id" => $usuario_id,
                "nome" => $input['nome'],
                "email" => $input['email'],
                "cargo" => $input['cargo'],
                "senha_padrao" => $senha
            ]);

        } catch (PDOException $e) {
            $pdo->rollBack();

            if ($e->getCode() == 23000) {
                http_response_code(400);
                echo json_encode(["erro" => "Email já existe"]);
            } else {
                http_response_code(500);
                echo json_encode(["erro" => "Erro interno: " . $e->getMessage()]);
            }
        }
        exit;
    }

    // PUT - Atualizar técnico existente
    if ($method === "PUT" && isset($uri[1])) {
        $tecnico_id = $uri[1];
        $input = json_decode(file_get_contents("php://input"), true);

        // Verifica campos obrigatórios
        if (empty($input['nome']) || empty($input['email']) || empty($input['cargo'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Nome, email e cargo são obrigatórios"]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Busca o usuário relacionado ao técnico
            $stmt = $pdo->prepare("SELECT usuario_id FROM tecnicos WHERE id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                http_response_code(404);
                echo json_encode(["erro" => "Técnico não encontrado"]);
                exit;
            }

            $usuario_id = $result['usuario_id'];

            // Atualiza dados do usuário
            $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, email = :email WHERE id = :id");
            $stmt->execute([
                "nome" => $input['nome'],
                "email" => $input['email'],
                "id" => $usuario_id
            ]);

            // Atualiza dados do técnico
            $stmt = $pdo->prepare("UPDATE tecnicos SET cargo = :cargo WHERE id = :id");
            $stmt->execute([
                "cargo" => $input['cargo'],
                "id" => $tecnico_id
            ]);

            // Se houver nova senha, atualiza também
            if (!empty($input['senha'])) {
                $hash = password_hash($input['senha'], PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
                $stmt->execute(['senha' => $hash, 'id' => $usuario_id]);
            }

            $pdo->commit();

            // Retorna dados atualizados (o frontend pode usar sem dar F5)
            echo json_encode([
                "status" => "Técnico atualizado com sucesso",
                "id" => $tecnico_id,
                "usuario_id" => $usuario_id,
                "nome" => $input['nome'],
                "email" => $input['email'],
                "cargo" => $input['cargo']
            ]);

        } catch (PDOException $e) {
            $pdo->rollBack();

            if ($e->getCode() == 23000) {
                http_response_code(400);
                echo json_encode(["erro" => "Email já existe"]);
            } else {
                http_response_code(500);
                echo json_encode(["erro" => "Erro interno: " . $e->getMessage()]);
            }
        }
        exit;
    }

    // DELETE - Excluir técnico
    if ($method === "DELETE" && isset($uri[1])) {
        $tecnico_id = $uri[1];

        try {
            $pdo->beginTransaction();

            // Verifica se o técnico tem chamados atribuídos
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM chamados WHERE tecnico_id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['total'] > 0) {
                http_response_code(400);
                echo json_encode(["erro" => "Técnico possui chamados atribuídos"]);
                exit;
            }

            // Busca o usuário relacionado ao técnico
            $stmt = $pdo->prepare("SELECT usuario_id FROM tecnicos WHERE id = :id");
            $stmt->execute(['id' => $tecnico_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                http_response_code(404);
                echo json_encode(["erro" => "Técnico não encontrado"]);
                exit;
            }

            $usuario_id = $result['usuario_id'];

            // Remove técnico e usuário
            $pdo->prepare("DELETE FROM tecnicos WHERE id = :id")->execute(['id' => $tecnico_id]);
            $pdo->prepare("DELETE FROM usuarios WHERE id = :id")->execute(['id' => $usuario_id]);

            $pdo->commit();

            echo json_encode(["status" => "Técnico excluído com sucesso"]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(["erro" => "Erro interno: " . $e->getMessage()]);
        }
        exit;
    }

    // Rota não encontrada
    http_response_code(404);
    echo json_encode(["erro" => "Ação não encontrada"]);
    exit;
}
