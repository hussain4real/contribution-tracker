<?php

use App\Mcp\Servers\FamilyFundServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/family-fund', FamilyFundServer::class)
    ->middleware(['auth:api', 'verified', 'throttle:30,1']);
