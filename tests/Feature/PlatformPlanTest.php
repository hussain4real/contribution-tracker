<?php

declare(strict_types=1);

use App\Filament\Resources\PlatformPlans\Pages\CreatePlatformPlan;
use App\Filament\Resources\PlatformPlans\Pages\EditPlatformPlan;
use App\Filament\Resources\PlatformPlans\Pages\ListPlatformPlans;
use App\Filament\Resources\PlatformPlans\PlatformPlanResource;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\PlatformPlanCatalog;
use Database\Seeders\PlatformPlanSeeder;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Collection;
use Livewire\Livewire;

function createSuperAdmin(): User
{
    $family = Family::factory()->create();

    return User::factory()->admin()->create([
        'family_id' => $family->id,
        'is_super_admin' => true,
    ]);
}

/**
 * @param  Collection<string, PlatformPlan>  $plans
 */
function seededPlatformPlan(Collection $plans, string $slug): PlatformPlan
{
    $plan = $plans->get($slug);

    if (! $plan instanceof PlatformPlan) {
        throw new RuntimeException("Expected seeded platform plan [{$slug}].");
    }

    return $plan;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function platformPlanFormData(array $overrides = []): array
{
    return [
        'name' => 'Pro',
        'slug' => 'pro',
        'price' => 5000,
        'max_members' => 20,
        'paystack_plan_code' => null,
        'features' => [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::Reports,
            PlatformPlanCatalog::Exports,
        ],
        'is_active' => true,
        'sort_order' => 1,
        ...$overrides,
    ];
}

it('seeds the freemium self serve pricing ladder and retires legacy plans', function () {
    foreach (['starter', 'pro', 'enterprise'] as $legacySlug) {
        PlatformPlan::create([
            'name' => ucfirst($legacySlug),
            'slug' => $legacySlug,
            'price' => 9999,
            'max_members' => 99,
            'features' => ['basic_contributions'],
            'is_active' => true,
            'sort_order' => 9,
            'paystack_plan_code' => 'PLN_'.$legacySlug,
        ]);
    }

    $this->seed(PlatformPlanSeeder::class);

    $activePlans = PlatformPlan::query()
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get()
        ->keyBy('slug');
    $freePlan = seededPlatformPlan($activePlans, PlatformPlanCatalog::Free);
    $familyPlan = seededPlatformPlan($activePlans, PlatformPlanCatalog::Family);
    $growthPlan = seededPlatformPlan($activePlans, PlatformPlanCatalog::Growth);
    $organizationPlan = seededPlatformPlan($activePlans, PlatformPlanCatalog::Organization);

    expect($activePlans->keys()->all())->toBe([
        PlatformPlanCatalog::Free,
        PlatformPlanCatalog::Family,
        PlatformPlanCatalog::Growth,
        PlatformPlanCatalog::Organization,
    ])
        ->and($freePlan->price)->toBe(0)
        ->and($freePlan->max_members)->toBe(5)
        ->and($freePlan->features)->toBe([
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
        ])
        ->and($familyPlan->price)->toBe(3000)
        ->and($familyPlan->max_members)->toBe(25)
        ->and($familyPlan->features)->toContain(
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::EmailReminders,
            PlatformPlanCatalog::WebPushReminders,
            PlatformPlanCatalog::WhatsappReminders,
            PlatformPlanCatalog::Reports,
        )
        ->and($growthPlan->price)->toBe(7500)
        ->and($growthPlan->max_members)->toBe(75)
        ->and($growthPlan->features)->toContain(
            PlatformPlanCatalog::WhatsappReminders,
            PlatformPlanCatalog::AiAssistant,
        )
        ->and($organizationPlan->price)->toBe(20000)
        ->and($organizationPlan->max_members)->toBe(250)
        ->and($organizationPlan->features)->toContain(
            PlatformPlanCatalog::WhatsappReminders,
            PlatformPlanCatalog::WhatsappMessaging,
            PlatformPlanCatalog::PrioritySupport,
        );

    $legacyActiveStates = PlatformPlan::query()
        ->whereIn('slug', ['starter', 'pro', 'enterprise'])
        ->get()
        ->mapWithKeys(fn (PlatformPlan $plan): array => [$plan->slug => $plan->is_active])
        ->sortKeys()
        ->all();

    expect($legacyActiveStates)
        ->toBe([
            'enterprise' => false,
            'pro' => false,
            'starter' => false,
        ]);
});

it('shows the plans resource for super admin', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($admin)
        ->get(PlatformPlanResource::getUrl())
        ->assertSuccessful()
        ->assertSee('Plans')
        ->assertSee('Free');

    $component = Livewire::test(ListPlatformPlans::class);

    $component->assertOk();
    $component->assertCanSeeTableRecords([$plan]);
});

