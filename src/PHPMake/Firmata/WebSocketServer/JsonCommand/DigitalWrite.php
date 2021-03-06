<?php
namespace PHPMake\Firmata\WebSocketServer\JsonCommand;
use \PHPMake\Firmata;

class DigitalWrite extends JsonCommandAdapter {

    public function execute(
        $commandName,
        $signature,
        array $arguments,
        Firmata\Device $device,
        \Ratchet\ConnectionInterface $from,
        \Iterator $connections)
    {
        $pin = $arguments[0];
        $level = $arguments[1];
        $device->digitalWrite($pin, $level);
        $this->send($from, $commandName, $signature, null);
    }
}
