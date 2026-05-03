<?php

use App\Mcp\Servers\FamilyFundServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/family-fund', FamilyFundServer::class)
    ->middleware(['auth:sanctum', 'throttle:30,1']);
