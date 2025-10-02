<?php
// ws/users.php
class WebSocketUser {
    public $id;
    public $socket;
    public $handshake = false;
    public $headers = [];
    public $partialMessage = "";
    public $partialBuffer = "";
    public $handlingPartialPacket = false;
    public $sendingContinuous = false;
    public $hasSentClose = false;

    // App specific
    public $auth = null;        // payload do JWT (array)
    public $current_chamado = null; // id do chamado que o cliente "abriu" no chat

    public function __construct($id, $socket) {
        $this->id = $id;
        $this->socket = $socket;
    }
}
