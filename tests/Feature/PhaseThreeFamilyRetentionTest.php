<?php

declare(strict_types=1);

use App\Actions\ArchiveFamily;
use App\Actions\RestoreFamily;
use App\Jobs\PurgeArchivedFamily;
use App\Models\Family;
use App\Models\ReportArtifact;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

it('archives immediately and permits only restore and export during retention', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->post(route('family.archive.store', ['current_family' => $family->slug]), [
            'reason' => 'The family is closing this workspace permanently.',
            'confirmation' => 'ARCHIVE',
        ])->assertRedirect(route('family.archive.show', ['current_family' => $family->slug]));

    $family->refresh();
    expect($family->isArchived())->toBeTrue()
        ->and(abs($family->purge_after?->diffInDays($family->archived_at) ?? 0))->toBe(30.0);

    $this->get(route('dashboard', ['current_family' => $family->slug]))->assertStatus(423);
    $this->get(route('family.archive.show', ['current_family' => $family->slug]))->assertOk();
    $exportResponse = $this->get(route('family.archive.export', ['current_family' => $family->slug]));
    $exportResponse
        ->assertOk()
        ->assertHeader('content-type', 'application/json');
    expect($exportResponse->streamedContent())->toContain('"family"', $family->name);

    $this->post(route('family.archive.restore', ['current_family' => $family->slug]))
        ->assertRedirect(route('dashboard', ['current_family' => $family->slug]));

    expect($family->refresh()->isArchived())->toBeFalse()
        ->and($family->purge_after)->toBeNull();
});

it('enforces the restoration deadline and makes archive actions idempotent', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $action = app(ArchiveFamily::class);
    $first = $action->handle($family, $admin, 'Requested account closure after migration.');
    $second = $action->handle($first, $admin, 'A duplicate archive request was retried.');

    expect($second->archived_at?->toDateTimeString())->toBe($first->archived_at?->toDateTimeString());

    $family->forceFill(['purge_after' => now()->subMinute()])->save();
    expect(fn () => app(RestoreFamily::class)->handle($family->refresh()))
        ->toThrow(ValidationException::class, 'restoration window has expired');

    $activeFamily = Family::factory()->create();
    expect(app(RestoreFamily::class)->handle($activeFamily)->is($activeFamily))->toBeTrue();
});

it('purges expired database records and private artifacts without deleting people', function () {
    Storage::fake('local');
    $family = Family::factory()->archived()->create(['purge_after' => now()->subMinute()]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $artifact = ReportArtifact::factory()->create([
        'family_id' => $family->id,
        'requested_by' => $admin->id,
        'path' => "reports/{$family->id}/test.csv",
    ]);
    Storage::disk('local')->put($artifact->path, 'private report');

    $job = new PurgeArchivedFamily($family->id);
    $job->handle();
    $job->handle();

    expect(Family::query()->find($family->id))->toBeNull()
        ->and(User::query()->find($admin->id))->not->toBeNull()
        ->and(User::query()->find($admin->id)?->family_id)->toBeNull()
        ->and(ReportArtifact::query()->where('family_id', $family->id)->count())->toBe(0)
        ->and(Storage::disk('local')->exists($artifact->path))->toBeFalse()
        ->and($job->uniqueId())->toBe((string) $family->id);
});

it('pauses purge on legal hold and dispatches only eligible families', function () {
    Storage::fake('local');
    Bus::fake();
    $held = Family::factory()->archived()->create([
        'purge_after' => now()->subDay(),
        'legal_hold_at' => now(),
        'legal_hold_reason' => 'Regulatory preservation request.',
    ]);
    $eligible = Family::factory()->archived()->create(['purge_after' => now()->subDay()]);

    (new PurgeArchivedFamily($held->id))->handle();
    expect($held->refresh()->exists)->toBeTrue();

    $this->artisan('families:purge-archived')->assertSuccessful();
    Bus::assertDispatched(PurgeArchivedFamily::class, fn (PurgeArchivedFamily $job): bool => $job->familyId === $eligible->id);
    Bus::assertNotDispatched(PurgeArchivedFamily::class, fn (PurgeArchivedFamily $job): bool => $job->familyId === $held->id);
});
