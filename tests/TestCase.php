<?php

declare(strict_types=1);

namespace Tests;

use AllowDynamicProperties;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use App\Policies\ContributionPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FundAdjustmentPolicy;
use App\Policies\PaymentPolicy;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * @method \Illuminate\Testing\PendingCommand artisan(string $command, array<string, mixed> $parameters = [])
 *
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
 * @property FamilyCategory $employed
 * @property FamilyCategory $familyCategory
 * @property FamilyCategory $student
 * @property FamilyCategory $unemployed
 * @property FamilyMembership $archivedMembership
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
     * @param  class-string  $abstract
     */
    public function mock($abstract, ?Closure $mock = null): MockInterface
    {
        return parent::mock($abstract, $mock);
    }

    /**
     * @param  class-string  $abstract
     */
    public function partialMock($abstract, ?Closure $mock = null): MockInterface
    {
        return parent::partialMock($abstract, $mock);
    }

    /**
     * @param  class-string  $abstract
     */
    public function spy($abstract, ?Closure $mock = null): MockInterface
    {
        return parent::spy($abstract, $mock);
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     * @param  array<string, mixed>  $data
     */
    public function assertDatabaseHas($table, array $data = [], mixed $connection = null): static
    {
        parent::assertDatabaseHas($table, $data, $connection);

        return $this;
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     * @param  array<string, mixed>  $data
     */
    public function assertDatabaseMissing($table, array $data = [], mixed $connection = null): static
    {
        parent::assertDatabaseMissing($table, $data, $connection);

        return $this;
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     */
    public function assertDatabaseCount($table, int $count, mixed $connection = null): static
    {
        parent::assertDatabaseCount($table, $count, $connection);

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
