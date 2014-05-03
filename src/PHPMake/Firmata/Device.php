<?php
namespace PHPMake\Firmata;
use PHPMake\Firmata;
use PHPMake\Firmata\Device;

class Device extends \PHPMake\SerialPort {
    private $_putbackBuffer = array();
    private $_logger;
    private $_loop = false;
    private $_noop = true;
    protected $_firmware;
    protected $_version;
    protected $_pins;
    private $_pinInited = false;
    protected $_capability = null;
    protected $_analogPinObservers = array();
    protected $_digitalPinObservers = array();
    private $_digitalPortReportArray = array();

    public function __construct($deviceName, $baudRate=57600) {
        parent::__construct($deviceName);
        $this->_logger = Firmata::getLogger();
        $this->setBaudRate($baudRate)
                ->setCanonical(false)
                ->setVTime(0)->setVMin(1); // never modify
        $this->_setup();
        $this->_initPins();
    }

    public function waitData(array $byteArray) {
        $buffer = array();
        $length = count($byteArray);
        $index = 0;
        while (true) {
            $t = $this->_getc();
            $c = $byteArray[$index];
            $this->_logger->debug(sprintf('$t: %s, $c: %s', $t, $c));
            if ($c == Firmata::ANY_BYTE || $t == $c) {
                $this->_logger->debug('match');
                $buffer[] = $t;
                if ($index == $length-1) {
                    break;
                } else {
                    ++$index;
                }
            } else {
                $this->_logger->debug('reset');
                $index = 0; // reset
                unset($buffer);
                $buffer = array();
            }
        }

        return $buffer;
    }

    private function _setup() {
        $buffer = $this->waitData(array(
            Firmata::REPORT_VERSION,
            Firmata::ANY_BYTE,
            Firmata::ANY_BYTE,
            Firmata::SYSEX_START,
            Firmata::QUERY_FIRMWARE,
        ));

        array_shift($buffer);
        $majorVersion = array_shift($buffer);
        $minorVersion = array_shift($buffer);
        $this->_version = (object)array(
            'major' => (int)$majorVersion,
            'minor' => (int)$minorVersion);

        array_shift($buffer); // Firmata::SYSEX_START
        array_shift($buffer); // Firmata::QUERY_FIRMWARE
        $this->_getc(); // equal to $majorVersion
        $this->_getc(); // equal to $minorVersion

        $firmwareName = $this->receiveSysEx7bitBytesData();
        $this->_firmware = (object)array(
            'name' => $firmwareName,
            'majorVersion' => (int)$majorVersion,
            'minorVersion' => (int)$minorVersion,
        );
    }

    private function _preReadCapability() {
        $this->_logger->debug(__METHOD__);
        $this->_capability = array();
        $buffer = array();
        $pinCount = 0;

        $c = $buffer[] = $this->_getc(); // Firmata::SYSEX_START
        if ($c != Firmata::SYSEX_START) {
            throw new Exception('unexpected char received');
        }
        $c = $buffer[] = $this->_getc(); // Firmata::RESPONSE_CAPABILITY
        if ($c != Firmata::RESPONSE_CAPABILITY) {
            throw new Exception('unexpected char received');
        }

        $endOfSysExData = false;
        for (;;) {
            for (;;) {
                $buffer[] = $code = $this->_getc();
                if ($code == 0x7F) {
                    ++$pinCount;
                    break;
                } else if ($code == Firmata::SYSEX_END) {
                    $endOfSysExData = true;
                    break;
                }
                $buffer[] = $this->_getc();
            }

            if ($endOfSysExData) {
                break;
            }
        }

        $logstr = '';
        $bufferLength = count($buffer);
        for ($i = $bufferLength-1; $i >= 0; $i--) {
            $c = $buffer[$i];
            $logstr .= sprintf('0x%02X ', $c);
            $this->_putback($c);
        }
        $this->_logger->debug($logstr);

        for ($i = 0; $i < $pinCount; $i++) {
            $this->_capability[] = new Device\PinCapability();
            $this->_pins[] = new Device\Pin($i);
        }
        $this->_logger->debug('pinCount: '.$pinCount);
    }

