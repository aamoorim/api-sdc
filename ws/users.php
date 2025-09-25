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
  public $requestedResource = "";
  // App-specific
  public $auth = null; // will hold user info after authentication

  public function __construct($id, $socket) {
    $this->id = $id;
    $this->socket = $socket;
  }
}
