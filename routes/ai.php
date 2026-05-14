<?php

use App\Mcp\Servers\OperationsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('operations', OperationsServer::class);
