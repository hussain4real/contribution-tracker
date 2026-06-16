<?php

declare(strict_types=1);

namespace Tests;

use AllowDynamicProperties;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use App\Policies\ContributionPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FundAdjustmentPolicy;
use App\Policies\PaymentPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @property User $admin
 * @property User $archivedMember
 * @property User $employedMember
 * @property User $financialSecretary
 * @property User $member
 * @property User $otherMember
 * @property User $outsider
 * @property User $payingMember
 * @property User $recorder
 * @property User $studentMember
 * @property User $targetMember
 * @property User $user
 * @property Family $family
 * @property Family $otherFamily
 * @property Contribution $contribution
 * @property Contribution $employedContribution
 * @property Contribution $memberContribution
 * @property Contribution $otherContribution
 * @property Contribution $studentContribution
 * @property Expense $expense
 * @property FundAdjustment $fundAdjustment
 * @property Payment $otherPayment
 * @property Payment $payment
 * @property ContributionPolicy|ExpensePolicy|FundAdjustmentPolicy|PaymentPolicy $policy
 */
#[AllowDynamicProperties]
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults([
            'current_family' => 'test-family',
            'family' => 'test-family',
        ]);
    }

    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        if ($user instanceof User) {
            $family = $user->currentFamily ?? $user->family ?? $user->families()->first();

            if ($family instanceof Family) {
                URL::defaults([
                    'current_family' => $family->slug,
                    'family' => $family->slug,
                ]);
            }
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, string>  $server
     * @return TestResponse<Response>
     */
    public function call(
        $method,
        $uri,
        $parameters = [],
        $cookies = [],
        $files = [],
        $server = [],
        $content = null,
    ): TestResponse {
        if (is_string($uri)) {
            $uri = $this->withCurrentFamilyPrefix($uri);
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function withCurrentFamilyPrefix(string $uri): string
    {
        if (! str_starts_with($uri, '/')) {
            return $uri;
        }

        $path = parse_url($uri, PHP_URL_PATH);

        if (! is_string($path) || $path === '/') {
            return $uri;
        }

        $firstSegment = explode('/', trim($path, '/'))[0];

        if (! in_array($firstSegment, $this->legacyTenantTestSegments(), true)) {
            return $uri;
        }

        $family = auth()->user() instanceof User
            ? (auth()->user()->currentFamily ?? auth()->user()->family)
            : null;

        if (! $family instanceof Family || str_starts_with($path, "/{$family->slug}/")) {
            return $uri;
        }

        $query = parse_url($uri, PHP_URL_QUERY);

        return "/{$family->slug}{$path}".(is_string($query) ? "?{$query}" : '');
    }

    /**
     * @return list<string>
     */
    private function legacyTenantTestSegments(): array
    {
        return [
            'ai',
            'changelog',
            'contributions',
            'dashboard',
            'expenses',
            'family',
            'fund-adjustments',
            'inbox',
            'members',
            'notifications',
            'pay',
            'payments',
            'reports',
            'subscription',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderTestViewWithoutVite(string $view, array $data = []): string
    {
        $this->withoutVite();

        return (string) $this->view($view, $data);
    }
}
