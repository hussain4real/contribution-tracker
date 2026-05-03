<?php

namespace App\Mcp\Tools;

use App\Mcp\Resources\FamilyFundReviewApp;
use App\Mcp\Tools\Concerns\AuthorizesFamilyFundReview;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tool;

#[Description('Opens an interactive family fund review dashboard for the current month.')]
#[RendersApp(resource: FamilyFundReviewApp::class)]
class OpenFamilyFundReview extends Tool
{
    use AuthorizesFamilyFundReview;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['_meta']['openai/outputTemplate'] = (new FamilyFundReviewApp)->uri();

        return $tool;
    }

    public function handle(Request $request): Response
    {
        $user = $this->authorizedUser($request);

        if ($user instanceof Response) {
            return $user;
        }

        return Response::text('Family fund review loaded.');
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
