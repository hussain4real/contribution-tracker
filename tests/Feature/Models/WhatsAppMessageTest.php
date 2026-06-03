<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;

it('casts payload and timestamp fields', function () {
    $message = WhatsAppMessage::factory()->create([
        'payload' => ['messages' => [['id' => 'wamid.123']]],
        'wa_timestamp' => '2026-05-11 10:15:00',
        'read_at' => '2026-05-11 11:00:00',
    ]);

    expect($message->payload)->toBe(['messages' => [['id' => 'wamid.123']]])
        ->and($message->wa_timestamp)->toBeInstanceOf(Carbon::class)
        ->and($message->read_at)->toBeInstanceOf(Carbon::class);
});

it('exposes family and user relationships', function () {
    $family = Family::factory()->create();
    $user = User::factory()->member()->create(['family_id' => $family->id]);

    $message = WhatsAppMessage::factory()->create([
        'family_id' => $family->id,
        'user_id' => $user->id,
    ]);

    expect($message->family()->firstOrFail()->is($family))->toBeTrue()
        ->and($message->user()->firstOrFail()->is($user))->toBeTrue();
});

it('filters whatsapp messages with local query scopes', function () {
    $family = Family::factory()->create();
    $otherFamily = Family::factory()->create();
    $inbound = WhatsAppMessage::factory()->inbound()->create([
        'family_id' => $family->id,
        'from' => '2348012345678',
        'to' => '1038448572690931',
    ]);
    $outbound = WhatsAppMessage::factory()->create([
        'family_id' => $family->id,
        'from' => '1038448572690931',
        'to' => '2348012345678',
    ]);
    $otherFamilyMessage = WhatsAppMessage::factory()->create([
        'family_id' => $otherFamily->id,
        'to' => '2348099999999',
    ]);

    expect($inbound->isInbound())->toBeTrue()
        ->and($outbound->isInbound())->toBeFalse()
        ->and(WhatsAppMessage::inbound()->pluck('id')->all())->toBe([$inbound->id])
        ->and(WhatsAppMessage::outbound()->pluck('id')->all())->toEqualCanonicalizing([
            $outbound->id,
            $otherFamilyMessage->id,
        ])
        ->and(WhatsAppMessage::forFamily($family->id)->pluck('id')->all())->toEqualCanonicalizing([
            $inbound->id,
            $outbound->id,
        ])
        ->and(WhatsAppMessage::forPhone('2348012345678')->pluck('id')->all())->toEqualCanonicalizing([
            $inbound->id,
            $outbound->id,
        ]);
});
