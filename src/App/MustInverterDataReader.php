<?php


namespace App;
/**
 *
 */
class MustInverterDataReader
{
    /**
     * @var string
     */
    private string $port;
    /**
     * @var int
     */
    private int $baud;
    /**
     * @var null
     */
    private $fp = null;

    /**
     * @param string $port
     * @param int $baud
     */
    public function __construct(string $port = "/dev/ttyUSB0", int $baud = 19200) {
        $this->port = $port;
        $this->baud = $baud;
    }

    /* -----------------------------------------------
     * SERIAL PORT
     * ----------------------------------------------- */

    /**
     * @return void
     * @throws Exception
     */
    public function open(): void {
        shell_exec("stty -F {$this->port} {$this->baud} cs8 -cstopb -parenb -ixon -ixoff -crtscts raw -echo");

        $this->fp = @fopen($this->port, "c+");
        if (!$this->fp) {
            throw new Exception("Cannot open serial port {$this->port}");
        }

        stream_set_blocking($this->fp, false);
    }

    /**
     * @return void
     */
    public function close(): void {
        if ($this->fp) {
            fclose($this->fp);
            $this->fp = null;
        }
    }

    /**
     * @param string $bytes
     * @return int
     */
    private function write(string $bytes): int {
        return fwrite($this->fp, $bytes);
    }

    /**
     * @param int $length
     * @return string
     */
    private function readBlocking(int $length): string {
        $buffer = "";
        $tries = 0;

        while (strlen($buffer) < $length && $tries < 40) {
            $chunk = fread($this->fp, $length);
            if ($chunk !== false) {
                $buffer .= $chunk;
            }
            usleep(100000);
            $tries++;
        }

        return $buffer;
    }

    /* -----------------------------------------------
     * CRC + Frame Parser
     * ----------------------------------------------- */

    /**
     * @param string $hex
     * @return string
     */
    private function generateCRC(string $hex): string {
        $hex = str_replace(" ", "", $hex);
        if (strlen($hex) % 2 !== 0) {
            $hex .= "0";
        }

        $bytes = hex2bin($hex);

        $max1 = 0xFF;
        $max2 = 0xFF;

        for ($i = 0; $i < strlen($bytes); $i++) {
            $b = ord($bytes[$i]);
            $max1 ^= $b;

            for ($g = 0; $g < 8; $g++) {
                $num2 = $max2;
                $num3 = $max1;
                $max2 >>= 1;
                $max1 >>= 1;

                if (($num2 & 1) === 1) {
                    $max1 |= 0x80;
                }
                if (($num3 & 1) === 1) {
                    $max2 ^= 0xA0;
                    $max1 ^= 0x01;
                }
            }
        }

        return $bytes . chr($max1) . chr($max2);
    }

    /**
     * @param string $data
     * @param int $registers
     * @return array
     * @throws Exception
     */
    private function bytesToArray(string $data, int $registers): array {
        $required = $registers * 2 + 5;
        if (strlen($data) !== $required) {
            throw new Exception("Invalid frame length, expected $required got " . strlen($data));
        }

        $out = array_fill(0, $registers, "");
        $empty = "";
        $num1 = 0;
        $idx = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $byte = ord($data[$i]);

            if ($num1 <= 2 || $num1 >= strlen($data) - 2) {
                $num1++;
                continue;
            }

            if ($num1 % 2 === 1) {
                $empty = sprintf("%02X", $byte);
            } else {
                $out[$idx] = $empty . sprintf("%02X", $byte);
                $empty = "";
                $idx++;
            }

            $num1++;
        }

