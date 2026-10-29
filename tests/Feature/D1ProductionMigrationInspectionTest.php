<?php

declare(strict_types=1);

use App\Models\ReportArtifact;
use App\Models\User;
use FamilyFunds\D1Staging\D1ProductionMaintenance;
use FamilyFunds\D1Staging\D1RuntimeClient;
use FamilyFunds\D1Staging\D1RuntimeProfile;
use FamilyFunds\D1Staging\D1StagingRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    DB::select('select 1');
    $this->app['env'] = 'production';
    $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($pair === false) {
        throw new RuntimeException('Unable to generate the fixture signing key.');
    }
    openssl_pkey_export($pair, $privateKey);
    $details = openssl_pkey_get_details($pair);
    if ($details === false) {
        throw new RuntimeException('Unable to inspect the fixture signing key.');
    }
    $publicKey = $details['key'];
    $vapid = VAPID::createVapidKeys();
    config(['app.env' => 'production', 'app.debug' => false, 'd1-staging.enabled' => false, 'd1-production.enabled' => true,
        'd1-production.migration_checks' => true, 'd1-production.delivery_enabled' => false, 'd1-production.queue_worker_enabled' => false,
        'passport.private_key' => $privateKey, 'passport.public_key' => $publicKey,
        'webpush.vapid' => ['subject' => 'mailto:migration@example.invalid', 'public_key' => $vapid['publicKey'], 'private_key' => $vapid['privateKey']]]);
    Http::preventStrayRequests();
    $this->inspection = new D1ProductionMaintenance(new D1RuntimeClient(D1RuntimeProfile::PRODUCTION_URL, 'independent-inspection-fixture', D1RuntimeProfile::PRODUCTION_DATABASE), new D1StagingRuntime, app(Kernel::class));
});

it('verifies migrated encrypted accounts and signing keys without changing rows or returning secret material', function (): void {
    $secret = 'JBSWY3DPEHPK3PXP';
    $recovery = '["independent-recovery-fixture"]';
    $user = User::factory()->create(['two_factor_secret' => Crypt::encrypt($secret), 'two_factor_recovery_codes' => Crypt::encrypt($recovery), 'two_factor_confirmed_at' => now()]);
    Storage::fake('local');
    $artifact = ReportArtifact::factory()->create();
    Storage::disk('local')->put($artifact->path, 'independent-report-fixture');
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'non-executing-migration-fixture', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 1, 'created_at' => 1]);
    $before = $user->refresh()->getRawOriginal();
    $result = $this->inspection->inspectMigration();
    expect($result)->toMatchArray(['exit_code' => 0, 'read_only' => true, 'passport_pair_valid' => true, 'vapid_pair_valid' => true, 'jobs' => 1, 'foreign_key_violations' => 0])
        ->and($result['mfa'])->toBe(['enrolled' => 1, 'confirmed' => 1, 'encrypted_fields_verified' => 2])
        ->and($result['files'])->toContain(['path_sha256' => hash('sha256', $artifact->disk.':'.$artifact->path), 'bytes' => strlen('independent-report-fixture'), 'sha256' => hash('sha256', 'independent-report-fixture')])
        ->and($user->refresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('jobs')->value('reserved_at'))->toBeNull()
        ->and(json_encode($result))->not->toContain($secret, $recovery, 'PRIVATE KEY');
    Http::assertNothingSent();
});

it('refuses mismatched push keys before declaring production credentials compatible', function (): void {
    config(['webpush.vapid.private_key' => VAPID::createVapidKeys()['privateKey']]);
    expect(fn () => $this->inspection->inspectMigration())->toThrow(RuntimeException::class, 'do not match');
});

it('refuses missing application files and activated delivery', function (): void {
    Storage::fake('local');
    ReportArtifact::factory()->create();
    expect(fn () => $this->inspection->inspectMigration())->toThrow(RuntimeException::class, 'file is missing');
    config(['d1-production.delivery_enabled' => true]);
    expect(fn () => $this->inspection->inspectMigration())->toThrow(RuntimeException::class, 'Read-only migration');
});
