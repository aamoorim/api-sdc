<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../utils/log_auditoria.php';

if ($uri[0] === "clientes") {
    $payload = autenticar();

    // GET - Obter cliente específico (com id)
    if ($method === "GET" && isset($uri[1])) {
        $cliente_id = $uri[1];

        // Buscar usuario_id do cliente solicitado
        $stmt = $pdo->prepare("SELECT usuario_id FROM clientes WHERE id = :id");
        $stmt->execute(['id' => $cliente_id]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cliente) {
            http_response_code(404);
            echo json_encode(["erro" => "Cliente não encontrado"]);
            exit;
        }

        // Permitir admin acessar qualquer cliente
        // Permitir cliente acessar somente seu próprio registro
        if ($payload->role !== "admin" && $cliente['usuario_id'] != $payload->sub) {
            http_response_code(403);
            echo json_encode(["erro" => "Acesso negado"]);
            exit;
        }

        // Buscar e retornar os dados do cliente solicitado
        $stmt = $pdo->prepare("
            SELECT c.id, c.empresa, c.setor, u.nome, u.email, u.id as usuario_id
            FROM clientes c 
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.id = :id
        ");
        $stmt->execute(['id' => $cliente_id]);
        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($dados);
        exit;
    }

    // GET - Listar todos os clientes (apenas admin)
    if ($method === "GET") {
        if ($payload->role !== "admin") {
            http_response_code(403);
            echo json_encode(["erro" => "Acesso negado"]);
            exit;
        }

        $stmt = $pdo->query("
            SELECT c.id, c.empresa, c.setor, u.nome, u.email, u.id as usuario_id
            FROM clientes c 
            JOIN usuarios u ON c.usuario_id = u.id 
            ORDER BY u.nome
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // POST - Criar novo cliente (apenas admin)
    if ($method === "POST") {
        if ($payload->role !== "admin") {
            http_response_code(403);
            echo json_encode(["erro" => "Acesso negado"]);
            exit;
        }

        $input = json_decode(file_get_contents("php://input"), true);

        // Validação básica
        if (empty($input['nome']) || empty($input['email']) || empty($input['empresa']) || empty($input['setor'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Nome, email, empresa e setor são obrigatórios"]);
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
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, tipo_usuario) VALUES (:nome, :email, :senha, 'cliente')");
            $stmt->execute([
                "nome" => $input['nome'],
                "email" => $input['email'],
                "senha" => $hash
            ]);
            $usuario_id = $pdo->lastInsertId();

            // Inserir na tabela clientes
            $stmt = $pdo->prepare("INSERT INTO clientes (usuario_id, empresa, setor) VALUES (:usuario_id, :empresa, :setor)");
            $stmt->execute([
                "usuario_id" => $usuario_id,
                "empresa" => $input['empresa'],
                "setor" => $input['setor']
            ]);
            $cliente_id = $pdo->lastInsertId();

            registrarLogAuditoria(
                $pdo,
                $payload->sub,
                "criar",
                "Criou novo cliente",
                null,
                [
                    "cliente_id" => $cliente_id,
                    "usuario_id" => $usuario_id,
                    "nome" => $input['nome'],
                    "email" => $input['email'],
                    "empresa" => $input['empresa'],
                    "setor" => $input['setor']
                ]
            );

            $pdo->commit();
            echo json_encode([
                "status" => "Cliente criado com sucesso", 
                "id" => $cliente_id,
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

    // PUT - Editar cliente (apenas admin)
    // PUT - Editar cliente (apenas admin)
if ($method === "PUT" && isset($uri[1])) {
    if ($payload->role !== "admin") {
        http_response_code(403);
        echo json_encode(["erro" => "Acesso negado"]);
        exit;
    }

    $cliente_id = $uri[1];
    $input = json_decode(file_get_contents("php://input"), true);

    // Validação básica
    if (empty($input['nome']) || empty($input['email']) || empty($input['empresa']) || empty($input['setor'])) {
        http_response_code(400);
        echo json_encode(["erro" => "Nome, email, empresa e setor são obrigatórios"]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Buscar o usuario_id do cliente
        $stmt = $pdo->prepare("SELECT usuario_id FROM clientes WHERE id = :id");
        $stmt->execute(['id' => $cliente_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            http_response_code(404);
            echo json_encode(["erro" => "Cliente não encontrado"]);
            exit;
        }

        $usuario_id = $result['usuario_id'];

        // Buscar dados antigos antes da atualização
        $stmt = $pdo->prepare("
            SELECT u.nome, u.email, c.empresa, c.setor 
            FROM usuarios u 
            JOIN clientes c ON u.id = c.usuario_id 
            WHERE c.id = :id
        ");
        $stmt->execute(['id' => $cliente_id]);
        $dados_antigos = $stmt->fetch(PDO::FETCH_ASSOC);

        // Atualizar dados na tabela usuarios
        $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, email = :email WHERE id = :id");
        $stmt->execute([
            "nome" => $input['nome'],
            "email" => $input['email'],
            "id" => $usuario_id
        ]);

        // Atualizar dados na tabela clientes
        $stmt = $pdo->prepare("UPDATE clientes SET empresa = :empresa, setor = :setor WHERE id = :id");
        $stmt->execute([
            "empresa" => $input['empresa'],
            "setor" => $input['setor'],
            "id" => $cliente_id
        ]);

        // Se foi fornecida nova senha, atualizar
        $senha_alterada = false;
        if (!empty($input['senha'])) {
            $hash = password_hash($input['senha'], PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
            $stmt->execute(['senha' => $hash, 'id' => $usuario_id]);
            $senha_alterada = true;
        }

        // Registrar log de auditoria
        registrarLogAuditoria(
            $pdo,
            $payload->sub,
            "editar",
            "Editou cliente",
            $dados_antigos,
            [
                "nome" => $input['nome'],
                "email" => $input['email'],
                "empresa" => $input['empresa'],
                "setor" => $input['setor'],
                "senha" => $senha_alterada ? "[alterada]" : "[inalterada]"
            ]
        );

        $pdo->commit();

        echo json_encode(["status" => "Cliente atualizado com sucesso"]);

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


    // DELETE - Excluir cliente (apenas admin)
    if ($method === "DELETE" && isset($uri[1])) {
        if ($payload->role !== "admin") {
            http_response_code(403);
            echo json_encode(["erro" => "Acesso negado"]);
            exit;
        }

        $cliente_id = $uri[1];

        try {
            $pdo->beginTransaction();

            // Verificar se o cliente tem chamados
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM chamados WHERE cliente_id = :id");
            $stmt->execute(['id' => $cliente_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['total'] > 0) {
                http_response_code(400);
                echo json_encode(["erro" => "Não é possível excluir cliente que possui chamados"]);
                exit;
            }

            // Buscar o usuario_id do cliente
            $stmt = $pdo->prepare("SELECT usuario_id FROM clientes WHERE id = :id");
            $stmt->execute(['id' => $cliente_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                http_response_code(404);
                echo json_encode(["erro" => "Cliente não encontrado"]);
                exit;
            }

            $usuario_id = $result['usuario_id'];

            // Excluir da tabela clientes primeiro
            $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = :id");
            $stmt->execute(['id' => $cliente_id]);

            // Depois excluir da tabela usuarios
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->execute(['id' => $usuario_id]);

            $pdo->commit();

            echo json_encode(["status" => "Cliente excluído com sucesso"]);

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
