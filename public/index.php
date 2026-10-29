<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Support\Bootstrap;

$kernel = Bootstrap::create();
$kernel->handle(Request::fromGlobals())->send();