    private function _processInputSysexCapability() {
        $this->_logger->debug(__METHOD__);
        if (is_null($this->_capability)) {
            $this->_preReadCapability();
        }

        $c = $this->_getc(); // Firmata::SYSEX_START
        if ($c != Firmata::SYSEX_START) {
            throw new Exception('unexpected char received');
        }
        $c = $this->_getc(); // Firmata::RESPONSE_CAPABILITY
        if ($c != Firmata::RESPONSE_CAPABILITY) {
            throw new Exception('unexpected char received');
        }

        foreach ($this->_capability as $pinCapability) {
            for (;;) {
                $code = $this->_getc();
                if ($code == 0x7F) {
                    break;
                }

                $exponentOf2ForResolution = $this->_getc();
                $resolution = pow(2, $exponentOf2ForResolution);
                $pinCapability->setResolution($code, $resolution);
            }

        }

        $c = $this->_getc();
        if ($c != Firmata::SYSEX_END) {
            throw new Exception('unexpected char received');
        }
    }

    private function _processInputSysexPinState() {
        $this->_logger->debug(__METHOD__);
        $this->_getc(); // Firmata::SYSEX_START
        $this->_getc(); // Firmata::RESPONSE_PIN_STATE
        $pinNumber = $this->_getc();
        $mode = $this->_getc();
        if ($mode == Firmata::SYSEX_END) {
            throw new Exception(
                    sprintf('specified pin(%d) does not exist', $pinNumber));
        }
        $pin = $this->getPin($pinNumber);
        $pin->updateMode($mode);
        $state7bitByteArray = array();
        for (;;) {
            $byte = $this->_getc();
            if ($byte == Firmata::SYSEX_END) {
                break;
            }

            $state7bitByteArray[] = $byte;
        }

        $pinState = 0;
        $byteArrayLength = count($state7bitByteArray);
        for ($i = 0; $i < $byteArrayLength; $i++) {
            $byte = $state7bitByteArray[$i];
            $pinState |= ($byte&0x7F)<<(8*$i);
        }
        $pin->updateState($pinState);
    }

    private function _processInputSysexFirmware() {
        $this->_logger->debug(__METHOD__);
        $this->_getc(); // Firmata::SYSEX_START
        $this->_getc(); // Firmata::QUERY_FIRMWARE
        $majorVersion = $this->_getc();
        $minorVersion = $this->_getc();
        $firmwareName = $this->receiveSysEx7bitBytesData();
        $this->_firmware = (object)array(
            'name' => $firmwareName,
            'majorVersion' => (int)$majorVersion,
            'minorVersion' => (int)$minorVersion,
        );
    }

    private function _processInputSysexAnalogMapping() {
        $this->_logger->debug(__METHOD__);
        $this->_getc(); // Firmata::SYSEX_START
        $this->_getc(); // Firmata::RESPONSE_ANALOG_MAPPING
        $pinCount = count($this->_pins);
        for ($i = 0; $i < $pinCount; ++$i) {
            $pin = $this->_pins[$i];
            $pin->setAnalogPinNumber($this->_getc());
        }
        $this->_getc(); // Firmata::SYSEX_END
    }

    private function _processInputSysex() {
        $this->_logger->debug(__METHOD__);
        $s = $this->_getc(); // assume Firmata::SYSEX_START
        $c = $this->_getc();
        $this->_putback($c);
        $this->_putback($s);
        switch ($c) {
            case Firmata::RESPONSE_CAPABILITY:
                $this->_processInputSysexCapability();
                break;
            case Firmata::RESPONSE_PIN_STATE:
                $this->_processInputSysexPinState();
                break;
            case Firmata::QUERY_FIRMWARE:
                $this->_processInputSysexFirmware();
                break;
            case Firmata::RESPONSE_ANALOG_MAPPING:
                $this->_processInputSysexAnalogMapping();
                break;
            default:
                throw new Exception(sprintf('unknown sysex command(0x%02X) detected', $c));
        }
    }

    private function _processInputVersion() {
        $this->_logger->debug(__METHOD__);
        $c = $this->_getc(); // assume Firmata::REPORT_VERSION
        $majorVersion = $this->_getc();
        $minorVersion = $this->_getc();
        $this->_version = (object)array(
            'major' => (int)$majorVersion,
            'minor' => (int)$minorVersion);
    }

    private function _processInput() {
        $this->_logger->debug(__METHOD__);
        $c = $this->_getc();
        switch ($c) {
            case Firmata::SYSEX_START:
                $this->_putback($c);
                $this->_processInputSysex();
                break;
            case Firmata::REPORT_VERSION:
                $this->_putback($c);
                $this->_processInputVersion();
                break;
            case Firmata::MESSAGE_ANALOG:
                $this->_putback($c);
                break;
            default:
                throw new Exception(sprintf(
                        'unknown command(0x%02X) detected', $c));
        }
    }

    public function addAnalogPinObserver(Device\PinObserver $observer) {
        $this->_analogPinObservers[] = $observer;
    }