it('denies non-super-admin access to plans', function () {
    $family = Family::factory()->create();
    $user = User::factory()->create(['family_id' => $family->id]);

    $this->actingAs($user)
        ->get(PlatformPlanResource::getUrl())
        ->assertForbidden();
});

it('creates a new plan', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin);

    Livewire::test(CreatePlatformPlan::class)
        ->fillForm(platformPlanFormData())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $plan = PlatformPlan::where('slug', 'pro')->firstOrFail();

    expect($plan->name)->toBe('Pro')
        ->and($plan->price)->toBe(5000)
        ->and($plan->max_members)->toBe(20)
        ->and($plan->features)->toBe([
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::Reports,
            PlatformPlanCatalog::Exports,
        ]);
});

it('validates required fields when creating a plan', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin);

    Livewire::test(CreatePlatformPlan::class)
        ->fillForm([
            'name' => null,
            'slug' => null,
            'price' => null,
            'sort_order' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'slug' => 'required',
            'price' => 'required',
            'sort_order' => 'required',
        ]);
});

it('prevents duplicate slugs', function () {
    $admin = createSuperAdmin();

    PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => [],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($admin);

    Livewire::test(CreatePlatformPlan::class)
        ->fillForm(platformPlanFormData([
            'name' => 'Free Again',
            'slug' => 'free',
        ]))
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('updates an existing plan', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Basic',
        'slug' => 'basic',
        'price' => 1000,
        'max_members' => 10,
        'features' => [PlatformPlanCatalog::BasicContributions],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditPlatformPlan::class, ['record' => $plan->getRouteKey()])
        ->fillForm(platformPlanFormData([
            'name' => 'Basic Plus',
            'slug' => 'basic',
            'price' => 2000,
            'max_members' => 15,
            'features' => [
                PlatformPlanCatalog::BasicContributions,
                PlatformPlanCatalog::Reports,
            ],
            'sort_order' => 0,
        ]))
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $plan->refresh();

    expect($plan->name)->toBe('Basic Plus')
        ->and($plan->price)->toBe(2000)
        ->and($plan->max_members)->toBe(15)
        ->and($plan->features)->toBe([
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::Reports,
        ]);
});

it('toggles plan active status', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price' => 5000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListPlatformPlans::class)
        ->callTableAction('toggleActive', $plan)
        ->assertNotified('Plan "Pro" has been deactivated.');

    expect($plan->refresh()->is_active)->toBeFalse();

    Livewire::test(ListPlatformPlans::class)
        ->callTableAction('toggleActive', $plan)
        ->assertNotified('Plan "Pro" has been activated.');

    expect($plan->refresh()->is_active)->toBeTrue();
});

it('deletes a plan with no families', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Test',
        'slug' => 'test',
        'price' => 0,
        'max_members' => 5,
        'features' => [],
        'is_active' => false,
        'sort_order' => 99,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListPlatformPlans::class)
        ->callTableAction(DeleteAction::class, $plan)
        ->assertNotified();

    expect(PlatformPlan::find($plan->id))->toBeNull();
});

it('prevents deleting a plan with assigned families', function () {
    $admin = createSuperAdmin();

    $plan = PlatformPlan::create([
        'name' => 'Popular',
        'slug' => 'popular',
        'price' => 3000,
        'max_members' => 10,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    Family::factory()->create(['platform_plan_id' => $plan->id]);

    $this->actingAs($admin);

    Livewire::test(ListPlatformPlans::class)
        ->callTableAction(DeleteAction::class, $plan)
        ->assertNotified('Cannot delete a plan that has families assigned to it.');

    expect(PlatformPlan::find($plan->id))->not->toBeNull();
});
