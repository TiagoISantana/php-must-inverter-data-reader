<?php

use App\MustInverterDataReader;

$inv = new MustInverterDataReader("/dev/ttyUSB0");

$data = $inv->readAll();

echo json_encode($data, JSON_PRETTY_PRINT);