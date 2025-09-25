<?php

use Firebase\JWT\Key;
// ws/ws_server.php
set_time_limit(0);
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/../config/db.php';   // sua conexão PDO (ajuste caminho)
require_once __DIR__ . '/../config/jwt.php';  // para verificar token (ajuste)

require_once __DIR__ . '/WebSocketServer.php'; // coloque a classe abstrata aqui (separada)

class ChatServer extends WebSocketServer {

  protected $userClass = 'WebSocketUser';
  protected $db;

  public function __construct($addr, $port, $bufferLength = 2048) {
    parent::__construct($addr, $port, $bufferLength);

    // inicializa PDO via seu config/db.php
    // ASSUMO que config/db.php tem algo como $pdo = new PDO(...)
    // Caso seu arquivo exponha outra variável, ajuste abaixo.
    global $pdo;
    if (!isset($pdo)) {
      // fallback: criar PDO aqui (ajuste dsn/user/pass)
      $this->stderr("Aviso: \$pdo não encontrado via config/db.php. Ajuste o include.\n");
    }
    $this->db = $pdo ?? null;
  }

  protected function connected($user) {
    $this->stdout("handshake complete for {$user->id}");
    // nada a fazer até o cliente autenticar
    // recomendamos que o cliente envie um JSON de autenticação assim que conectar
  }

  protected function closed($user) {
    $this->stdout("Connection closed: {$user->id}");
    // broadcast que saiu (opcional)
    $msg = json_encode(['type'=>'left','user'=> $user->auth ?? null ]);
    foreach ($this->users as $u) {
      if ($u->handshake && $u !== $user) $this->send($u, $msg);
    }
  }

  protected function process($user, $message) {
    // Esperamos mensagens JSON com formato: { type: 'auth'|'msg'|'join', ... }
    $data = json_decode($message, true);
    if (!$data) {
      $this->stderr("Non-json message received");
      return;
    }

    switch ($data['type'] ?? '') {
      case 'auth':
        // cliente envia token: {type:'auth', token:'JWT...'}
        $token = $data['token'] ?? null;
        if (!$token) {
          $this->send($user, json_encode(['type'=>'auth','ok'=>false,'error'=>'no token']));
          return;
        }
        // Verifique token com sua função (ajuste conforme seu jwt.php)
        // suposição: existe função verify_jwt($token) que retorna payload ou false
        $payload = false;
        if (function_exists('validarToken')) {
          $payload = validarToken($token);
        } else {
          // tente usar Firebase\JWT se instalado
          try {
            if (class_exists('\Firebase\JWT\JWT')) {
              $secret = defined('JWT_SECRET') ? $_ENV['JWT_SECRET'] : null;
              $payload = \Firebase\JWT\JWT::decode($token, new Key($secret, 'HS256'));
            }
          } catch (Exception $e) {
            $this->stderr("JWT decode failed: ".$e->getMessage());
            $payload = false;
          }
        }
        if (!$payload) {
          $this->send($user, json_encode(['type'=>'auth','ok'=>false,'error'=>'invalid token']));
          // opcional: desconectar
          //$this->disconnect($user->socket);
          return;
        }
        // marque user como autenticado
        $user->auth = (array)$payload; // adaptar conforme payload real
        $this->send($user, json_encode(['type'=>'auth','ok'=>true,'user'=>$user->auth]));
        // announce to others
        $this->broadcast(json_encode(['type'=>'joined','user'=>$user->auth]), $user);
        break;

      case 'msg':
        // {type:'msg', room:'room1', text:'hello'}
        if (!$user->auth) {
          $this->send($user, json_encode(['type'=>'error','error'=>'not authenticated']));
          return;
        }
        $room = $data['room'] ?? 'default';
        $text = $data['text'] ?? '';
        $sender_id = $user->auth['id'] ?? null;
        $sender_name = $user->auth['name'] ?? ($user->auth['username'] ?? null);

        // salvar no banco (se PDO disponível)
        if ($this->db) {
          $stmt = $this->db->prepare("INSERT INTO chat_messages (room,sender_id,sender_name,message) VALUES (:room,:sid,:sname,:msg)");
          $stmt->execute([
            ':room'=>$room,
            ':sid'=>$sender_id,
            ':sname'=>$sender_name,
            ':msg'=>$text
          ]);
          $msgId = $this->db->lastInsertId();
        } else {
          $msgId = null;
        }

        $out = [
          'type'=>'msg',
          'id'=>$msgId,
          'room'=>$room,
          'sender_id'=>$sender_id,
          'sender_name'=>$sender_name,
          'text'=>$text,
          'created_at'=>date('c')
        ];
        // broadcast to all in same room
        foreach ($this->users as $u) {
          if ($u->handshake && $u->auth) {
            // you could implement $u->currentRoom to filter by room
            $this->send($u, json_encode($out));
          }
        }
        break;

      default:
        $this->send($user, json_encode(['type'=>'error','error'=>'unknown type']));
    }
  }

  // helper broadcast excluding sender
  protected function broadcast($msg, $excludeUser = null) {
    foreach ($this->users as $u) {
      if ($u->handshake && $u !== $excludeUser) {
        $this->send($u, $msg);
      }
    }
  }

}

// --- start server ---
$addr = '0.0.0.0';
$port = 9000;
$server = new ChatServer($addr, $port);
$server->run();
