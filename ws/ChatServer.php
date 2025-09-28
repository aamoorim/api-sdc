<?php
// ws/ChatServer.php
declare(strict_types=1);

require_once __DIR__ . '/WebSocketServer.php';

class ChatServer extends WebSocketServer
{
    public function __construct(string $addr, int $port, PDO $pdo, int $bufferLength = 2048)
    {
        parent::__construct($addr, $port, $pdo, $bufferLength);
    }

    /**
     * Processa mensagens enviadas pelos clientes.
     */
    protected function process($user, string $message): void
    {
        $data = json_decode($message, true);

        if (!$data) {
            $this->send($user, json_encode(['erro' => 'Formato de mensagem inválido']));
            return;
        }

        switch ($data['type'] ?? null) {
            case 'auth':
                $token = $data['token'] ?? '';
                $payload = $this->authenticateUser($token);

                if ($payload) {
                    $user->auth = $payload;
                    $this->send($user, json_encode(['success' => 'Autenticado com sucesso']));
                } else {
                    $this->send($user, json_encode(['erro' => 'Token inválido ou expirado']));
                    $this->disconnect($user->socket);
                }
                break;

            case 'join':
                if (!$user->auth) {
                    $this->send($user, json_encode(['erro' => 'Necessário autenticação']));
                    return;
                }

                $chamadoId = $data['chamado_id'] ?? null;
                if (!$chamadoId) {
                    $this->send($user, json_encode(['erro' => 'Chamado inválido']));
                    return;
                }

                $user->current_chamado = $chamadoId;
                $this->send($user, json_encode(['success' => "Entrou no chamado {$chamadoId}"]));
                break;

            case 'msg':
                if (!$user->auth) {
                    $this->send($user, json_encode(['erro' => 'Necessário autenticação']));
                    return;
                }
                if (!$user->current_chamado) {
                    $this->send($user, json_encode(['erro' => 'Usuário não está em nenhum chamado']));
                    return;
                }

                $texto = trim($data['message'] ?? '');
                if ($texto === '') {
                    $this->send($user, json_encode(['erro' => 'Mensagem vazia']));
                    return;
                }

                // salva no banco
                $stmt = $this->db->prepare("
                    INSERT INTO mensagens (chamado_id, usuario_id, conteudo, criado_em)
                    VALUES (:chamado_id, :usuario_id, :conteudo, NOW())
                ");
                $stmt->execute([
                    ':chamado_id' => $user->current_chamado,
                    ':usuario_id' => $user->auth['id'],
                    ':conteudo'   => $texto
                ]);

                $msg = [
                    'type'       => 'msg',
                    'chamado_id' => $user->current_chamado,
                    'usuario_id' => $user->auth['id'],
                    'conteudo'   => $texto,
                    'criado_em'  => date('c')
                ];

                // envia para todos do mesmo chamado
                foreach ($this->users as $u) {
                    if ($u->current_chamado === $user->current_chamado) {
                        $this->send($u, json_encode($msg));
                    }
                }
                break;

            default:
                $this->send($user, json_encode(['erro' => 'Tipo de mensagem desconhecido']));
        }
    }

    /**
     * Chamado quando um cliente conecta (antes de autenticar).
     */
    protected function connected($user): void
    {
        $this->stdout("Novo cliente conectado ({$user->id})");
    }

    /**
     * Chamado quando um cliente desconecta.
     */
    protected function closed($user): void
    {
        $this->stdout("Cliente desconectado ({$user->id})");
    }
}