        return $out;
    }

    /**
     * @param string $command
     * @param int $len
     * @return array|null
     * @throws Exception
     */
    private function readSmallFrame(string $command, int $len): ?array {
        $this->write($command);
        $expected = $len * 2 + 5;

        $loops = 0;
        while ($loops < 40) {
            $data = stream_get_contents($this->fp);

            if ($data !== "" && strlen($data) >= $expected) {
                return $this->bytesToArray(substr($data, 0, $expected), $len);
            }

            usleep(100000);
            $loops++;
        }

        return null;
    }

    /**
     * @param string $command
     * @param int $len
     * @param int $timeoutMs
     * @return array|null
     * @throws Exception
     */
    private function readLargeFrame(string $command, int $len, int $timeoutMs = 600): ?array {
        $this->write($command);

        $expected = $len * 2 + 5;
        $buffer = "";
        $start = microtime(true);

        while (true) {
            $chunk = fread($this->fp, 512);
            if ($chunk !== false && strlen($chunk) > 0) {
                $buffer .= $chunk;
            }

            if (strlen($buffer) >= $expected) {
                return $this->bytesToArray(substr($buffer, 0, $expected), $len);
            }

            if (((microtime(true) - $start) * 1000) > $timeoutMs) {
                return null;
            }

            usleep(20000);
        }
    }

    /* -----------------------------------------------
     * Data Conversion Helpers
     * ----------------------------------------------- */

    /**
     * @param string $hex
     * @return int
     */
    private function twos(string $hex): int {
        $value = hexdec($hex);
        if ($value & (1 << 15)) {
            $value -= (1 << 16);
        }
        return $value;
    }

    /* -----------------------------------------------
     * Command 3 Converter
     * ----------------------------------------------- */

    /**
     * @param array $a
     * @return array|null
     */
    private function decodeCmd3(array $a): ?array {
        if (count($a) !== 21) return null;

        return [
            "ChargerWorkstate" => $this->twos($a[0]),
            "MpptState" => $this->twos($a[1]),
            "ChargingState" => $this->twos($a[2]),
            "PvVoltage" => sprintf("%.2f", $this->twos($a[4]) * 0.1),
            "BatteryVoltage" => sprintf("%.2f", $this->twos($a[5]) * 0.1),
            "ChargerCurrent" => sprintf("%.2f", $this->twos($a[6]) * 0.1),
            "ChargerPower" => $this->twos($a[7]),
            "RadiatorTemperature" => $this->twos($a[8]),
            "ExternalTemperature" => $this->twos($a[9]),
            "BatteryRelay" => $this->twos($a[10]),
            "PvRelay" => $this->twos($a[11]),
            "ErrorMessage" => $this->twos($a[12]),
            "WarningMessage" => $this->twos($a[13]),
            "RatedCurrent" => sprintf("%.2f", $this->twos($a[15]) * 0.1),
        ];
    }

    /* -----------------------------------------------
     * Command 6 Converter (Full)
     * ----------------------------------------------- */

    /**
     * @param array $a
     * @return array|null
     */
    private function decodeCmd6(array $a): ?array {
        if (count($a) !== 74) return null;

        return [
            "WorkState" => $this->twos($a[0]),
            "AcVoltageGrade" => $this->twos($a[1]),
            "RatedPower" => $this->twos($a[2]),
            "InverterBatteryVoltage" => sprintf("%.2f", $this->twos($a[4]) * 0.1),
            "InverterVoltage" => sprintf("%.2f", $this->twos($a[5]) * 0.1),
            "GridVoltage" => sprintf("%.2f", $this->twos($a[6]) * 0.1),
            "BusVoltage" => sprintf("%.2f", $this->twos($a[7]) * 0.1),
            "ControlCurrent" => sprintf("%.2f", $this->twos($a[8]) * 0.1),
            "InverterCurrent" => sprintf("%.2f", $this->twos($a[9]) * 0.1),
            "GridCurrent" => sprintf("%.2f", $this->twos($a[10]) * 0.1),
            "LoadCurrent" => sprintf("%.2f", $this->twos($a[11]) * 0.1),
            "PInverter" => $this->twos($a[12]),
            "PGrid" => $this->twos($a[13]),
            "PLoad" => $this->twos($a[14]),
            "LoadPercent" => $this->twos($a[15]),
            "SInverter" => $this->twos($a[16]),
            "SGrid" => $this->twos($a[17]),
            "Sload" => $this->twos($a[18]),
            "Qinverter" => $this->twos($a[20]),
            "Qgrid" => $this->twos($a[21]),
            "Qload" => $this->twos($a[22]),
            "InverterFrequency" => $this->twos($a[24]) * 0.01,
            "GridFrequency" => sprintf("%.2f", $this->twos($a[25]) * 0.01),
            "InverterMaxNumber" => $a[28],
            "CombineType" => $a[29],
            "InverterNumber" => $a[30],
            "AcRadiatorTemperature" => $this->twos($a[32]),
            "TransformerTemperature" => $this->twos($a[33]),
            "DcRadiatorTemperature" => $this->twos($a[34]),
            "InverterRelayState" => $this->twos($a[36]),
            "GridRelayState" => $this->twos($a[37]),
            "LoadRelayState" => $this->twos($a[38]),
            "N_LineRelayState" => $this->twos($a[39]),
            "DCRelayState" => $this->twos($a[40]),
            "EarthRelayState" => $this->twos($a[41]),
            "AccumulatedChargerPower" => sprintf("%.2f", $this->twos($a[44]) * 1000 + $this->twos($a[45]) * 0.1),
            "AccumulatedDischargerPower" => sprintf("%.2f", $this->twos($a[46]) * 1000 + $this->twos($a[47]) * 0.1),
            "AccumulatedBuyPower" => sprintf("%.2f", $this->twos($a[48]) * 1000 + $this->twos($a[49]) * 0.1),
            "AccumulatedSellPower" => sprintf("%.2f", $this->twos($a[50]) * 1000 + $this->twos($a[51]) * 0.1),
            "AccumulatedLoadPower" => sprintf("%.2f", $this->twos($a[52]) * 1000 + $this->twos($a[53]) * 0.1),
            "AccumulatedSelf_usePower" => sprintf("%.2f", $this->twos($a[54]) * 1000 + $this->twos($a[55]) * 0.1),
            "AccumulatedPV_sellPower" => sprintf("%.2f", $this->twos($a[56]) * 1000 + $this->twos($a[57]) * 0.1),
            "AccumulatedGrid_chargerPower" => sprintf("%.2f", $this->twos($a[58]) * 1000 + $this->twos($a[59]) * 0.1),
            "BattPower" => $this->twos($a[72]),
            "BattCurrent" => $this->twos($a[73])
        ];
    }

    /* -----------------------------------------------
     * PUBLIC API
     * ----------------------------------------------- */

    /**
     * @return array
     * @throws Exception
     */
    public function readAll(): array {
        $this->open();

        $cmd3 = $this->generateCRC("04 03 3B 61 00 15");
        $cmd6 = $this->generateCRC("04 03 62 71 00 4A");

        $data3 = $this->readSmallFrame($cmd3, 21);
        usleep(30000);

        $data6 = $this->readLargeFrame($cmd6, 74);

        $this->close();

        $result = [];

        if ($data3) {
            $result = array_merge($result, $this->decodeCmd3($data3));
        }

        if ($data6) {
            $result = array_merge($result, $this->decodeCmd6($data6));
        }

        return $result;
    }
}