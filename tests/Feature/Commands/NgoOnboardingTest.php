<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Mail\NgoOnboardingCredentialsMail;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

it('onboards an ngo with qar monthly dues and privileged users', function () {
    Mail::fake();

    $whatsapp = typedMock(WhatsAppService::class);
    $whatsapp->shouldReceive('sendOnboardingNotice')
        ->once()
        ->with(
            '+97455550001',
            'Ngo Admin',
            'Qatar Helping Hands',
            'Admin',
            'admin@ngo.test',
            route('login'),
        )
        ->andReturn([
            'success' => true,
            'wa_message_id' => 'wamid.admin',
            'error' => null,
        ]);
    $whatsapp->shouldReceive('sendOnboardingNotice')
        ->once()
        ->with(
            '+97455550002',
            'Finance Lead',
            'Qatar Helping Hands',
            'Financial Secretary',
            'finance@ngo.test',
            route('login'),
        )
        ->andReturn([
            'success' => true,
            'wa_message_id' => 'wamid.finance',
            'error' => null,
        ]);

    app()->instance(WhatsAppService::class, $whatsapp);

    $this->artisan('ngo:onboard', [
        '--name' => 'Qatar Helping Hands',
        '--due-day' => '30',
        '--admin-name' => 'Ngo Admin',
        '--admin-email' => 'admin@ngo.test',
        '--admin-whatsapp' => '+97455550001',
        '--financial-secretary-name' => 'Finance Lead',
        '--financial-secretary-email' => 'finance@ngo.test',
        '--financial-secretary-whatsapp' => '+97455550002',
    ])->assertSuccessful();

    $family = Family::query()->where('name', 'Qatar Helping Hands')->firstOrFail();
    $category = FamilyCategory::query()->where('family_id', $family->id)->firstOrFail();
    $admin = User::query()->where('email', 'admin@ngo.test')->firstOrFail();
    $financialSecretary = User::query()->where('email', 'finance@ngo.test')->firstOrFail();

    expect($family->currency)->toBe('QAR')
        ->and($family->due_day)->toBe(30)
        ->and($family->created_by)->toBe($admin->id)
        ->and($category->name)->toBe('Monthly Dues')
        ->and($category->monthly_amount)->toBe(100)
        ->and($admin->role)->toBe(Role::Admin)
        ->and($admin->family_id)->toBe($family->id)
        ->and($admin->family_category_id)->toBeNull()
        ->and($admin->must_change_password_at)->not->toBeNull()
        ->and($financialSecretary->role)->toBe(Role::FinancialSecretary)
        ->and($financialSecretary->family_id)->toBe($family->id)
        ->and($financialSecretary->family_category_id)->toBe($category->id)
        ->and($financialSecretary->getMonthlyAmount())->toBe(100)
        ->and($financialSecretary->must_change_password_at)->not->toBeNull();

    Mail::assertSent(NgoOnboardingCredentialsMail::class, 2);

    Mail::assertSent(NgoOnboardingCredentialsMail::class, function (NgoOnboardingCredentialsMail $mail) use ($admin): bool {
        return $mail->hasTo('admin@ngo.test')
            && $mail->user->is($admin)
            && Hash::check($mail->temporaryPassword, $admin->password)
            && $mail->loginUrl === route('login');
    });

    Mail::assertSent(NgoOnboardingCredentialsMail::class, function (NgoOnboardingCredentialsMail $mail) use ($financialSecretary): bool {
        return $mail->hasTo('finance@ngo.test')
            && $mail->user->is($financialSecretary)
            && Hash::check($mail->temporaryPassword, $financialSecretary->password)
            && $mail->loginUrl === route('login');
    });
});

it('defaults the ngo contribution due day to the twenty eighth', function () {
    Mail::fake();

    $this->artisan('ngo:onboard', [
        '--name' => 'Qatar Monthly Circle',
        '--admin-name' => 'Admin User',
        '--admin-email' => 'admin-default@ngo.test',
        '--admin-whatsapp' => '+97455550011',
        '--financial-secretary-name' => 'Secretary User',
        '--financial-secretary-email' => 'secretary-default@ngo.test',
        '--financial-secretary-whatsapp' => '+97455550012',
        '--skip-whatsapp' => true,
    ])->assertSuccessful();

    expect(Family::query()->where('name', 'Qatar Monthly Circle')->firstOrFail()->due_day)->toBe(28);
});

it('does not fail after records are created when whatsapp onboarding delivery fails', function () {
    Mail::fake();

    config()->set('services.whatsapp.templates.onboarding.name', null);

    $this->artisan('ngo:onboard', [
        '--name' => 'Qatar Resilience Fund',
        '--admin-name' => 'Admin Contact',
        '--admin-email' => 'admin-resilience@ngo.test',
        '--admin-whatsapp' => '+97455550101',
        '--financial-secretary-name' => 'Finance Contact',
        '--financial-secretary-email' => 'finance-resilience@ngo.test',
        '--financial-secretary-whatsapp' => '+97455550102',
    ])
        ->expectsOutputToContain('WhatsApp delivery [admin]: failed')
        ->expectsOutputToContain('WhatsApp delivery [financial_secretary]: failed')
        ->assertSuccessful();

    expect(Family::query()->where('name', 'Qatar Resilience Fund')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'admin-resilience@ngo.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'finance-resilience@ngo.test')->exists())->toBeTrue();

    Mail::assertSent(NgoOnboardingCredentialsMail::class, 2);
});
