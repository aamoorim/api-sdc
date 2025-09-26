<?php

require_once __DIR__ . '/users.php';

abstract class WebSocketServer {

    protected $userClass = 'WebSocketUser';
    protected $maxBufferSize;
    protected $master;
    protected $sockets = [];
    protected $users = [];
    protected $heldMessages = [];
    protected $interactive = true;

    function __construct($addr, $port, $bufferLength = 2048) {
        $this->maxBufferSize = $bufferLength;

        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("Failed: socket_create()");
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1) or die("Failed: socket_option()");
        socket_bind($this->master, $addr, $port) or die("Failed: socket_bind()");
        socket_listen($this->master, 20) or die("Failed: socket_listen()");

        $this->sockets['m'] = $this->master;
        $this->stdout("Server started on $addr:$port\n");
    }

    abstract protected function process($user, $message);
    abstract protected function connected($user);
    abstract protected function closed($user);

    protected function connecting($user) { }

    public function stdout($message) {
        if ($this->interactive) echo "$message\n";
    }

    public function stderr($message) {
        if ($this->interactive) echo "$message\n";
    }

    // ----------------------
    // Run loop principal
    // ----------------------
    public function run() {
        while (true) {
            $read = $this->sockets;
            $write = $except = null;
            $this->_tick();

            @socket_select($read, $write, $except, 1);

            foreach ($read as $socket) {
                if ($socket === $this->master) {
                    $client = socket_accept($socket);
                    if ($client < 0) {
                        $this->stderr("Failed: socket_accept()");
                        continue;
                    }
                    $this->connect($client);
                    $this->stdout("Client connected. Socket ID: ".intval($client));
                } else {
                    $numBytes = @socket_recv($socket, $buffer, $this->maxBufferSize, 0);
                    if ($numBytes === false) {
                        $sockErrNo = socket_last_error($socket);
                        $this->stderr("Socket error: ".socket_strerror($sockErrNo));
                        $this->disconnect($socket);
                        continue;
                    }
                    if ($numBytes == 0) {
                        $this->disconnect($socket);
                        continue;
                    }

                    $user = $this->getUserBySocket($socket);
                    if (!$user->handshake) {
                        if (strpos($buffer, "\r\n\r\n") === false) continue;
                        $this->doHandshake($user, $buffer);
                    } else {
                        $this->split_packet($numBytes, $buffer, $user);
                    }
                }
            }
        }
    }

    // ----------------------
    // Conectar usuário
    // ----------------------
    protected function connect($socket) {
        $userId = uniqid('u');
        $user = new $this->userClass($userId, $socket);
        $user->auth = null;
        $user->currentRoom = null;
        $user->handshake = false;
        $user->partialMessage = "";
        $user->handlingPartialPacket = false;
        $user->partialBuffer = "";
        $user->hasSentClose = false;
        $user->sendingContinuous = false;

        $this->users[$userId] = $user;
        $this->sockets[$userId] = $socket;
        $this->connecting($user);
    }

    // ----------------------
    // Desconectar usuário
    // ----------------------
    protected function disconnect($socket, $triggerClosed = true) {
        $user = $this->getUserBySocket($socket);
        if (!$user) return;

        unset($this->users[$user->id], $this->sockets[$user->id]);

        if ($triggerClosed) {
            $this->closed($user);
            socket_close($user->socket);
        }
    }

    // ----------------------
    // Envia mensagem
    // ----------------------
    protected function send($user, $message) {
        if ($user->handshake) {
            $frame = $this->frame($message, $user);
            @socket_write($user->socket, $frame, strlen($frame));
        } else {
            $this->heldMessages[] = ['user'=>$user, 'message'=>$message];
        }
    }

    // ----------------------
    // Busca usuário pelo socket
    // ----------------------
    protected function getUserBySocket($socket) {
        foreach ($this->users as $u) {
            if ($u->socket == $socket) return $u;
        }
        return null;
    }

    // ----------------------
    // JWT seguro
    // ----------------------
    protected function authenticateUser($token) {
        $payload = false;
        if (function_exists('validarToken')) {
            try {
                $payload = validarToken($token);
                if (is_object($payload)) $payload = json_decode(json_encode($payload), true);
            } catch (Exception $e) { $payload = false; }
        } else if (class_exists('\Firebase\JWT\JWT')) {
            try {
                $secret = defined('JWT_SECRET') ? JWT_SECRET : ($_ENV['JWT_SECRET'] ?? null);
                $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
                $payload = json_decode(json_encode($decoded), true);
            } catch (Exception $e) { $payload = false; }
        }
        return $payload;
    }

    // ----------------------
    // Frames websocket
    // ----------------------
    protected function frame($message, $user, $type='text') {
        $b1 = match($type) {
            'text' => 129,
            'binary' => 130,
            'close' => 136,
            'ping' => 137,
            'pong' => 138,
            default => 129
        };
        $len = strlen($message);
        if ($len < 126) $header = chr($b1).chr($len);
        elseif ($len < 65536) $header = chr($b1).chr(126).pack('n', $len);
        else $header = chr($b1).chr(127).pack('J', $len);

        return $header.$message;
    }

    // ----------------------
    // Split de pacotes (múltiplos frames)
    // ----------------------
    protected function split_packet($length, $packet, $user) {
        $payload = $packet; // simplificado para texto puro
        $this->process($user, $payload);
    }
}
