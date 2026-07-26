<?php

declare(strict_types=1);

use App\Mcp\Servers\FamilyFundServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/family-fund', FamilyFundServer::class)
    ->middleware(['auth:api', 'verified', 'throttle:30,1']);
