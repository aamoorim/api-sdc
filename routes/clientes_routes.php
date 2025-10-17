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

if ($uri[0] === "clientes") {
    $payload = autenticar();

    // GET - Obter cliente específico (com id)
    if ($method === "GET" && isset($uri[1])) {
        $cliente_id = $uri[1];

        $stmt = $pdo->prepare("SELECT usuario_id FROM clientes WHERE id = :id");
        $stmt->execute(['id' => $cliente_id]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cliente) {
            http_response_code(404);
            echo json_encode(["erro" => "Cliente não encontrado"]);
            exit;
        }

        if ($payload->role !== "admin" && $cliente['usuario_id'] != $payload->sub) {
            http_response_code(403);
            echo json_encode(["erro" => "Acesso negado"]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT c.id, c.empresa, c.setor, u.nome, u.email, u.id as usuario_id
            FROM clientes c 
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.id = :id
        ");
        $stmt->execute(['id' => $cliente_id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
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

        if (empty($input['nome']) || empty($input['email']) || empty($input['empresa']) || empty($input['setor'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Nome, email, empresa e setor são obrigatórios"]);
            exit;
        }

        // Senha: se fornecida valida, senão gera aleatória
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

            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, tipo_usuario) VALUES (:nome, :email, :senha, 'cliente')");
            $stmt->execute([
                "nome" => $input['nome'],
                "email" => $input['email'],
                "senha" => $hash
            ]);
            $usuario_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO clientes (usuario_id, empresa, setor) VALUES (:usuario_id, :empresa, :setor)");
            $stmt->execute([
                "usuario_id" => $usuario_id,
                "empresa" => $input['empresa'],
                "setor" => $input['setor']
            ]);
            $cliente_id = $pdo->lastInsertId();

            $pdo->commit();

            echo json_encode([
                "status" => "Cliente criado com sucesso",
                "id" => $cliente_id,
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

    // PUT - Editar cliente (apenas admin)
    if ($method === "PUT" && isset($uri[1])) {
        if ($payload->role !== "admin") {
            http_response_code(403);
            echo json_encode(["erro" => "Acesso negado"]);
            exit;
        }

        $cliente_id = $uri[1];
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['nome']) || empty($input['email']) || empty($input['empresa']) || empty($input['setor'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Nome, email, empresa e setor são obrigatórios"]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT usuario_id FROM clientes WHERE id = :id");
            $stmt->execute(['id' => $cliente_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                http_response_code(404);
                echo json_encode(["erro" => "Cliente não encontrado"]);
                exit;
            }

            $usuario_id = $result['usuario_id'];

            $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, email = :email WHERE id = :id");
            $stmt->execute([
                "nome" => $input['nome'],
                "email" => $input['email'],
                "id" => $usuario_id
            ]);

            $stmt = $pdo->prepare("UPDATE clientes SET empresa = :empresa, setor = :setor WHERE id = :id");
            $stmt->execute([
                "empresa" => $input['empresa'],
                "setor" => $input['setor'],
                "id" => $cliente_id
            ]);

            // Senha
            $senhaRetorno = "não alterada";
            if (isset($input['senha'])) {
                if (!empty($input['senha'])) {
                    $validacao = validarSenha($input['senha']);
                    if ($validacao !== true) {
                        http_response_code(400);
                        echo json_encode(["erro" => $validacao]);
                        exit;
                    }
                    $hash = password_hash($input['senha'], PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
                    $stmt->execute(['senha' => $hash, 'id' => $usuario_id]);
                    $senhaRetorno = $input['senha'];
                }
            }

            $pdo->commit();

            echo json_encode([
                "status" => "Cliente atualizado com sucesso",
                "senha_atual" => $senhaRetorno
            ]);
            exit;

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

            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM chamados WHERE cliente_id = :id");
            $stmt->execute(['id' => $cliente_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['total'] > 0) {
                http_response_code(400);
                echo json_encode(["erro" => "Não é possível excluir cliente que possui chamados"]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT usuario_id FROM clientes WHERE id = :id");
            $stmt->execute(['id' => $cliente_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                http_response_code(404);
                echo json_encode(["erro" => "Cliente não encontrado"]);
                exit;
            }

            $usuario_id = $result['usuario_id'];

            $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = :id");
            $stmt->execute(['id' => $cliente_id]);

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

    http_response_code(404);
    echo json_encode(["erro" => "Ação não encontrada"]);
    exit;
}