    public function removeAnalogPinObserver(Device\PinObserver $observer) {
        self::_removePinObserver($this->_analogPinObservers, $observer);
    }

    public function addDigitalPinObserver(Device\PinObserver $observer) {
        $this->_digitalPinObservers[] = $observer;
    }

    public function removeDigitalPinObserver(Device\PinObserver $observer) {
        self::_removePinObserver($this->_digitalPinObservers, $observer);
    }

    private static function _removePinObserver(array $observers, Device\PinObserver $observer) {
        $index = null;
        foreach ($observers as $_index => $_observer) {
            if ($_observer === $observer) {
                $index = $_index;
                break;
            }
        }

        if (!is_null($index)) {
            unset($observers[$index]);
        }
    }

    private function _processCheckDigital() {
        $this->_logger->debug(__METHOD__);
        $command = $this->_getc(); // assume Firmata::MESSAGE_DIGITAL
        $lsb = $this->_getc();
        $msb = $this->_getc();

        $portNumber = $command&((~Firmata::MESSAGE_DIGITAL)&0xFF);
        $report = $this->_getDigitalPortReport($portNumber);
        $changed = $report->setValue($lsb, $msb);
        foreach ($this->_digitalPinObservers as $observer) {
            foreach ($changed as $pinNumber => $state) {
                $pin = $this->getPin($pinNumber);
                $pin->updateInputState($state);
                $observer->notify($this, $pin, $state);
            }
        }
    }

    private function _getDigitalPortReport($portNumber) {
        if (!array_key_exists($portNumber, $this->_digitalPortReportArray)) {
            $this->_digitalPortReportArray[$portNumber]
                    = new Device\DigitalPortReport($portNumber);
        }

        return $this->_digitalPortReportArray[$portNumber];
    }

    private function _processAnalogReport() {
        if (!$this->_pinInited) {
            return;
        }
        $this->_logger->debug(__METHOD__);
        $c = $this->_getc();
        $this->_logger->debug('message is analog');
        $analogPinNumber = $c & 0xF;
        $value = $this->receive7bitBytesData();
        foreach ($this->_analogPinObservers as $observer) {
            $observer->notify($this, $this->getPinByAnalogPinNumber($analogPinNumber), $value);
        }
    }

    private function _eval() {
        $this->_logger->debug(__METHOD__);
        $this->_noop = false;
        $c = $this->_getc();
        $this->_putback($c);
        switch ($c) {
        case Firmata::SYSEX_START:
            $this->_processInputSysex();
            break;
        case Firmata::REPORT_VERSION:
            $this->_processInputVersion();
            break;
        default:
            if (($c>>4) == 0xE /* 0xE equal to (Firmata::MESSAGE_ANALOG>>4) */) {
                $this->_processAnalogReport();
            } else if (($c>>4) == 0x9 /* 0x9 equal to (Firmata::MESSAGE_DIGITAL>>4) */) {
                $this->_processCheckDigital();
            } else {
                throw new Exception(sprintf('stream got unknown char(0x%02X)', $c));
            }
        }
    }

    public function dumpBuffer() {
        $data = $this->_putbackBuffer;
        $colLimit = 15;
        $col = 0;
        $length = count($data);
        for ($i=0; $i < $length; $i++) {
            printf('%02X ', $data[1]);
            if ($col==$colLimit) {
                $col=0;
                print PHP_EOL;
            } else {
                $col++;
            }
        }
        print PHP_EOL;
    }

    private function _getc() {
        if (count($this->_putbackBuffer) > 0) {
            return array_shift($this->_putbackBuffer);
        }

        $d = $this->read(1024);
        $length = strlen($d);
        for ($i = 0; $i < $length; $i++) {
            $_c = unpack('C*', substr($d, $i, 1));
            foreach ($_c as $index => $c) {
                array_push($this->_putbackBuffer, $c);
            }
        }

        return $this->_getc();
    }

    private function _putback($c) {
        array_unshift($this->_putbackBuffer, $c);
    }

    public function stop() {
        $this->_loop = false;
    }

    public function run(Firmata\LoopDelegate $delegate) {
        $this->_logger->debug(__METHOD__);
        $this->_loop = true;
        $previous = 0;
        $baseInterval = self::getDeviceLoopMinIntervalInMicroseconds();
        $interval = $delegate->getInterval();
        while ($this->_loop) {
            $current = microtime(true);
            $elapsed = $current - $previous;
            if ($elapsed >= $interval) {
                $delegate->tick($this);
                $this->noop();
            }
            usleep($baseInterval);
        }
    }

    public static function getDeviceLoopMinIntervalInMicroseconds() {
        return 5000;
    }

