<?php
// ws/ChatServer.php

use Firebase\JWT\Key;

require_once __DIR__ . '/WebSocketServer.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/../config/db.php';   // $pdo
require_once __DIR__ . '/../config/jwt.php';  // validarToken() ou JWT_SECRET
require __DIR__ . '/../vendor/autoload.php';

class ChatServer extends WebSocketServer {

    protected $db;

    public function __construct($addr, $port, $bufferLength = 2048) {
        parent::__construct($addr, $port, $bufferLength);

        global $pdo;
        if (!isset($pdo) || !$pdo instanceof PDO) {
            $this->stderr("Aviso: \$pdo não encontrado ou inválido.\n");
            $this->db = null;
        } else {
            $this->db = $pdo;
        }
    }

    // ----------------------
    // Evento: handshake completo
    // ----------------------
    protected function connected($user) {
        $this->stdout("Handshake complete for {$user->id}");
        // Cliente deve enviar {type:'auth', token:'...'} depois
    }

    // ----------------------
    // Evento: desconexão
    // ----------------------
    protected function closed($user) {
        $this->stdout("Connection closed: {$user->id}");
        $payload = [
            'type'=>'left',
            'user'=> $user->auth ?? null,
            'chamado_id'=> $user->currentRoom ?? null
        ];
        foreach ($this->users as $u) {
            if ($u->handshake && $u !== $user && isset($u->currentRoom) && $u->currentRoom == $user->currentRoom) {
                $this->send($u, json_encode($payload));
            }
        }
    }

    // ----------------------
    // Processamento das mensagens
    // ----------------------
    protected function process($user, $message) {
        $data = json_decode($message, true);
        if (!$data) return $this->stderr("Mensagem não JSON recebida");

        $type = $data['type'] ?? '';

        switch($type) {

            case 'auth':
                $token = $data['token'] ?? null;
                if (!$token) {
                    $this->send($user, json_encode(['type'=>'auth','ok'=>false,'error'=>'no token']));
                    return;
                }

                $payload = $this->authenticateUser($token);
                if (!$payload) {
                    $this->send($user, json_encode(['type'=>'auth','ok'=>false,'error'=>'invalid token']));
                    return;
                }

                // Marca usuário como autenticado
                $user->auth = $payload;
                if (isset($payload['id'])) $user->auth['id'] = $payload['id'];
                elseif (isset($payload['user_id'])) $user->auth['id'] = $payload['user_id'];

                $this->send($user, json_encode(['type'=>'auth','ok'=>true,'user'=>$user->auth]));
                break;

            case 'join':
                if (!$user->auth) {
                    $this->send($user, json_encode(['type'=>'error','error'=>'not authenticated']));
                    return;
                }
                $chamado = $data['chamado_id'] ?? null;
                if (!$chamado) {
                    $this->send($user, json_encode(['type'=>'error','error'=>'missing chamado_id']));
                    return;
                }
                $user->currentRoom = (int)$chamado;
                $this->send($user, json_encode(['type'=>'joined','chamado_id'=>$user->currentRoom]));

                foreach ($this->users as $u) {
                    if ($u !== $user && $u->handshake && isset($u->currentRoom) && $u->currentRoom == $user->currentRoom) {
                        $this->send($u, json_encode(['type'=>'peer_joined','user'=>$user->auth,'chamado_id'=>$user->currentRoom]));
                    }
                }
                break;

            case 'msg':
                if (!$user->auth) {
                    $this->send($user, json_encode(['type'=>'error','error'=>'not authenticated']));
                    return;
                }
                $chamado = $data['chamado_id'] ?? ($user->currentRoom ?? null);
                if (!$chamado) {
                    $this->send($user, json_encode(['type'=>'error','error'=>'missing chamado_id']));
                    return;
                }
                $text = trim($data['text'] ?? '');
                if ($text === '') {
                    $this->send($user, json_encode(['type'=>'error','error'=>'empty message']));
                    return;
                }

                $sender_id = $user->auth['id'] ?? null;
                $msgId = null;

                if ($this->db) {
                    try {
                        $stmt = $this->db->prepare("
                            INSERT INTO mensagens (chamado_id, usuario_id, mensagem, criado_em)
                            VALUES (:chamado_id, :usuario_id, :mensagem, NOW())
                        ");
                        $stmt->execute([
                            ':chamado_id'=>$chamado,
                            ':usuario_id'=>$sender_id,
                            ':mensagem'=>$text
                        ]);
                        $msgId = $this->db->lastInsertId();
                    } catch (Exception $e) {
                        $this->stderr("Falha ao inserir DB: ".$e->getMessage());
                    }
                }

                $out = [
                    'type'=>'msg',
                    'id'=>$msgId,
                    'chamado_id'=>$chamado,
                    'usuario_id'=>$sender_id,
                    'usuario'=>$user->auth,
                    'mensagem'=>$text,
                    'criado_em'=>date('c')
                ];

                foreach ($this->users as $u) {
                    if ($u->handshake && isset($u->currentRoom) && $u->currentRoom == $chamado) {
                        $this->send($u, json_encode($out));
                    }
                }
                break;

            default:
                $this->send($user, json_encode(['type'=>'error','error'=>'unknown type']));
        }
    }
}
