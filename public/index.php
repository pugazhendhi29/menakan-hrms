<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Core\Kernel;
use App\Core\Request;

(new Kernel())->handle(Request::fromGlobals())->send();
