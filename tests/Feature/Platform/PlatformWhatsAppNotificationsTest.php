<?php

declare(strict_types=1);

use App\Filament\Resources\WhatsAppMessages\Pages\ListWhatsAppMessages;
use App\Filament\Resources\WhatsAppMessages\WhatsAppMessageResource;
use App\Filament\Resources\WhatsAppMessages\Widgets\WhatsAppDeliveryStats;
use App\Models\Family;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;

describe('Platform WhatsApp notifications', function () {
    it('allows super admins to access the notification monitor', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get(WhatsAppMessageResource::getUrl())
            ->assertOk()
            ->assertSee('WhatsApp Notifications');
    });

    it('denies non-super-admin access to the notification monitor', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get(WhatsAppMessageResource::getUrl())
            ->assertForbidden();
    });

    it('shows outbound notifications and excludes inbound messages', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $outbound = WhatsAppMessage::factory()->create([
            'family_id' => $family->id,
            'user_id' => $superAdmin->id,
        ]);
        $inbound = WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $family->id,
            'user_id' => $superAdmin->id,
        ]);
        WhatsAppMessage::factory()->create(['to' => null]);
        WhatsAppMessage::factory()->create(['to' => '1234']);

        $this->actingAs($superAdmin);

        $component = Livewire::test(ListWhatsAppMessages::class);

        $component->assertOk();
        $component
            ->assertCanSeeTableRecords([$outbound])
            ->assertCanNotSeeTableRecords([$inbound])
            ->assertTableColumnExists(
                'to',
                fn (TextColumn $column): bool => $column->getCopyableState($outbound->to) === $outbound->to,
                $outbound,
            )
            ->assertSee($family->name)
            ->assertSee($superAdmin->name)
            ->assertSee('Unknown')
            ->assertSee('1234')
            ->assertSee(mb_substr($outbound->to, -4));
    });

    it('formats every supported delivery status', function () {
        expect(WhatsAppMessageResource::statusLabel('pending'))->toBe('Pending')
            ->and(WhatsAppMessageResource::statusLabel('sent'))->toBe('Sent')
            ->and(WhatsAppMessageResource::statusLabel('delivered'))->toBe('Delivered')
            ->and(WhatsAppMessageResource::statusLabel('read'))->toBe('Read')
            ->and(WhatsAppMessageResource::statusLabel('failed'))->toBe('Failed')
            ->and(WhatsAppMessageResource::statusLabel('accepted'))->toBe('Accepted')
            ->and(WhatsAppMessageResource::statusLabel(null))->toBe('Unknown');
    });

    it('filters notifications by delivery status', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $failed = WhatsAppMessage::factory()->failed()->create();
        $delivered = WhatsAppMessage::factory()->delivered()->create();

        $this->actingAs($superAdmin);

        Livewire::test(ListWhatsAppMessages::class)
            ->filterTable('status', ['failed'])
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$delivered]);
    });

    it('filters notifications by attempted date range', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $beforeRange = WhatsAppMessage::factory()->create(['created_at' => now()->subDays(3)]);
        $withinRange = WhatsAppMessage::factory()->create(['created_at' => now()->subDay()]);
        $afterRange = WhatsAppMessage::factory()->create(['created_at' => now()->addDay()]);

        $this->actingAs($superAdmin);

        Livewire::test(ListWhatsAppMessages::class)
            ->filterTable('attempted_at', [
                'from' => now()->subDays(2)->toDateString(),
                'until' => now()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$withinRange])
            ->assertCanNotSeeTableRecords([$beforeRange, $afterRange]);
    });

    it('summarizes delivery health without counting inbound or old notifications', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->delivered()->create();
        WhatsAppMessage::factory()->read()->create();
        WhatsAppMessage::factory()->failed()->create();
        WhatsAppMessage::factory()->create();
        WhatsAppMessage::factory()->inbound()->create();
        WhatsAppMessage::factory()->delivered()->create(['created_at' => now()->subDays(8)]);

        $this->actingAs($superAdmin);

        Livewire::test(WhatsAppDeliveryStats::class)
            ->assertOk()
            ->assertSee('Attempts today')
            ->assertSee('4')
            ->assertSee('Confirmed today')
            ->assertSee('2')
            ->assertSee('Failed today')
            ->assertSee('1')
            ->assertSee('7-day confirmation rate')
            ->assertSee('50.0%');
    });

    it('summarizes an empty delivery window', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(WhatsAppDeliveryStats::class)
            ->assertOk()
            ->assertSee('No failures reported')
            ->assertSee('0.0%')
            ->assertSee('0 of 0 confirmed');
    });

    it('shows provider and failure details on the read-only notification page', function () {
        $family = Family::factory()->create(['name' => 'Hussain Family']);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $message = WhatsAppMessage::factory()->failed()->create([
            'family_id' => $family->id,
            'user_id' => $superAdmin->id,
            'to' => '2348012345678',
            'wa_message_id' => 'wamid.failed-message-1',
            'error_code' => '131026',
            'error_message' => 'Message undeliverable',
            'payload' => ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'],
        ]);

        $this->actingAs($superAdmin)
            ->get(WhatsAppMessageResource::getUrl('view', ['record' => $message]))
            ->assertOk()
            ->assertSee('Failure details')
            ->assertSee('131026')
            ->assertSee('Message undeliverable')
            ->assertSee('2348012345678')
            ->assertSee('wamid.failed-message-1')
            ->assertSee('Hussain Family')
            ->assertDontSee('Edit');
    });
});
