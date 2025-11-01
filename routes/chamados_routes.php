<?php
// Habilitar CORS
header("Access-Control-Allow-Origin: *");  // ou especificar origem permitida
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400"); // opcional: tempo para cache do preflight

// Se for requisição preflight (OPTIONS), responder e sair
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../utils/log_auditoria.php';

// Pegar método HTTP e a URI fragmentada
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api-sdc/#', '', $path); // remove prefixo
$uri = array_values(array_filter(explode('/', $path)));

if (!isset($uri[0]) || $uri[0] !== 'chamados') {
    http_response_code(404);
    echo json_encode(["erro" => "Rota não encontrada"]);
    exit;
}

$payload = autenticar(); // função de autenticação via JWT


// ============================
// Rota específica: mensagens
// ============================
if (isset($uri[1]) && $uri[1] === "mensagens" && isset($uri[2])) {
    $chamado_id = intval($uri[2]);

    // Verificar acesso ao chamado
    if ($payload->role === "cliente") {
        $stmt = $pdo->prepare("
            SELECT c.id 
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id
            WHERE c.id = :chamado_id AND cl.usuario_id = :usuario_id
        ");
        $stmt->execute([
            'chamado_id' => $chamado_id,
            'usuario_id' => $payload->sub
        ]);
        if ($stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['erro' => 'Chamado não encontrado ou sem permissão']);
            exit;
        }
    } elseif ($payload->role === "tecnico") {
        $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
        $stmtTecnico->execute(['usuario_id' => $payload->sub]);
        $tecnico = $stmtTecnico->fetch(PDO::FETCH_ASSOC);
        if (!$tecnico) {
            http_response_code(403);
            echo json_encode(['erro' => 'Técnico não encontrado']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM chamados WHERE id = :chamado_id AND tecnico_id = :tecnico_id");
        $stmt->execute([
            'chamado_id' => $chamado_id,
            'tecnico_id' => $tecnico['id']
        ]);
        if ($stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['erro' => 'Chamado não pertence ao técnico']);
            exit;
        }
    }

    // GET - histórico de mensagens
    if ($method === "GET") {
        $stmt = $pdo->prepare("
            SELECT m.id, m.mensagem, m.criado_em,
                   u.id AS usuario_id, u.nome, u.tipo_usuario
            FROM mensagens m
            JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.chamado_id = :chamado_id
            ORDER BY m.criado_em ASC
        ");
        $stmt->execute(['chamado_id' => $chamado_id]);
        $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'mensagens' => $mensagens]);
        exit;
    }

    // POST - criar nova mensagem
    if ($method === "POST") {
        $input = json_decode(file_get_contents("php://input"), true);
        $texto = trim($input['mensagem'] ?? '');

        if ($texto === '') {
            http_response_code(400);
            echo json_encode(['erro' => 'Mensagem não pode ser vazia']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO mensagens (chamado_id, usuario_id, mensagem, criado_em)
            VALUES (:chamado_id, :usuario_id, :mensagem, NOW())
        ");
        $stmt->execute([
            'chamado_id' => $chamado_id,
            'usuario_id' => $payload->sub,
            'mensagem'   => $texto
        ]);

        $novaMsg = [
            'type'       => 'msg',
            'chamado_id' => $chamado_id,
            'usuario_id' => $payload->sub,
            'mensagem'   => $texto,
            'criado_em'  => date('c')
        ];

        // Notificar WebSocket
        $sock = @fsockopen("127.0.0.1", 9000, $errno, $errstr, 2);
        if ($sock) {
            fwrite($sock, json_encode($novaMsg) . "\n");
            fclose($sock);
        }

        echo json_encode(['ok' => true, 'mensagem' => $novaMsg]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);
    exit;
}


// GET - Listar ou buscar chamados
if ($method === "GET") {
    if ($payload->role === "cliente") {
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   cl.empresa,
                   uc.nome as cliente_nome,
                   uc.email as cliente_email,
                   ut.nome as tecnico_nome,
                   ut.email as tecnico_email
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id 
            JOIN usuarios uc ON cl.usuario_id = uc.id
            LEFT JOIN tecnicos t ON c.tecnico_id = t.id 
            LEFT JOIN usuarios ut ON t.usuario_id = ut.id
            WHERE cl.usuario_id = :usuario_id 
            ORDER BY c.data_criacao DESC
        ");
        $stmt->execute(['usuario_id' => $payload->sub]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($dados);
        exit;
    }

    if ($payload->role === "tecnico") {
        $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
        $stmtTecnico->execute(['usuario_id' => $payload->sub]);
        $tecnico = $stmtTecnico->fetch(PDO::FETCH_ASSOC);

        if (!$tecnico) {
            http_response_code(403);
            echo json_encode(["erro" => "Usuário não é um técnico válido"]);
            exit;
        }

        if (isset($uri[1]) && is_numeric($uri[1])) {
            $stmt = $pdo->prepare("
                SELECT 
                    c.*, 
                    cl.empresa, 
                    uc.nome AS cliente_nome,
                    uc.email AS cliente_email,
                    ut.nome AS tecnico_nome,
                    ut.email AS tecnico_email
                FROM chamados c
                JOIN clientes cl ON c.cliente_id = cl.id
                JOIN usuarios uc ON cl.usuario_id = uc.id
                LEFT JOIN tecnicos t ON c.tecnico_id = t.id
                LEFT JOIN usuarios ut ON t.usuario_id = ut.id
                WHERE c.id = :chamado_id AND c.tecnico_id = :tecnico_id
            ");
            $stmt->execute([
                'chamado_id' => $uri[1],
                'tecnico_id' => $tecnico['id']
            ]);
            $chamado = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$chamado) {
                http_response_code(404);
                echo json_encode(["erro" => "Chamado não encontrado ou não pertence ao técnico"]);
                exit;
            }

            echo json_encode($chamado);
            exit;
        }

        // Incluir dados completos do técnico e cliente
        if (!isset($uri[1]) || $uri[1] === "") {
            $stmt = $pdo->prepare("
                SELECT c.*, 
                       cl.empresa, 
                       uc.nome as cliente_nome,
                       uc.email as cliente_email,
                       ut.nome as tecnico_nome,
                       ut.email as tecnico_email
                FROM chamados c 
                JOIN clientes cl ON c.cliente_id = cl.id 
                JOIN usuarios uc ON cl.usuario_id = uc.id 
                LEFT JOIN tecnicos t ON c.tecnico_id = t.id 
                LEFT JOIN usuarios ut ON t.usuario_id = ut.id 
                WHERE c.tecnico_id = :tecnico_id AND c.status = 'em_andamento'
                ORDER BY c.data_criacao DESC
            ");
            $stmt->execute(['tecnico_id' => $tecnico['id']]);
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($dados);
            exit;
        }

        if (isset($uri[1]) && $uri[1] === "abertos") {
            $stmt = $pdo->query("
                SELECT c.*, 
                       cl.empresa, 
                       cl.setor, 
                       uc.nome as cliente_nome,
                       uc.email as cliente_email
                FROM chamados c 
                JOIN clientes cl ON c.cliente_id = cl.id 
                JOIN usuarios uc ON cl.usuario_id = uc.id 
                WHERE c.status = 'aberto' AND c.tecnico_id IS NULL 
                ORDER BY c.data_criacao DESC
            ");
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($dados);
            exit;
        }

        // Adicionar rota para buscar todos os chamados do técnico (incluindo encerrados)
        if (isset($uri[1]) && $uri[1] === "todos") {
            $stmt = $pdo->prepare("
                SELECT c.*, 
                       cl.empresa, 
                       uc.nome as cliente_nome,
                       uc.email as cliente_email,
                       ut.nome as tecnico_nome,
                       ut.email as tecnico_email
                FROM chamados c 
                JOIN clientes cl ON c.cliente_id = cl.id 
                JOIN usuarios uc ON cl.usuario_id = uc.id 
                LEFT JOIN tecnicos t ON c.tecnico_id = t.id 
                LEFT JOIN usuarios ut ON t.usuario_id = ut.id 
                WHERE c.tecnico_id = :tecnico_id
                ORDER BY c.data_criacao DESC
            ");
            $stmt->execute(['tecnico_id' => $tecnico['id']]);
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($dados);
            exit;
        }
    }

    if ($payload->role === "admin") {
        $stmt = $pdo->query("
            SELECT c.*, 
                   cl.empresa, cl.setor, 
                   uc.nome as cliente_nome,
                   uc.email as cliente_email,
                   ut.nome as tecnico_nome,
                   ut.email as tecnico_email
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id 
            JOIN usuarios uc ON cl.usuario_id = uc.id 
            LEFT JOIN tecnicos t ON c.tecnico_id = t.id 
            LEFT JOIN usuarios ut ON t.usuario_id = ut.id 
            ORDER BY c.data_criacao DESC
        ");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($dados);
        exit;
    }

    http_response_code(404);
    echo json_encode(["erro" => "Ação GET não encontrada"]);
    exit;
}

// POST - Criar chamado
if ($method === "POST") {
    if ($payload->role === "cliente") {
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['titulo']) || empty($input['descricao'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Título e descrição são obrigatórios"]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM clientes WHERE usuario_id = :usuario_id");
        $stmt->execute(['usuario_id' => $payload->sub]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cliente) {
            http_response_code(400);
            echo json_encode(["erro" => "Cliente não encontrado"]);
            exit;
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO chamados (titulo, descricao, cliente_id, status, data_criacao)
            VALUES (:titulo, :descricao, :cliente_id, 'aberto', NOW())
        ");
        $sucesso = $stmtIns->execute([
            'titulo'      => $input['titulo'],
            'descricao'   => $input['descricao'],
            'cliente_id'  => $cliente['id']
        ]);

        if (!$sucesso) {
            $errorInfo = $stmtIns->errorInfo();
            http_response_code(500);
            echo json_encode(["erro" => "Falha ao criar chamado", "detail" => $errorInfo]);
            exit;
        }

        $novoId = $pdo->lastInsertId();
        $stmtNovo = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
        $stmtNovo->execute(['id' => $novoId]);
        $novoChamado = $stmtNovo->fetch(PDO::FETCH_ASSOC);

         // 🔹 Registrar log de criação
        registrarLogAuditoria(
            $pdo,
            $payload->sub,
            'Criou',
            'Criou novo chamado',
            null,
            $novoChamado
        );

        echo json_encode($novoChamado);
        exit;
    }

    http_response_code(403);
    echo json_encode(["erro" => "Você não tem permissão para criar chamados"]);
    exit;
}

// PUT - Atualizar chamado
if ($method === "PUT" && isset($uri[1])) {
    $chamado_id = $uri[1];
    $input = json_decode(file_get_contents("php://input"), true);

    // Pegar dados antigos para o log
    $stmtAntigo = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
    $stmtAntigo->execute(['id' => $chamado_id]);
    $valorAntigo = $stmtAntigo->fetch(PDO::FETCH_ASSOC);

    // Cliente editar próprio chamado
    if ($payload->role === "cliente") {
        $stmtCliente = $pdo->prepare("
            SELECT c.cliente_id 
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id 
            WHERE c.id = :id AND cl.usuario_id = :usuario_id
        ");
        $stmtCliente->execute([
            'id' => $chamado_id,
            'usuario_id' => $payload->sub
        ]);
        $rel = $stmtCliente->fetch(PDO::FETCH_ASSOC);
        if (!$rel) {
            http_response_code(403);
            echo json_encode(["erro" => "Chamado não encontrado ou você não tem permissão para editar"]);
            exit;
        }

        if (empty($input['titulo']) || empty($input['descricao'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Título e descrição são obrigatórios"]);
            exit;
        }

        $stmtUpd = $pdo->prepare("
            UPDATE chamados
            SET titulo = :titulo, descricao = :descricao
            WHERE id = :id
        ");
        $stmtUpd->execute([
            'titulo'    => $input['titulo'],
            'descricao' => $input['descricao'],
            'id'        => $chamado_id
        ]);

        if ($stmtUpd->rowCount() > 0) {
            $stmtSel = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
            $stmtSel->execute(['id' => $chamado_id]);
            $chamadoAtualizado = $stmtSel->fetch(PDO::FETCH_ASSOC);
            
            // Registrar log de edição
            registrarLogAuditoria(
                $pdo,
                $payload->sub,
                'Editou',
                'Chamado editado pelo cliente',
                $valorAntigo,
                $chamadoAtualizado
            );
            
            echo json_encode($chamadoAtualizado);
        } else {
            http_response_code(404);
            echo json_encode(["erro" => "Chamado não encontrado ou já possui esses dados"]);
        }
        exit;
    }

    // Técnico atribuir chamado
   if ($payload->role === "tecnico" && isset($uri[2]) && $uri[2] === "atribuir") {
    $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
    $stmtTecnico->execute(['usuario_id' => $payload->sub]);
    $tecnico = $stmtTecnico->fetch(PDO::FETCH_ASSOC);

    if (!$tecnico) {
        http_response_code(403);
        echo json_encode(["erro" => "Usuário não é um técnico válido"]);
        exit;
    }

    // Buscar dados antigos (para log)
    $stmtAntigo = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
    $stmtAntigo->execute(['id' => $chamado_id]);
    $valorAntigo = $stmtAntigo->fetch(PDO::FETCH_ASSOC);

    if (!$valorAntigo) {
        http_response_code(404);
        echo json_encode(["erro" => "Chamado não encontrado"]);
        exit;
    }

    if ($valorAntigo['status'] !== 'aberto') {
        http_response_code(400);
        echo json_encode(["erro" => "Chamado não está disponível para atribuição"]);
        exit;
    }

    // Atualiza o chamado
    $stmtUpd = $pdo->prepare("
        UPDATE chamados 
        SET tecnico_id = :tecnico_id, status = 'em_andamento' 
        WHERE id = :id
    ");
    $stmtUpd->execute([
        'tecnico_id' => $tecnico['id'],
        'id' => $chamado_id
    ]);

    if ($stmtUpd->rowCount() > 0) {
        // Buscar dados novos após a atribuição
        $stmtNovo = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
        $stmtNovo->execute(['id' => $chamado_id]);
        $valorNovo = $stmtNovo->fetch(PDO::FETCH_ASSOC);

        // Registrar log de auditoria
        registrarLogAuditoria(
            $pdo,
            $payload->sub,
            'Atribuiu',
            "Técnico atribuiu ao chamado",
            $valorAntigo,
            $valorNovo
        );

        echo json_encode(["status" => "Chamado atribuído com sucesso"]);
    } else {
        http_response_code(400);
        echo json_encode(["erro" => "Falha ao atribuir chamado"]);
    }
    exit;
}

    // Técnico encerrar chamado
   if ($payload->role === "tecnico" && isset($uri[2]) && $uri[2] === "encerrar") {
    $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
    $stmtTecnico->execute(['usuario_id' => $payload->sub]);
    $tecnico = $stmtTecnico->fetch(PDO::FETCH_ASSOC);

    if (!$tecnico) {
        http_response_code(403);
        echo json_encode(["erro" => "Técnico não encontrado"]);
        exit;
    }

    // Buscar dados antigos (antes de encerrar)
    $stmt = $pdo->prepare("SELECT * FROM chamados WHERE id = :id AND tecnico_id = :tecnico_id");
    $stmt->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);
    $valorAntigo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$valorAntigo) {
        http_response_code(400);
        echo json_encode(["erro" => "Chamado não encontrado ou não é seu"]);
        exit;
    }

    // Atualizar status para encerrado
    $stmtUpd = $pdo->prepare("
        UPDATE chamados 
        SET status = 'encerrado'
        WHERE id = :id AND tecnico_id = :tecnico_id
    ");
    $stmtUpd->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);

    if ($stmtUpd->rowCount() > 0) {
        // Buscar o novo estado após o encerramento
        $stmtNovo = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
        $stmtNovo->execute(['id' => $chamado_id]);
        $valorNovo = $stmtNovo->fetch(PDO::FETCH_ASSOC);

        // Registrar log de auditoria
        registrarLogAuditoria(
            $pdo,
            $payload->sub,
            'Encerrou',
            "Técnico encerrou o chamado",
            $valorAntigo,
            $valorNovo
        );

        echo json_encode(["status" => "Chamado encerrado com sucesso"]);
    } else {
        http_response_code(400);
        echo json_encode(["erro" => "Falha ao encerrar chamado"]);
    }
    exit;
}

    // Técnico atualizar status simples (para encerrar via frontend)
    if ($payload->role === "tecnico") {
        $stmtTecnico = $pdo->prepare("SELECT id FROM tecnicos WHERE usuario_id = :usuario_id");
        $stmtTecnico->execute(['usuario_id' => $payload->sub]);
        $tecnico = $stmtTecnico->fetch(PDO::FETCH_ASSOC);

        if (!$tecnico) {
            http_response_code(403);
            echo json_encode(["erro" => "Técnico não encontrado"]);
            exit;
        }

        // Verificar se é um chamado do técnico
        $stmt = $pdo->prepare("SELECT * FROM chamados WHERE id = :id AND tecnico_id = :tecnico_id");
        $stmt->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);

        if ($stmt->rowCount() == 0) {
            http_response_code(403);
            echo json_encode(["erro" => "Chamado não encontrado ou não é seu"]);
            exit;
        }

        // Se tem status no input, atualizar
        if (isset($input['status'])) {
            $stmtUpd = $pdo->prepare("UPDATE chamados SET status = :status WHERE id = :id AND tecnico_id = :tecnico_id");
            $stmtUpd->execute([
                'status' => $input['status'],
                'id' => $chamado_id,
                'tecnico_id' => $tecnico['id']
            ]);

            if ($stmtUpd->rowCount() > 0) {
                echo json_encode(["status" => "Chamado atualizado com sucesso"]);
            } else {
                http_response_code(400);
                echo json_encode(["erro" => "Falha ao atualizar chamado"]);
            }
            exit;
        }
    }

    // ADMIN editar chamado
   if ($payload->role === "admin") {
    if (empty($input['titulo']) || empty($input['descricao'])) {
        http_response_code(400);
        echo json_encode(["erro" => "Informe 'titulo' e 'descricao'."]);
        exit;
    }

    // Buscar dados antigos antes da atualização
    $stmtAntigo = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
    $stmtAntigo->execute(['id' => $chamado_id]);
    $valorAntigo = $stmtAntigo->fetch(PDO::FETCH_ASSOC);

    if (!$valorAntigo) {
        http_response_code(404);
        echo json_encode(["erro" => "Chamado não encontrado"]);
        exit;
    }

    // Atualizar dados do chamado
    $stmtUpd = $pdo->prepare("
        UPDATE chamados
        SET titulo = :titulo, descricao = :descricao
        WHERE id = :id
    ");
    $stmtUpd->execute([
        'titulo'    => $input['titulo'],
        'descricao' => $input['descricao'],
        'id'        => $chamado_id
    ]);

    if ($stmtUpd->rowCount() > 0) {
        // Buscar dados novos após a atualização
        $stmtSel = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
        $stmtSel->execute(['id' => $chamado_id]);
        $chamadoAtualizado = $stmtSel->fetch(PDO::FETCH_ASSOC);

        // Registrar log de auditoria
        registrarLogAuditoria(
            $pdo,
            $payload->sub,
            'Editou',
            'Chamado editado pelo administrador',
            $valorAntigo,
            $chamadoAtualizado
        );

        echo json_encode($chamadoAtualizado);
    } else {
        http_response_code(404);
        echo json_encode(["erro" => "Chamado não encontrado ou sem alterações"]);
    }
    exit;
}

    http_response_code(404);
    echo json_encode(["erro" => "Ação PUT não encontrada"]);
    exit;
}

// DELETE - Deletar chamado
if ($method === "DELETE" && isset($uri[1])) {
    if ($payload->role === "admin") {
        $chamado_id = $uri[1];

        // Pegar todos os dados do chamado para log
        $stmtChk = $pdo->prepare("SELECT * FROM chamados WHERE id = :id");
        $stmtChk->execute(['id' => $chamado_id]);
        $valorAntigo = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if (!$valorAntigo) {
            http_response_code(404);
            echo json_encode(["erro" => "Chamado não encontrado"]);
            exit;
        }

        // Deletar chamado
        $stmtDel = $pdo->prepare("DELETE FROM chamados WHERE id = :id");
        $stmtDel->execute(['id' => $chamado_id]);

       error_log("Tentando registrar log de deleção do chamado: " . json_encode($valorAntigo));
        registrarLogAuditoria(
            $pdo,
            $payload->sub,
            'Deletou',
            'Chamado deletado',
            $valorAntigo,
            null
        );
        error_log("Log registrado");


        echo json_encode(["status" => "Chamado deletado com sucesso"]);
        exit;
    } else {
        http_response_code(403);
        echo json_encode(["erro" => "Você não tem permissão para deletar chamados"]);
        exit;
    }
}


// Se chegar aqui, rota não encontrada
http_response_code(404);    
echo json_encode(["erro" => "Rota não encontrada"]);
exit;