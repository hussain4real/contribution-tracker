<?php

declare(strict_types=1);

use App\Actions\ScoreFamilyPaymentRisk;
use App\Ai\Agents\FamilyAssistant;
use App\Enums\PaymentRiskAdvisoryBand;
use App\Features\PredictiveAnalytics;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\PaymentRiskModelVersion;
use App\Models\PaymentRiskPrediction;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\PlatformFeatureRegistry;
use App\Support\PlatformPlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

function enablePredictiveAnalyticsGlobally(): void
{
    DB::table('features')->insert([
        'name' => PredictiveAnalytics::class,
        'scope' => '',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Feature::flushCache();
}

/** @param array<model-property<Family>, mixed> $attributes */
function paymentRiskReportsFamily(array $attributes = []): Family
{
    $plan = PlatformPlan::query()->firstOrCreate([
        'slug' => 'payment-risk-reports',
    ], [
        'name' => 'Payment Risk Reports',
        'price' => 7500,
        'max_members' => 75,
        'features' => [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::Reports,
        ],
        'is_active' => true,
        'sort_order' => 98,
    ]);

    return Family::factory()->create([
        'platform_plan_id' => $plan->id,
        'subscription_status' => 'active',
        ...$attributes,
    ]);
}

it('registers and shares the predictive analytics Pennant flag', function () {
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    Feature::for($admin)->activate(PredictiveAnalytics::class);

    expect(PlatformFeatureRegistry::options())->toHaveKey('predictive-analytics')
        ->and(PlatformFeatureRegistry::resolve('predictive-analytics')['class'])->toBe(PredictiveAnalytics::class);

    $this->actingAs($admin)
        ->get(route('dashboard', ['current_family' => $family->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('featureFlags.predictive_analytics', true));
});

it('allows admins and financial secretaries to view the honest unavailable state', function (string $roleState) {
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $user = match ($roleState) {
        'admin' => User::factory()->admin()->create(['family_id' => $family->id]),
        'financialSecretary' => User::factory()->financialSecretary()->create(['family_id' => $family->id]),
        default => throw new InvalidArgumentException("Unsupported payment-risk officer state [{$roleState}]."),
    };

    $this->actingAs($user)
        ->get(route('payment-risk.index', ['current_family' => $family->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PaymentRisk/Index')
            ->where('readiness.status', 'unavailable')
            ->where('readiness.can_refresh', false)
            ->where('model', null)
            ->where('predictions', [])
            ->where('summary.total', 0));
})->with(['admin', 'financialSecretary']);

it('forbids members from reading or refreshing predictions', function () {
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $member = User::factory()->member()->create(['family_id' => $family->id]);

    $this->actingAs($member)
        ->get(route('payment-risk.index', ['current_family' => $family->slug]))
        ->assertForbidden();
    $this->actingAs($member)
        ->post(route('payment-risk.refresh', ['current_family' => $family->slug]), ['period' => now()->format('Y-m')])
        ->assertForbidden();
});

it('redirects officers when the Pennant flag is inactive', function () {
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->get(route('payment-risk.index', ['current_family' => $family->slug]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning');
});

it('requires the existing Reports subscription entitlement', function () {
    enablePredictiveAnalyticsGlobally();
    $plan = PlatformPlan::query()->create([
        'name' => 'No Reports',
        'slug' => 'no-reports',
        'price' => 0,
        'max_members' => 10,
        'features' => [PlatformPlanCatalog::BasicContributions],
        'is_active' => true,
        'sort_order' => 99,
    ]);
    $family = Family::factory()->create(['platform_plan_id' => $plan->id]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->get(route('payment-risk.index', ['current_family' => $family->slug]))
        ->assertRedirect(route('subscription.index', ['current_family' => $family->slug]))
        ->assertSessionHas('error');
});

it('fails closed when no subscription plan can be resolved', function () {
    enablePredictiveAnalyticsGlobally();
    $family = Family::factory()->create(['platform_plan_id' => null]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->get(route('payment-risk.index', ['current_family' => $family->slug]))
        ->assertRedirect(route('subscription.index', ['current_family' => $family->slug]))
        ->assertSessionHas('error', 'This feature is not available on your current plan. Please upgrade.');
});

it('requires authentication and validates period filters', function () {
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->get(route('payment-risk.index', ['current_family' => $family->slug]))
        ->assertRedirect();

    $this->actingAs($admin)
        ->get(route('payment-risk.index', ['current_family' => $family->slug, 'period' => '2026-13']))
        ->assertSessionHasErrors('period');
    $this->actingAs($admin)
        ->post(route('payment-risk.refresh', ['current_family' => $family->slug]), ['period' => 'bad'])
        ->assertSessionHasErrors('period');
});

it('does not expose payment-risk predictions through the AI assistant tool registry', function () {
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $tools = collect((new FamilyAssistant($admin))->tools())
        ->map(fn (object $tool): string => $tool::class)
        ->all();

    expect(implode(' ', $tools))->not->toContain('PaymentRisk')
        ->and(implode(' ', $tools))->not->toContain('Predictive');
});

it('renders only the current family populated predictions and model metadata', function () {
    Storage::fake('local');
    Storage::fake('public');
    installActiveTestPaymentRiskModel();
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    createMatureRiskHistory($family, $member, 6);
    scoreTestContribution($family, $member, $period);
    app(ScoreFamilyPaymentRisk::class)->handle($family, $period->year, $period->month);

    $this->actingAs($admin)
        ->get(route('payment-risk.index', [
            'current_family' => $family->slug,
            'period' => $period->format('Y-m'),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'ready')
            ->where('model.version', 'test-model-v1')
            ->where('model.prevalence', 0.4)
            ->has('predictions', 1)
            ->where('predictions.0.member_name', $member->name)
            ->where('predictions.0.history_tier', 'experimental')
            ->where('summary.total', 1));
});

it('filters predictions by advisory band and recomputes the visible summary', function () {
    Storage::fake('local');
    installActiveTestPaymentRiskModel();
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $members = User::factory()->member()->count(2)->create(['family_id' => $family->id]);
    $priorityMember = $members->firstOrFail();
    $model = PaymentRiskModelVersion::query()->where('is_active', true)->sole();
    $period = now()->toImmutable()->startOfMonth();

    foreach ($members as $index => $member) {
        $contribution = scoreTestContribution($family, $member, $period);
        $membership = FamilyMembership::query()
            ->where('family_id', $family->id)
            ->where('user_id', $member->id)
            ->firstOrFail();
        PaymentRiskPrediction::factory()->create([
            'family_id' => $family->id,
            'family_membership_id' => $membership->id,
            'contribution_id' => $contribution->id,
            'payment_risk_model_version_id' => $model->id,
            'cutoff_at' => $contribution->created_at,
            'probability' => $index === 0 ? 0.8 : 0.2,
            'advisory_band' => $index === 0
                ? PaymentRiskAdvisoryBand::PriorityReview
                : PaymentRiskAdvisoryBand::RoutineReview,
        ]);
    }

    $this->actingAs($admin)
        ->get(route('payment-risk.index', [
            'current_family' => $family->slug,
            'period' => $period->format('Y-m'),
            'band' => PaymentRiskAdvisoryBand::PriorityReview->value,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.band', PaymentRiskAdvisoryBand::PriorityReview->value)
            ->has('predictions', 1)
            ->where('predictions.0.member_name', $priorityMember->name)
            ->where('summary.total', 1)
            ->where('summary.priority', 1)
            ->where('summary.routine', 0));
});

it('renders predictions only for the exact eligible model returned by readiness', function () {
    Storage::fake('local');
    installActiveTestPaymentRiskModel();
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $eligibleModel = PaymentRiskModelVersion::query()->where('is_active', true)->sole();
    $ineligibleModel = PaymentRiskModelVersion::factory()->create([
        'is_active' => false,
        'activation_eligible' => false,
    ]);
    $period = now()->toImmutable()->startOfMonth();
    $eligibleMember = User::factory()->member()->create([
        'family_id' => $family->id,
        'name' => 'Eligible Model Member',
    ]);
    $ineligibleMember = User::factory()->member()->create([
        'family_id' => $family->id,
        'name' => 'Ineligible Model Member',
    ]);

    foreach ([
        [$eligibleMember, $eligibleModel],
        [$ineligibleMember, $ineligibleModel],
    ] as [$member, $model]) {
        $contribution = scoreTestContribution($family, $member, $period);
        $membership = FamilyMembership::query()
            ->where('family_id', $family->id)
            ->where('user_id', $member->id)
            ->firstOrFail();
        PaymentRiskPrediction::factory()->create([
            'family_id' => $family->id,
            'family_membership_id' => $membership->id,
            'contribution_id' => $contribution->id,
            'payment_risk_model_version_id' => $model->id,
            'cutoff_at' => $contribution->created_at,
        ]);
    }

    $this->actingAs($admin)
        ->get(route('payment-risk.index', [
            'current_family' => $family->slug,
            'period' => $period->format('Y-m'),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('predictions', 1)
            ->where('predictions.0.member_name', 'Eligible Model Member'));
});

it('shows the stored timing warning for an otherwise pooled unavailable row', function () {
    Storage::fake('local');
    installActiveTestPaymentRiskModel();
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    createMatureRiskHistory($family, $member, 3);
    scoreTestContribution($family, $member, $period, $period->day(24)->setTime(9, 0));
    app(ScoreFamilyPaymentRisk::class)->handle($family, $period->year, $period->month);

    $this->actingAs($admin)
        ->get(route('payment-risk.index', [
            'current_family' => $family->slug,
            'period' => $period->format('Y-m'),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'predictions.0.warning',
                'The contribution was generated fewer than seven days before its due date.',
            ));
});

it('fails closed before displaying a name from a cross-tenant membership', function () {
    Storage::fake('local');
    installActiveTestPaymentRiskModel();
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $otherFamily = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $otherMember = User::factory()->member()->create(['family_id' => $otherFamily->id]);
    $otherMembership = FamilyMembership::query()
        ->where('family_id', $otherFamily->id)
        ->where('user_id', $otherMember->id)
        ->firstOrFail();
    $period = now()->toImmutable()->startOfMonth();
    scoreTestContribution($family, $member, $period);
    app(ScoreFamilyPaymentRisk::class)->handle($family, $period->year, $period->month);
    $prediction = PaymentRiskPrediction::query()->sole();
    DB::table('payment_risk_predictions')
        ->where('id', $prediction->id)
        ->update(['family_membership_id' => $otherMembership->id]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->get(route('payment-risk.index', [
        'current_family' => $family->slug,
        'period' => $period->format('Y-m'),
    ])))->toThrow(RuntimeException::class, 'inconsistent tenant');
});

it('redirects with explicit refresh success and failure feedback', function () {
    Storage::fake('local');
    installActiveTestPaymentRiskModel();
    enablePredictiveAnalyticsGlobally();
    $family = paymentRiskReportsFamily();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $period = now()->format('Y-m');
    $route = route('payment-risk.refresh', ['current_family' => $family->slug]);

    $this->actingAs($admin)
        ->post($route, ['period' => $period])
        ->assertRedirect(route('payment-risk.index', [
            'current_family' => $family->slug,
            'period' => $period,
        ]))
        ->assertSessionHas('success');

    DB::table('payment_risk_model_versions')->update(['is_active' => false]);

    $this->actingAs($admin)
        ->post($route, ['period' => $period])
        ->assertRedirect(route('payment-risk.index', [
            'current_family' => $family->slug,
            'period' => $period,
        ]))
        ->assertSessionHas('error', 'Payment-risk insights are temporarily unavailable. No predictions were changed.');
});
