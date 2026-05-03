<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\AppMeta;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Interactive monthly contribution review and reminder panel for family fund officers.')]
#[AppMeta(prefersBorder: true)]
class FamilyFundReviewApp extends AppResource
{
    /**
     * Handle the app resource request.
     */
    public function handle(Request $request): Response
    {
        return Response::view('mcp.family-fund-review-app', [
            'title' => $this->title(),
        ]);
    }
}
