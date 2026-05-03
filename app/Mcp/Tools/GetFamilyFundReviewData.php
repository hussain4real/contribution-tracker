<?php

namespace App\Mcp\Tools;

use App\Mcp\Resources\FamilyFundReviewApp;
use App\Mcp\Tools\Concerns\AuthorizesFamilyFundReview;
use App\Services\FamilyContributionReviewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Ui\Enums\Visibility;

#[Description('Fetches monthly, family-scoped contribution review data for the MCP app.')]
#[RendersApp(resource: FamilyFundReviewApp::class, visibility: [Visibility::App])]
class GetFamilyFundReviewData extends Tool
{
    use AuthorizesFamilyFundReview;

    public function __construct(
        private readonly FamilyContributionReviewService $reviewService,
    ) {}

    public function handle(Request $request): Response
    {
        $user = $this->authorizedUser($request);

        if ($user instanceof Response) {
            return $user;
        }

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2030'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'status' => ['nullable', 'string', 'in:all,paid,partial,unpaid,overdue'],
        ]);

        return Response::json($this->reviewService->monthly(
            user: $user,
            year: (int) ($validated['year'] ?? now()->year),
            month: (int) ($validated['month'] ?? now()->month),
            status: $validated['status'] ?? null,
        ));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer()
                ->min(2020)
                ->max(2030)
                ->description('The contribution year to review. Defaults to the current year.'),
            'month' => $schema->integer()
                ->min(1)
                ->max(12)
                ->description('The contribution month to review. Defaults to the current month.'),
            'status' => $schema->string()
                ->enum(['all', 'paid', 'partial', 'unpaid', 'overdue'])
                ->description('Optional member status filter.'),
        ];
    }
}
