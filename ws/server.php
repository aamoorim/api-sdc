<?php
// ws/server.php
use Firebase\JWT\Key;

set_time_limit(0);
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/../config/db.php';   // deve definir $pdo (PDO)
require_once __DIR__ . '/../config/jwt.php';  // sua função validarToken ou constantes
require_once __DIR__ . '/WebSocketServer.php';
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

define('JWT_SECRET', $_ENV['JWT_SECRET']);

class ChatServer extends WebSocketServer {

  protected $userClass = 'WebSocketUser';
  protected $db;

  public function __construct($addr, $port, $bufferLength = 2048) {
    parent::__construct($addr, $port, $bufferLength);

    global $pdo;
    if (!isset($pdo) || !$pdo instanceof PDO) {
      $this->stderr("Aviso: \$pdo não encontrado ou inválido em config/db.php. Ajuste include.\n");
      $this->db = null;
    } else {
      $this->db = $pdo;
    }
  }

  protected function connected($user) {
    $this->stdout("handshake complete for {$user->id}");
    // cliente deve enviar {type:'auth', token:'...'} em seguida
  }

  protected function closed($user) {
    $this->stdout("Connection closed: {$user->id}");
    // avisa outros que saiu
    $payload = ['type'=>'left','user'=> $user->auth ?? null, 'chamado_id' => $user->currentRoom ?? null];
    foreach ($this->users as $u) {
      if ($u->handshake && $u !== $user) {
        $this->send($u, json_encode($payload));
      }
    }
  }

  protected function process($user, $message) {
    $data = json_decode($message, true);
    if (!$data) {
      $this->stderr("Non-json message received");
      return;
    }

    $type = $data['type'] ?? '';

    switch ($type) {
      case 'auth':
        $token = $data['token'] ?? null;
        if (!$token) {
          $this->send($user, json_encode(['type'=>'auth','ok'=>false,'error'=>'no token']));
          return;
        }

        $payload = false;
        if (function_exists('validarToken')) {
          // espera que validarToken retorne payload (array) ou false
          try {
            $payload = validarToken($token);
          } catch (Exception $e) {
            $payload = false;
          }
        } else {
          // tenta Firebase\JWT
          try {
            if (class_exists('\Firebase\JWT\JWT')) {
              $secret = defined('JWT_SECRET') ? JWT_SECRET  : ($_ENV['JWT_SECRET'] ?? null);
              $decoded = \Firebase\JWT\JWT::decode($token, new Key($secret, 'HS256'));
              // converte objeto -> array
              $payload = json_decode(json_encode($decoded), true);
            }
          } catch (Exception $e) {
            $this->stderr("JWT decode failed: ".$e->getMessage());
            $payload = false;
          }
        }

        if (!$payload) {
          $this->send($user, json_encode(['type'=>'auth','ok'=>false,'error'=>'invalid token']));
          return;
        }

        // marca user como autenticado
        $user->auth = $payload;
        // opcional: normalize fields
        if (isset($payload['id'])) {
          $user->auth['id'] = $payload['id'];
        } elseif (isset($payload['user_id'])) {
          $user->auth['id'] = $payload['user_id'];
        }
        $this->send($user, json_encode(['type'=>'auth','ok'=>true,'user'=>$user->auth]));
        break;

      case 'join':
        // {type:'join', chamado_id: 123}
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
        // opcional: avisar sala
        foreach ($this->users as $u) {
          if ($u !== $user && $u->handshake && isset($u->currentRoom) && $u->currentRoom == $user->currentRoom) {
            $this->send($u, json_encode(['type'=>'peer_joined','user'=>$user->auth,'chamado_id'=>$user->currentRoom]));
          }
        }
        break;

      case 'msg':
        // {type:'msg', chamado_id:123, text:'...'}
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

        // Inserir na tabela 'mensagens' (id, chamado_id, usuario_id, mensagem, criado_em)
        $msgId = null;
        if ($this->db) {
          try {
            $stmt = $this->db->prepare("
              INSERT INTO mensagens (chamado_id, usuario_id, mensagem, criado_em)
              VALUES (:chamado_id, :usuario_id, :mensagem, NOW())
            ");
            $stmt->execute([
              ':chamado_id' => $chamado,
              ':usuario_id' => $sender_id,
              ':mensagem'   => $text
            ]);
            $msgId = $this->db->lastInsertId();
          } catch (Exception $e) {
            $this->stderr("DB insert failed: ".$e->getMessage());
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

        // broadcast só para usuários na mesma sala (chamado_id)
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

  // helper: broadcast excluding sender (não usado aqui, mantido)
  protected function broadcast($msg, $excludeUser = null) {
    foreach ($this->users as $u) {
      if ($u->handshake && $u !== $excludeUser) {
        $this->send($u, $msg);
      }
    }
  }
}


