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

// Pegar método HTTP e a URI fragmentada
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];
$path = parse_url($path, PHP_URL_PATH);
$uri = array_values(array_filter(explode('/', $path))); // remove vazios e reindexa

// Verifica se a rota é para chamados
if (!isset($uri[0]) || $uri[0] !== 'chamados') {
    http_response_code(404);
    echo json_encode(["erro" => "Rota não encontrada"]);
    exit;
}

$payload = autenticar(); // função de autenticação via JWT

// GET - Listar ou buscar chamados
if ($method === "GET") {
    if ($payload->role === "cliente") {
        $stmt = $pdo->prepare("
            SELECT c.* 
            FROM chamados c 
            JOIN clientes cl ON c.cliente_id = cl.id 
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
                    u.nome AS cliente_nome, 
                    ut.nome AS tecnico_nome,
                    ut.email AS tecnico_email
                FROM chamados c
                JOIN clientes cl ON c.cliente_id = cl.id
                JOIN usuarios u ON cl.usuario_id = u.id
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

        if (!isset($uri[1]) || $uri[1] === "") {
            $stmt = $pdo->prepare("
                SELECT c.*, cl.empresa, u.nome as cliente_nome 
                FROM chamados c 
                JOIN clientes cl ON c.cliente_id = cl.id 
                JOIN usuarios u ON cl.usuario_id = u.id 
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
                SELECT c.*, cl.empresa, cl.setor, u.nome as cliente_nome 
                FROM chamados c 
                JOIN clientes cl ON c.cliente_id = cl.id 
                JOIN usuarios u ON cl.usuario_id = u.id 
                WHERE c.status = 'aberto' AND c.tecnico_id IS NULL 
                ORDER BY c.data_criacao DESC
            ");
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

        $stmtChamado = $pdo->prepare("SELECT status FROM chamados WHERE id = :id");
        $stmtChamado->execute(['id' => $chamado_id]);
        $ch = $stmtChamado->fetch(PDO::FETCH_ASSOC);

        if (!$ch) {
            http_response_code(404);
            echo json_encode(["erro" => "Chamado não encontrado"]);
            exit;
        }

        if ($ch['status'] !== 'aberto') {
            http_response_code(400);
            echo json_encode(["erro" => "Chamado não está disponível para atribuição"]);
            exit;
        }

        $stmtUpd = $pdo->prepare("UPDATE chamados SET tecnico_id = :tecnico_id, status = 'em_andamento' WHERE id = :id");
        $stmtUpd->execute([
            'tecnico_id' => $tecnico['id'],
            'id'         => $chamado_id
        ]);

        if ($stmtUpd->rowCount() > 0) {
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

        $stmt = $pdo->prepare("SELECT * FROM chamados WHERE id = :id AND tecnico_id = :tecnico_id");
        $stmt->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);

        if ($stmt->rowCount() == 0) {
            http_response_code(400);
            echo json_encode(["erro" => "Chamado não encontrado ou não é seu"]);
            exit;
        }

        $stmtUpd = $pdo->prepare("UPDATE chamados SET status = 'encerrado' WHERE id = :id AND tecnico_id = :tecnico_id");
        $stmtUpd->execute(['id' => $chamado_id, 'tecnico_id' => $tecnico['id']]);

        echo json_encode(["status" => "Chamado encerrado com sucesso"]);
        exit;
    }

    // ADMIN editar chamado
    if ($payload->role === "admin") {
        if (empty($input['titulo']) || empty($input['descricao'])) {
            http_response_code(400);
            echo json_encode(["erro" => "Informe 'titulo' e 'descricao'."]);
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
            echo json_encode($chamadoAtualizado);
        } else {
            http_response_code(404);
            echo json_encode(["erro" => "Chamado não encontrado"]);
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
        $stmtChk = $pdo->prepare("SELECT id FROM chamados WHERE id = :id");
        $stmtChk->execute(['id' => $chamado_id]);
        if ($stmtChk->rowCount() == 0) {
            http_response_code(404);
            echo json_encode(["erro" => "Chamado não encontrado"]);
            exit;
        }

        $stmtDel = $pdo->prepare("DELETE FROM chamados WHERE id = :id");
        $stmtDel->execute(['id' => $chamado_id]);
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
