<?php
// ws/WebSocketServer.php
declare(strict_types=1);

require_once __DIR__ . '/users.php';

abstract class WebSocketServer {

    protected string $userClass = 'WebSocketUser';
    protected int $maxBufferSize;
    protected $master;
    protected array $sockets = [];
    protected array $users = [];
    protected array $heldMessages = [];
    protected bool $interactive = true;
    protected $db; // PDO (Postgres) passado no construtor

    /**
     * @param string $addr
     * @param int $port
     * @param \PDO $pdo
     * @param int $bufferLength
     */
    public function __construct(string $addr, int $port, \PDO $pdo, int $bufferLength = 2048) {
        $this->maxBufferSize = $bufferLength;
        $this->db = $pdo;

        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("Failed: socket_create()");
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1) or die("Failed: socket_option()");
        socket_bind($this->master, $addr, $port) or die("Failed: socket_bind()");
        socket_listen($this->master, 20) or die("Failed: socket_listen()");

        $this->sockets['m'] = $this->master;
        $this->stdout("Server started on {$addr}:{$port}");
    }

    // Métodos que a classe filha deve implementar
    abstract protected function process($user, string $message): void;
    abstract protected function connected($user): void;
    abstract protected function closed($user): void;

    // Chamado logo após new user ser criado (antes do handshake)
    protected function connecting($user): void { }

    public function stdout(string $message): void {
        if ($this->interactive) echo $message . PHP_EOL;
    }

    public function stderr(string $message): void {
        if ($this->interactive) echo $message . PHP_EOL;
    }

    /**
     * Loop principal do servidor (intencionalmente infinito).
     * Static analyzers podem apontar "infinite loop" — é esperado, é um servidor.
     *
     * @noinspection PhpLoopNeverReturnsInspection
     */
    public function run(): void {
        while (true) {
            $read = $this->sockets;
            $write = $except = null;

            // manutenção interna
            $this->_tick();

            // Hook para subclasses
            $this->tick();

            @socket_select($read, $write, $except, 1);

            foreach ($read as $socket) {
                if ($socket === $this->master) {
                    $client = @socket_accept($socket);
                    if ($client === false || $client < 0) {
                        $this->stderr("Failed: socket_accept()");
                        continue;
                    }
                    $this->connect($client);
                    $this->stdout("Client connected. Socket: {$client}");
                } else {
                    $numBytes = @socket_recv($socket, $buffer, $this->maxBufferSize, 0);
                    if ($numBytes === false) {
                        // erro na conexão do socket
                        $sockErrNo = socket_last_error($socket);
                        $this->stderr("Socket error: " . socket_strerror($sockErrNo));
                        $this->disconnect($socket);
                        continue;
                    }

                    if ($numBytes === 0) {
                        // cliente desconectou graciosamente
                        $this->disconnect($socket);
                        continue;
                    }

                    $user = $this->getUserBySocket($socket);
                    if (!$user) {
                        // usuário não encontrado — fecha conexão
                        socket_close($socket);
                        continue;
                    }

                    if (!$user->handshake) {
                        // espera headers HTTP completos (terminador \r\n\r\n)
                        if (strpos($buffer, "\r\n\r\n") === false) continue;
                        $this->doHandshake($user, $buffer);
                    } else {
                        $this->split_packet($numBytes, $buffer, $user);
                    }
                }
            }
        }
    }

    // --- gerenciamento de conexões ----

    protected function connect($socket): void {
        $userId = uniqid('u');
        $user = new $this->userClass($userId, $socket);
        $user->auth = null;
        $user->current_chamado = null;
        $user->handshake = false;
        $user->partialMessage = "";
        $user->partialBuffer = "";
        $user->handlingPartialPacket = false;
        $user->sendingContinuous = false;
        $user->hasSentClose = false;

        $this->users[$userId] = $user;
        $this->sockets[$userId] = $socket;
        $this->connecting($user);
    }

    protected function disconnect($socket, bool $triggerClosed = true): void {
        $user = $this->getUserBySocket($socket);
        if (!$user) return;

        unset($this->users[$user->id]);
        if (array_key_exists($user->id, $this->sockets)) unset($this->sockets[$user->id]);

        if ($triggerClosed) {
            $this->closed($user);
            @socket_close($user->socket);
        } else {
            $frame = $this->frame('', $user, 'close');
            @socket_write($user->socket, $frame, strlen($frame));
        }
    }

    // Envia mensagem (armazena se handshake ainda não feito)
    protected function send($user, string $message): void {
        if ($user->handshake) {
            $frame = $this->frame($message, $user);
            @socket_write($user->socket, $frame, strlen($frame));
        } else {
            $this->heldMessages[] = ['user'=>$user, 'message'=>$message];
        }
    }

    // busca usuário por socket
    protected function getUserBySocket($socket) {
        foreach ($this->users as $u) {
            if ($u->socket === $socket) return $u;
        }
        return null;
    }

    // -------------------------
    // Autenticação JWT helper
    // -------------------------
    protected function authenticateUser(string $token) {
        // tenta função validarToken() se existir no seu config/jwt.php
        if (function_exists('validarToken')) {
            try {
                $payload = validarToken($token);
                if (is_object($payload)) $payload = json_decode(json_encode($payload), true);
                return $payload;
            } catch (\Throwable $e) {
                return false;
            }
        }

        // tenta firebase/jwt se disponível
        if (class_exists('\Firebase\JWT\JWT')) {
            try {
                $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
                if (!$secret) return false;
                $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
                $payload = json_decode(json_encode($decoded), true);
                return $payload;
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    // -------------------------
    // Mensagens de manutenção
    // -------------------------
    protected function _tick(): void {
        // Tenta reenviar mensagens que ficaram em hold (quando handshake não estava pronto)
        foreach ($this->heldMessages as $key => $hm) {
            $found = false;
            foreach ($this->users as $currentUser) {
                if ($hm['user']->socket === $currentUser->socket) {
                    $found = true;
                    if ($currentUser->handshake) {
                        unset($this->heldMessages[$key]);
                        $this->send($currentUser, $hm['message']);
                    }
                }
            }
            if (!$found) {
                unset($this->heldMessages[$key]);
            }
        }
    }

    // Hook que subclasses podem sobrescrever (run chama isso)
    protected function tick(): void { }

    // -------------------------
    // Handshake WebSocket
    // -------------------------
    protected function doHandshake($user, string $buffer): void {
        $magicGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
        $headers = [];
        $lines = preg_split("/\r\n/", $buffer);

        foreach ($lines as $line) {
            if (strpos($line, ":") !== false) {
                [$k, $v] = explode(":", $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            } elseif (stripos($line, "GET ") !== false) {
                if (preg_match("/GET (.*) HTTP/i", $line, $m)) {
                    $headers['get'] = trim($m[1]);
                }
            }
        }

        if (!isset($headers['sec-websocket-key'])) {
            socket_write($user->socket, "HTTP/1.1 400 Bad Request\r\n\r\n");
            $this->disconnect($user->socket);
            return;
        }

        $acceptKey = base64_encode(sha1($headers['sec-websocket-key'] . $magicGUID, true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";

        socket_write($user->socket, $response, strlen($response));
        $user->handshake = true;
        $user->headers = $headers;

        // enviar mensagens que estavam em hold para esse usuário
        foreach ($this->heldMessages as $k => $hm) {
            if ($hm['user']->socket === $user->socket) {
                $this->send($user, $hm['message']);
                unset($this->heldMessages[$k]);
            }
        }

        $this->connected($user);
    }

    // -------------------------
    // Framing / deframing
    // -------------------------
    protected function frame(string $message, $user, string $type = 'text'): string {
        $b1 = match($type) {
            'text' => 129,
            'binary' => 130,
            'close' => 136,
            'ping' => 137,
            'pong' => 138,
            default => 129
        };

        $len = strlen($message);
        if ($len < 126) {
            $header = chr($b1) . chr($len);
        } elseif ($len < 65536) {
            $header = chr($b1) . chr(126) . pack('n', $len);
        } else {
            // 64-bit length
            $header = chr($b1) . chr(127) . pack('J', $len);
        }

        return $header . $message;
    }

    protected function split_packet($length, $packet, $user): void {
        // Simplificado: decodifica único frame (clientes browsers sempre máscara)
        $payload = $this->unmask($packet);
        if ($payload !== false) {
            $this->process($user, $payload);
        }
    }

    /**
     * Decodifica payload recebido do cliente (aplica máscara).
     * Retorna string do payload ou false em erro.
     */
    protected function unmask(string $payload) {
        $len = ord($payload[1]) & 127;
        $offset = 2;

        if ($len === 126) {
            $offset = 4;
            $len = (ord($payload[2]) << 8) + ord($payload[3]);
        } elseif ($len === 127) {
            $offset = 10;
            // 64bit length (apenas suporta até o tamanho da string)
            $len = 0;
            for ($i = 0; $i < 8; $i++) {
                $len = ($len << 8) + ord($payload[2 + $i]);
            }
        }

        $hasMask = (ord($payload[1]) & 128) === 128;
        if ($hasMask) {
            $maskStart = $offset;
            $mask = substr($payload, $maskStart, 4);
            $dataStart = $maskStart + 4;
            $data = substr($payload, $dataStart);
            $text = '';
            for ($i = 0, $l = strlen($data); $i < $l; $i++) {
                $text .= $data[$i] ^ $mask[$i % 4];
            }
            return $text;
        } else {
            // não mascarado (servidor->cliente normalmente não), retorna direto
            return substr($payload, $offset);
        }
    }
}