    private function _drain() {
        $this->_eval();
    }

    public function noop() {
        if ($this->_noop) {
            $this->_drain();
        }
        $this->_noop = true;
    }

    private function _initCapability() {
        $this->_logger->debug(__METHOD__);
        $this->write(pack('CCC',
                Firmata::SYSEX_START,
                Firmata::QUERY_CAPABILITY,
                Firmata::SYSEX_END));
        $this->_eval();
    }

    private function _analogMapping() {
        $this->_logger->debug(__METHOD__);
        $this->write(pack('CCC',
                Firmata::SYSEX_START,
                Firmata::QUERY_ANALOG_MAPPING,
                Firmata::SYSEX_END));
        $this->_eval();
    }

    private function _initPins() {
        $this->_logger->debug(__METHOD__);
        $this->_pins = array();
        $this->_initCapability();
        $this->_analogMapping();
        $totalPins = count($this->_capability);
        for ($i = 0; $i < $totalPins; $i++) {
            $pin = $this->_pins[$i];
            $pin->setCapability($this->_capability[$i]);
            $this->updatePin($pin);
            if ($pin->getAnalogPinNumber() != 0x7F) {
                $this->reportAnalogPin($pin);
            }
        }
        $this->write(pack('CCCCC',
            Firmata::SYSEX_START,
            Firmata::SAMPLING_INTERVAL,
            0x32,
            0x00,
            Firmata::SYSEX_END));
        $this->_pinInited = true;
    }

    public function updatePin($pin) {
        $this->_logger->debug(__METHOD__);
        $pinNumber = $this->_pinNumber($pin);
        $this->write(pack('CCCC',
            Firmata::SYSEX_START,
            Firmata::QUERY_PIN_STATE,
            $pinNumber,
            Firmata::SYSEX_END));
        $this->_eval();
    }

    private function _pinNumber($pin) {
        if ($pin instanceof Device\Pin) {
            $pinNumber = $pin->getNumber();
        } else {
            $pinNumber = $pin;
        }

        if ($pinNumber >= count($this->_capability)) {
            throw new Device\Exception(
                    sprintf('specified pin(%d) does not exist', $pinNumber));
        }

        return $pinNumber;
    }

    public function getCapabilities() {
        return $this->_capability;
    }

    public function getCapability($pin) {
        $pinNumber = $this->_pinNumber($pin);
        return $this->_capability[$pinNumber];
    }

    public function getPin($pin) {
        $pinNumber = $this->_pinNumber($pin);
        return $this->_pins[$pinNumber];
    }

    public function getPinByAnalogPinNumber($analogPinNumber) {
        if ($analogPinNumber < 0 || $analogPinNumber > 15) {
            throw new Exception(
                'range error. analog pin number should be ' .
                'equal or greater than 0 and equal or less than 15.');
        }
        foreach ($this->_pins as $pin) {
            if ($pin->getAnalogPinNumber() == $analogPinNumber) {
                return $pin;
            }
        }

        return null;
    }

    public function setPinMode($pin, $mode) {
        $pin = $this->getPin($pin);
        $this->write(pack('CCC',
            Firmata::SET_PIN_MODE,
            $pin->getNumber(),
            $mode
        ));
        $this->updatePin($pin);
    }

    public function reportDigitalPort($portNumber, $report=true) {
        $command = Firmata::REPORT_DIGITAL | $portNumber;
        $this->write(pack('CC', $command, $report?1:0));
    }

    public function reportDigitalPin($pin, $report=true) {
        $pin = $this->getPin($pin);
        $this->setPinMode($pin, Firmata::INPUT);
        $this->reportDigitalPort(
            Device::portNumberForPin($pin->getNumber()), $report);
    }

    public function reportAnalogPin($pin, $report=true) {
        $pin = $this->getPin($pin);
        if (($analogPinNumber = $pin->getAnalogPinNumber()) != 0x7F) {
            $this->setPinMode($pin, Firmata::ANALOG);
            $command = Firmata::REPORT_ANALOG | $analogPinNumber;
            $this->write(pack('CC', $command, $report?1:0));
        } else {
            throw new Exception(
                sprintf('pin(%d) does not support analog', $pin->getNumber()));
        }
    }

