<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\FamilyFundReviewApp;
use App\Mcp\Tools\GetFamilyFundReviewData;
use App\Mcp\Tools\OpenFamilyFundReview;
use App\Mcp\Tools\SendFamilyFundReviewReminders;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Family Fund Server')]
#[Version('1.0.0')]
#[Instructions('Provides authenticated, family-scoped contribution review and reminder workflows for admins and financial secretaries.')]
class FamilyFundServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        OpenFamilyFundReview::class,
        GetFamilyFundReviewData::class,
        SendFamilyFundReviewReminders::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        FamilyFundReviewApp::class,
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
    ];
}
