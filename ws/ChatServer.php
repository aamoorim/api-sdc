<?php
// ws/ChatServer.php
declare(strict_types=1);

require_once __DIR__ . '/WebSocketServer.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
set_exception_handler(function ($e) {
    echo "🚨 [EXCEPTION] {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
});

class ChatServer extends WebSocketServer
{

    public function __construct(string $addr, int $port, PDO $pdo, int $bufferLength = 2048)
    {
        parent::__construct($addr, $port, $pdo, $bufferLength);
        $this->log("✅ Servidor iniciado em {$addr}:{$port}");
    }

    /** Utilitário de log colorido */
    protected function log(string $msg, string $type = 'INFO'): void
    {
        $colors = [
            'INFO' => "\033[1;34m",   // azul
            'OK'   => "\033[1;32m",   // verde
            'WARN' => "\033[1;33m",   // amarelo
            'ERR'  => "\033[1;31m",   // vermelho
            'DB'   => "\033[1;35m",   // roxo
        ];
        $reset = "\033[0m";
        $time = date('H:i:s');
        $color = $colors[$type] ?? "\033[0m";
        echo "{$color}[{$time}] [{$type}] {$msg}{$reset}\n";
    }

    /** Cria ou reutiliza a conexão com o banco */
    protected function getDb(): PDO
    {
        $this->log("Iniciando getDb()", 'INFO');

        if (!$this->db) {
            $host = getenv('DB_HOST');
            $port = getenv('DB_PORT');
            $dbname = getenv('DB_NAME');
            $user = getenv('DB_USER');
            $pass = getenv('DB_PASS');

            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
            $this->log("🔌 Conectando ao banco: {$dsn}", 'DB');

            try {
                $this->db = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $this->log("✅ Conexão com banco estabelecida", 'OK');
            } catch (PDOException $e) {
                $this->log("❌ Erro ao conectar: " . $e->getMessage(), 'ERR');
                throw $e;
            }
        }

        return $this->db;
    }

    /** Processa mensagens enviadas pelos clientes */
    protected function process($user, string $message): void
    {
        $this->log("📩 Mensagem recebida do usuário {$user->id}: {$message}", 'INFO');

        try {
            $data = json_decode($message, true);
            if (!$data) {
                $this->send($user, json_encode(['erro' => 'Formato de mensagem inválido']));
                $this->log("❌ JSON inválido recebido", 'ERR');
                return;
            }

            $type = $data['type'] ?? null;
            $this->log("🔍 Tipo de mensagem: {$type}", 'INFO');

            switch ($type) {
                case 'auth':
                    $token = $data['token'] ?? '';
                    $this->log("🔐 Autenticando token: {$token}", 'INFO');

                    $payload = $this->authenticateUser($token);

                    if ($payload) {
                        $user->auth = $payload;
                        $this->send($user, json_encode(['success' => 'Autenticado com sucesso']));
                        $this->log("✅ Autenticação OK | Usuário ID: {$payload['id']}", 'OK');
                    } else {
                        $this->send($user, json_encode(['erro' => 'Token inválido ou expirado']));
                        $this->log("❌ Token inválido. Desconectando usuário {$user->id}", 'ERR');
                        $this->disconnect($user->socket);
                    }
                    break;

                case 'join':
                    if (!$user->auth) {
                        $this->send($user, json_encode(['erro' => 'Necessário autenticação']));
                        $this->log("🚫 join rejeitado: usuário não autenticado", 'WARN');
                        return;
                    }

                    $chamadoId = $data['chamado_id'] ?? null;
                    if (!$chamadoId) {
                        $this->send($user, json_encode(['erro' => 'Chamado inválido']));
                        $this->log("🚫 join rejeitado: chamado_id ausente", 'WARN');
                        return;
                    }

                    $user->current_chamado = $chamadoId;
                    $this->send($user, json_encode(['success' => "Entrou no chamado {$chamadoId}"]));
                    $this->log("🟢 Usuário {$user->auth['id']} entrou no chamado {$chamadoId}", 'OK');
                    break;

                case 'msg':
                    if (!$user->auth) {
                        $this->send($user, json_encode(['erro' => 'Necessário autenticação']));
                        $this->log("🚫 msg rejeitada: usuário não autenticado", 'WARN');
                        return;
                    }

                    if (!$user->current_chamado) {
                        $this->send($user, json_encode(['erro' => 'Usuário não está em nenhum chamado']));
                        $this->log("🚫 msg rejeitada: usuário sem chamado ativo", 'WARN');
                        return;
                    }

                    $texto = trim($data['message'] ?? '');
                    if ($texto === '') {
                        $this->send($user, json_encode(['erro' => 'Mensagem vazia']));
                        $this->log("⚠️ Mensagem vazia ignorada", 'WARN');
                        return;
                    }

                    $this->log("💾 Gravando mensagem no banco...", 'DB');
                    $stmt = $this->getDb()->prepare("
                        INSERT INTO mensagens (chamado_id, usuario_id, mensagem, criado_em)
                        VALUES (:chamado_id, :usuario_id, :mensagem, NOW())
                    ");
                    $stmt->execute([
                        ':chamado_id' => $user->current_chamado,
                        ':usuario_id' => $user->auth['id'],
                        ':mensagem'   => $texto
                    ]);
                    $this->log("✅ Mensagem salva no banco (Chamado {$user->current_chamado})", 'OK');

                    $msg = [
                        'type'       => 'msg',
                        'chamado_id' => $user->current_chamado,
                        'usuario_id' => $user->auth['id'],
                        'mensagem'   => $texto,
                        'criado_em'  => date('c')
                    ];

                    foreach ($this->users as $u) {
                        if ($u->current_chamado === $user->current_chamado) {
                            $this->send($u, json_encode($msg));
                            $this->log("📤 Mensagem enviada para usuário {$u->id}", 'INFO');
                        }
                    }
                    break;

                default:
                    $this->send($user, json_encode(['erro' => 'Tipo de mensagem desconhecido']));
                    $this->log("⚠️ Tipo de mensagem desconhecido recebido", 'WARN');
            }
        } catch (Throwable $e) {
            $this->log("🚨 Erro em process(): " . $e->getMessage(), 'ERR');
            $this->log($e->getTraceAsString(), 'ERR');
        }
    }

    /** Chamado quando um cliente conecta */
    protected function connected($user): void
    {
        $this->log("🔗 Novo cliente conectado (ID: {$user->id})", 'OK');
    }

    /** Chamado quando um cliente desconecta */
    protected function closed($user): void
    {
        $this->log("❌ Cliente desconectado (ID: {$user->id})", 'WARN');
    }
}