    public function digitalWrite($pin, $value) {
        $this->_logger->debug(__METHOD__);
        $pinNumber = $this->_pinNumber($pin);
        $value = $value ? Firmata::HIGH : Firmata::LOW;
        $portNumber = self::portNumberForPin($pinNumber);
        $command = Firmata::MESSAGE_DIGITAL | $portNumber;
        $firstByte = $this->_makeFirstByteForDigitalWrite($pinNumber, $value);
        $secondByte = $this->_makeSecondByteForDigitalWrite($pinNumber, $value);
        $this->write(pack('CCC', $command, $firstByte, $secondByte));
        //$this->updatePin($pinNumber);
        $this->_pins[$pinNumber]->updateState($value?1:0);
        $this->_drain();
    }

    public function analogWrite($pin, $value) {
        $this->_logger->debug(__METHOD__);
        $pinNumber = $this->_pinNumber($pin);
        $v = $value;
        $this->write(pack('CCC',
            Firmata::SYSEX_START,
            Firmata::EXTENDED_ANALOG,
            $pinNumber));
        do {
            $this->write(pack('C', $v & 0x7F));
            $v = $v >> 7;
        } while ($v > 0);
        $this->write(pack('C', Firmata::SYSEX_END));

        $this->_pins[$pinNumber]->updateInputState($value);
        $this->_drain();
    }

    public function _makeFirstByteForDigitalWrite($pinNumber, $value) {
        $currentFirstByteState = 0;
        $pinLocationInPort = self::pinLocationInPort($pinNumber);
        $portNumber = self::portNumberForPin($pinNumber);
        $firstPinNumberInPort = $portNumber * 8;
        $limit = 7;
        for ($currentPinNumber = $firstPinNumberInPort, $i = 0; $i <= $limit; $currentPinNumber++, $i++) {
            if ($pinNumber == $currentPinNumber) {
                $pinDigitalState = $value ? 1 : 0;
            } else {
                $pinDigitalState
                        = $this->_pins[$currentPinNumber]->getState() ? 1 : 0;
            }

            $currentFirstByteState |= $pinDigitalState<<$i;
        }

        return ($currentFirstByteState) & 0x7F;
    }

    public function _makeSecondByteForDigitalWrite($pinNumber, $value) {
        $portNumber = self::portNumberForPin($pinNumber);
        $firstPinNumberInPort = (($portNumber + 1) * 8) - 1;
        $currentSecondByteState
                    = $this->_pins[$firstPinNumberInPort]->getState() ? 1 : 0;

        if (($pinNumber%8)==7) {
            $currentSecondByteState = $value ? 1 : 0;
        }

        return $currentSecondByteState;
    }

    private function _updatePinStateInPort($portNumber) {
        $firstPinNumberInPort = $portNumber * 8;
        $limit = 8;
        for (
                $currentPinNumber = $firstPinNumberInPort, $i = 0;
                $i < $limit;
                $i++, $currentPinNumber++)
        {
            $this->updatePin($currentPinNumber);
        }
    }

    public static function pinLocationInPort($pinNumber) {
        return $pinNumber%8;
    }

    public static function portNumberForPin($pinNumber) {
        return floor($pinNumber/8);
    }

    public static function pinNumber($pinLocationInPort, $portNumber) {
        return $portNumber*8 + $pinLocationInPort;
    }

    public function queryFirmware() {
        $this->write(pack('CCC',
                Firmata::SYSEX_START,
                Firmata::QUERY_FIRMWARE,
                Firmata::SYSEX_END));
        $this->_eval();
        return $this->_firmware;
    }

    public function getFirmware() {
        return $this->_firmware;
    }

    public function queryVersion() {
        $this->write(pack('C',
            Firmata::REPORT_VERSION));
        $this->_eval();
        return $this->_version;
    }

    public function getVersion() {
        return $this->_version;
    }

    public function receive7bitBytesData() {
        $lsb = $this->_getc() & 0x7F;
        $msb = $this->_getc() & 0x7F;

        return $msb<<7 | $lsb;
    }

    public function receiveSysEx7bitBytesData() {
        $data7bitByteArray = array();
        while (($c=$this->_getc()) != Firmata::SYSEX_END) {
            $data7bitByteArray[] = $c;
        }

        return self::dataWith7bitBytesArray($data7bitByteArray);
    }

    public static function dataWith7bitBytesArray(array $data7bitByteArray) {
        $data = '';
        $length = count($data7bitByteArray);
        if (($length%2) != 0) {
            throw new Exception(sprintf(
                'array length(%d) is invalid. length must be multiple of 2.',
                $length));
        }

        for ($i=0; $i<$length-1; $i+=2) {
            $firstValue = $data7bitByteArray[$i] & 0x7F;
            $secondValue = ($data7bitByteArray[$i+1] & 0x7F)<<7;

            $data .= pack('C', $firstValue|$secondValue);
        }

        return $data;
    }
}
