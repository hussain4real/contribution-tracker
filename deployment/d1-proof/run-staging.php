<?php

declare(strict_types=1);
use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\ProcessPaystackWebhook;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\ContributionReminderNotification;
use App\Services\PaymentAllocationService;
use App\Support\PlatformPlanCatalog;
use FamilyFunds\D1Staging\D1StagingDeliveryJob;
use FamilyFunds\D1Staging\D1StagingRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Staging operations are CLI-only.');
}
$root = dirname(__DIR__, 2);
$secretPath = $argv[2] ?? $root.'/storage/app/private/d1-staging/staging-secrets.json';
$target = $argv[3] ?? 'web';
if (! in_array($target, ['web', 'scale'], true)) {
    throw new RuntimeException('Unknown isolated staging target.');
}
$database = $target === 'scale' ? 'familyfunds-d1-scale-lab' : 'familyfunds-d1-web-staging';
$url = $target === 'scale' ? 'https://familyfunds-d1-scale-sql.familyfunds-lab.workers.dev' : 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev';
if (is_file($secretPath)) {
    $secrets = json_decode(file_get_contents($secretPath), true, flags: JSON_THROW_ON_ERROR);
} else {
    if (getenv('APP_ENV') !== 'd1-staging' || getenv('D1_STAGING_DATABASE') !== $database || getenv('D1_STAGING_URL') !== $url) {
        throw new RuntimeException('Container credentials must match the isolated staging target.');
    }
    $key = getenv('APP_KEY');
    $token = getenv('D1_STAGING_TOKEN');
    if (! is_string($key) || $key === '' || ! is_string($token) || $token === '') {
        throw new RuntimeException('Missing isolated container credentials.');
    }
    $secrets = ['app_key' => $key, $target === 'scale' ? 'scale_sql_token' : 'sql_token' => $token];
}
$environment = [
    'APP_ENV' => 'd1-staging', 'APP_DEBUG' => 'false', 'APP_KEY' => $secrets['app_key'],
    'D1_STAGING_ENABLED' => 'true', 'D1_STAGING_DATABASE' => $database,
    'D1_STAGING_URL' => $url,
    'D1_STAGING_TOKEN' => $secrets[$target === 'scale' ? 'scale_sql_token' : 'sql_token'], 'NIGHTWATCH_ENABLED' => 'false',
    'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file',
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$runtime = $app->make(D1StagingRuntime::class);
$operation = $argv[1] ?? '';
if ($operation === 'install') {
    D1StagingRuntime::install();
    echo json_encode(['installed' => true])."\n";
} elseif ($operation === 'install-read-cache') {
    D1StagingRuntime::installReadCache();
    echo json_encode(['read_cache_revision_installed' => true])."\n";
} elseif ($operation === 'seed-uat') {
    $family = Family::where('slug', 'd1-uat-family')->first();
    if ($family === null) {
        $id = $runtime->run(fn (): int => Family::factory()->create(['name' => 'D1 UAT Family', 'slug' => 'd1-uat-family', 'currency' => '₦'])->id);
        $family = Family::findOrFail($id);
    }
    $users = [];
    foreach (['secretary', 'member'] as $role) {
        $email = 'd1-'.$role.'@example.invalid';
        $user = User::where('email', $email)->first();
        if ($user === null) {
            $id = $runtime->run(function () use ($role, $family, $email, $secrets): int {
                $factory = User::factory()->withoutTwoFactor()->employed();
                $factory = $role === 'secretary' ? $factory->financialSecretary() : $factory->member();

                return $factory->create(['family_id' => $family->id, 'name' => 'D1 UAT '.ucfirst($role), 'email' => $email, 'password' => $secrets[$role.'_password']])->id;
            });
            $user = User::findOrFail($id);
        }
        $users[$role] = $user->id;
    }
    foreach ([now()->subMonth()->startOfMonth(), now()->startOfMonth()] as $month) {
        if (! Contribution::where('family_id', $family->id)->where('user_id', $users['member'])->where('year', $month->year)->where('month', $month->month)->exists()) {
            $runtime->run(fn () => Contribution::factory()->create(['family_id' => $family->id, 'user_id' => $users['member'], 'year' => $month->year, 'month' => $month->month, 'expected_amount' => 4000]));
        }
    }
    $result = ['family_id' => $family->id, 'family_slug' => $family->slug, 'users' => $users];
    file_put_contents($root.'/storage/app/private/d1-staging/uat-records-'.$target.'.json', json_encode($result, JSON_PRETTY_PRINT)."\n");
    echo json_encode($result)."\n";
} elseif ($operation === 'seed-officers') {
    $family = Family::where('slug', 'd1-uat-family')->firstOrFail();
    $runtime->run(function () use ($family): void {
        $target = Family::findOrFail($family->id);
        $plan = PlatformPlan::whereJsonContains('features', PlatformPlanCatalog::Reports)->where('is_active', true)->orderBy('price')->firstOrFail();
        $target->forceFill(['platform_plan_id' => $plan->id, 'subscription_status' => 'active'])->save();
    });
    $admin = User::where('email', 'd1-admin@example.invalid')->first();
    if ($admin === null) {
        $id = $runtime->run(fn (): int => User::factory()->admin()->nonPaying()->withoutTwoFactor()->create(['family_id' => $family->id, 'name' => 'D1 UAT Admin', 'email' => 'd1-admin@example.invalid', 'password' => $secrets['admin_password']])->id);
        $admin = User::findOrFail($id);
    }
    $other = Family::where('slug', 'd1-uat-other')->first();
    if ($other === null) {
        $id = $runtime->run(fn (): int => Family::factory()->create(['name' => 'D1 UAT Other Family', 'slug' => 'd1-uat-other'])->id);
        $other = Family::findOrFail($id);
    }
    $outsider = User::where('email', 'd1-outsider@example.invalid')->first();
    if ($outsider === null) {
        $id = $runtime->run(fn (): int => User::factory()->member()->employed()->withoutTwoFactor()->create(['family_id' => $other->id, 'name' => 'D1 UAT Outsider', 'email' => 'd1-outsider@example.invalid', 'password' => $secrets['member_password']])->id);
        $outsider = User::findOrFail($id);
    }
    echo json_encode(['admin_id' => $admin->id, 'other_family_id' => $other->id, 'outsider_id' => $outsider->id])."\n";
} elseif ($operation === 'queue-uat') {
    $runtime->run(function (): void {
        $family = Family::where('slug', 'd1-uat-family')->firstOrFail();
        $member = User::where('email', 'd1-member@example.invalid')->firstOrFail();
        PaystackTransaction::firstOrCreate(['reference' => 'D1-UAT-QUEUED-1'], [
            'family_id' => $family->id, 'user_id' => $member->id,
            'type' => TransactionType::Contribution, 'status' => TransactionStatus::Pending,
            'amount' => 4000, 'gross_amount_kobo' => 400000, 'estimated_fee_kobo' => 0,
            'settled_amount_kobo' => 400000, 'fee_policy' => 'payer_pays',
        ]);
        if (! DB::table('jobs')->where('queue', 'd1-uat')->exists()
            && PaystackTransaction::where('reference', 'D1-UAT-QUEUED-1')->value('allocated_at') === null) {
            $payload = ['event' => 'charge.success', 'data' => ['reference' => 'D1-UAT-QUEUED-1', 'amount' => 400000, 'fees' => 0, 'paid_at' => now()->toDateString(), 'status' => 'success']];
            Queue::connection('d1-staging')->bulk([
                new ProcessPaystackWebhook($payload), new ProcessPaystackWebhook($payload),
            ], queue: 'd1-uat');
        }
    });
    $app->make(Kernel::class)->call('queue:work', ['connection' => 'd1-staging', '--queue' => 'd1-uat', '--stop-when-empty' => true, '--max-jobs' => 2, '--tries' => 3, '--timeout' => 45]);
    echo $app->make(Kernel::class)->output();
    $transaction = PaystackTransaction::where('reference', 'D1-UAT-QUEUED-1')->firstOrFail();
    echo json_encode([
        'transaction_status' => $transaction->status->value,
        'payment_batch_id' => $transaction->payment_batch_id,
        'receipt_count' => PaymentBatch::where('reference', 'D1-UAT-QUEUED-1')->count(),
        'allocated_amount' => Payment::where('payment_batch_id', $transaction->payment_batch_id)->sum('amount'),
        'jobs_remaining' => DB::table('jobs')->where('queue', 'd1-uat')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(),
    ])."\n";
} elseif (in_array($operation, ['scale-write', 'scale-many-allocations'], true)) {
    if ($target !== 'scale') {
        throw new RuntimeException('Scale writes require the synthetic scale database.');
    }
    $key = $argv[4] ?? throw new RuntimeException('A scale operation key is required.');
    try {
        $result = $runtime->run(function () use ($key, $operation): int {
            $family = Family::where('slug', 'd1-uat-family')->firstOrFail();
            $member = $operation === 'scale-many-allocations' ? User::findOrFail(101) : User::where('email', 'd1-member@example.invalid')->firstOrFail();
            $officer = User::where('email', 'd1-secretary@example.invalid')->firstOrFail();
            usleep(250000);

            return app(PaymentAllocationService::class)->createBatch(member: $member, amount: $operation === 'scale-many-allocations' ? 200000 : 1, paidAt: now(), recordedBy: $officer, family: $family, reference: $key, idempotencyKey: $key)->id;
        }, (int) ($argv[5] ?? 1));
        echo json_encode(['status' => 'committed', 'batch_id' => $result])."\n";
    } catch (RuntimeException $exception) {
        echo json_encode(['status' => str_contains($exception->getMessage(), 'd1_staging_fresh_snapshot') ? 'conflict' : 'error', 'message' => $exception->getMessage()])."\n";
        exit(2);
    }
} elseif ($operation === 'seed-schedule-proof') {
    if ($target !== 'web') {
        throw new RuntimeException('Maintenance fixtures require the isolated web database.');
    }
    $result = $runtime->run(function (): array {
        $family = Family::firstOrCreate(['slug' => 'd1-schedule-maintenance-20261026'], ['name' => 'D1 Schedule Maintenance Proof', 'currency' => 'NGN']);
        if (! User::where('family_id', $family->id)->exists()) {
            User::factory()->employed()->withoutTwoFactor()->create(['family_id' => $family->id, 'email' => 'd1-schedule-maintenance@example.invalid']);
        }
        $purge = Family::firstOrCreate(['slug' => 'd1-schedule-purge-20261026'], [
            'name' => 'D1 Schedule Purge Proof', 'archived_at' => '2026-07-01', 'purge_after' => '2026-08-01',
        ]);

        return ['family' => $family->id, 'purge' => $purge->id];
    });
    echo json_encode($result)."\n";
} elseif ($operation === 'scheduled-maintenance-proof') {
    $kind = $argv[4] ?? '';
    $familyId = filter_var($argv[5] ?? null, FILTER_VALIDATE_INT);
    $at = $argv[6] ?? '';
    if ($target !== 'web' || ! in_array($kind, ['generate', 'remind25', 'remind28', 'purge', 'reports', 'retry-notifications'], true)
        || ! is_int($familyId) || $familyId < 1 || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $at)
        || Carbon::parse($at)->isFuture()) {
        throw new RuntimeException('Invalid isolated maintenance operation.');
    }
    if ($kind === 'reports') {
        if ($familyId !== 43) {
            throw new RuntimeException('Report maintenance requires the synthetic report family.');
        }
        $kernel = $app->make(Kernel::class);
        $result = $runtime->run(function () use ($kernel): array {
            foreach (ReportSchedule::where('is_active', true)->get() as $schedule) {
                if (! str_starts_with($schedule->name, 'D1-SCHEDULE-UAT-') || $schedule->channels !== ['email']
                    || array_any($schedule->recipients, fn (string $recipient): bool => ! str_ends_with($recipient, '@example.invalid'))) {
                    throw new RuntimeException('Scheduled report proof recipients must be non-deliverable.');
                }
            }

            return ['exit_code' => $kernel->call('reports:dispatch-scheduled', ['--no-interaction' => true])];
        }, $familyId);
    } else {
        $family = Family::find($familyId);
        if ($kind === 'purge' && $family === null) {
            $result = ['exit_code' => 0, 'already_absent' => true];
        } else {
            $slug = $kind === 'purge' ? 'd1-schedule-purge-20261026' : 'd1-schedule-maintenance-20261026';
            if (! $family instanceof Family || $family->slug !== $slug
                || User::where('family_id', $familyId)->where('email', 'not like', '%@example.invalid')->exists()) {
                throw new RuntimeException('Maintenance requires the dedicated synthetic fixture.');
            }
            Carbon::setTestNow(Carbon::parse($at));
            try {
                $result = $runtime->run(function () use ($app, $kind, $familyId): array {
                    $kernel = $app->make(Kernel::class);
                    $arguments = ['--no-interaction' => true];
                    if ($kind === 'retry-notifications') {
                        $contributionIds = Contribution::where('family_id', $familyId)->pluck('id');
                        $ids = [];
                        foreach (DB::table('failed_jobs')->get() as $failed) {
                            $payload = json_decode($failed->payload, true, flags: JSON_THROW_ON_ERROR);
                            $command = $payload['data']['command'] ?? '';
                            if (($payload['displayName'] ?? '') !== ContributionReminderNotification::class
                                || ! is_string($command)
                                || ! $contributionIds->contains(fn (int $id): bool => str_contains($command, '"App\\Models\\Contribution";s:2:"id";i:'.$id.';'))) {
                                throw new RuntimeException('Only the synthetic maintenance notification failures may be retried.');
                            }
                            $ids[] = $failed->uuid;
                        }
                        config(['queue.failed.database' => DB::getDefaultConnection()]);

                        return ['exit_code' => $ids === [] ? 0 : $kernel->call('queue:retry', ['id' => $ids, '--no-interaction' => true]), 'retried' => count($ids)];
                    }
                    $command = match ($kind) {
                        'generate' => 'contributions:generate', 'remind25', 'remind28' => 'contributions:remind', 'purge' => 'families:purge-archived',
                    };
                    if ($kind === 'generate') {
                        $arguments += ['--family' => $familyId, '--year' => now()->year, '--month' => now()->month];
                    } elseif (str_starts_with($kind, 'remind')) {
                        $arguments += ['--day' => $kind === 'remind25' ? 25 : 28];
                    }

                    return ['exit_code' => $kernel->call($command, $arguments), 'output' => trim($kernel->output())];
                }, $kind === 'retry-notifications' ? null : $familyId);
            } finally {
                Carbon::setTestNow();
            }
        }
    }
    echo json_encode(['kind' => $kind, 'family_id' => $familyId, ...$result])."\n";
} elseif ($operation === 'prepare-webhook-proof') {
    $messageId = $argv[4] ?? '';
    $reference = $argv[5] ?? '';
    if ($target !== 'web' || ! preg_match('/^wamid\.[A-Za-z0-9_.=-]{1,240}$/D', $messageId)
        || ! preg_match('/^D1-WEBHOOK-UAT-[A-Za-z0-9-]{1,80}$/D', $reference)) {
        throw new RuntimeException('Webhook fixtures require the isolated web target and bounded synthetic identifiers.');
    }
    $family = Family::where('slug', 'd1-uat-family')->whereKey(43)->firstOrFail();
    $result = $runtime->run(function () use ($family, $messageId, $reference): array {
        $member = User::where('family_id', $family->id)->where('email', 'd1-member@example.invalid')->firstOrFail();
        $transaction = PaystackTransaction::firstOrCreate(['reference' => $reference], [
            'family_id' => $family->id, 'user_id' => $member->id,
            'type' => TransactionType::Contribution, 'status' => TransactionStatus::Pending,
            'amount' => 1, 'gross_amount_kobo' => 100, 'estimated_fee_kobo' => 0,
            'settled_amount_kobo' => 100, 'fee_policy' => 'payer_pays', 'metadata' => ['family_id' => $family->id],
        ]);
        $message = WhatsAppMessage::firstOrCreate(['wa_message_id' => $messageId], [
            'family_id' => $family->id, 'user_id' => $member->id, 'direction' => 'outbound',
            'from' => '15556298737', 'to' => '97455481589', 'type' => 'template',
            'template_name' => 'hello_world', 'body' => 'D1 staging webhook proof', 'status' => 'pending',
        ]);

        return ['family_id' => $family->id, 'transaction_id' => $transaction->id, 'message_id' => $message->id, 'reference' => $reference];
    }, $family->id);
    echo json_encode($result)."\n";
} elseif ($operation === 'dispatch-synthetic-reports') {
    if ($target !== 'web' || config('mail.default') !== 'array') {
        throw new RuntimeException('Report UAT requires the web staging database and captured email.');
    }
    $family = Family::where('slug', 'd1-uat-family')->firstOrFail();
    $result = $runtime->run(function () use ($app, $family): array {
        $schedules = ReportSchedule::query()->where('is_active', true)->get();
        if ($schedules->isEmpty()) {
            return ['exit_code' => 0, 'output' => 'No active synthetic report schedules.', 'family_id' => $family->id, 'delivery' => 'array sink; no outgoing email'];
        }
        foreach ($schedules as $schedule) {
            if ($schedule->family_id !== $family->id || ! str_starts_with($schedule->name, 'D1-SCHEDULE-UAT-') || $schedule->channels !== ['email']) {
                throw new RuntimeException('Only synthetic email schedules may be dispatched by this proof.');
            }
            foreach ($schedule->recipients as $recipient) {
                if (! str_ends_with($recipient, '@example.invalid')) {
                    throw new RuntimeException('Scheduled UAT recipients must be non-deliverable.');
                }
            }
        }
        $kernel = $app->make(Kernel::class);
        $exit = $kernel->call('reports:dispatch-scheduled', ['--no-interaction' => true]);

        return ['exit_code' => $exit, 'output' => trim($kernel->output()), 'family_id' => $family->id, 'delivery' => 'array sink; no outgoing email'];
    }, $family->id);
    echo json_encode($result)."\n";
} elseif ($operation === 'queue-delivery-proof') {
    if ($target !== 'web' || ! config('d1-staging.delivery.enabled')) {
        throw new RuntimeException('Real delivery checks require the isolated web proof and explicit enablement.');
    }
    $channel = $argv[4] ?? '';
    $recipient = config('d1-staging.delivery.'.$channel);
    if (! in_array($channel, ['email', 'whatsapp'], true) || ! is_string($recipient) || $recipient === '') {
        throw new RuntimeException('An approved delivery channel and recipient are required.');
    }
    $family = Family::where('slug', 'd1-uat-family')->firstOrFail();
    $generation = $argv[5] ?? 'initial';
    if (! preg_match('/^[a-z0-9-]{1,40}$/D', $generation)) {
        throw new RuntimeException('A bounded synthetic delivery proof name is required.');
    }
    $result = $runtime->run(function () use ($family, $channel, $recipient, $generation): array {
        $creator = User::where('family_id', $family->id)->where('email', 'd1-admin@example.invalid')->firstOrFail();
        $schedule = ReportSchedule::firstOrCreate([
            'family_id' => $family->id, 'name' => 'D1-DELIVERY-PROOF-'.strtoupper($channel).($generation === 'initial' ? '' : '-'.strtoupper($generation)),
        ], [
            'created_by' => $creator->id, 'report_type' => ReportType::ContributionRegister,
            'format' => ReportFormat::Csv,
            'filters' => ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'],
            'channels' => [$channel], 'recipients' => [$recipient],
            'frequency' => ReportScheduleFrequency::Monthly,
            'timezone' => 'Asia/Qatar', 'next_run_at' => now()->addMonth(), 'is_active' => true,
        ]);
        if ($schedule->channels !== [$channel] || $schedule->recipients !== [$recipient]) {
            throw new RuntimeException('Existing proof schedule does not match the approved recipient.');
        }
        Queue::connection('d1-staging')->push(new D1StagingDeliveryJob($schedule->id, '2026-09-01', '2026-09-30'));

        return ['queued' => true, 'schedule_id' => $schedule->id, 'channel' => $channel];
    }, $family->id);
    echo json_encode($result)."\n";
} elseif ($operation === 'isolate-imported-mfa') {
    if ($target !== 'web') {
        throw new RuntimeException('Imported authentication isolation requires the web staging database.');
    }
    $result = $runtime->run(function (): array {
        $ids = DB::table('users')->where('email', 'not like', 'd1-%@example.invalid')
            ->where(fn (Builder $query): Builder => $query->whereNotNull('two_factor_secret')->orWhereNotNull('two_factor_recovery_codes')->orWhereNotNull('two_factor_confirmed_at'))
            ->pluck('id')->all();
        $changed = $ids === [] ? 0 : DB::table('users')->whereIn('id', $ids)->update([
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
        ]);

        return ['imported_accounts_isolated' => $changed, 'user_ids' => $ids, 'production_secrets_copied' => false, 'policy' => 'Independent staging enrollment; production is untouched.'];
    });
    echo json_encode($result)."\n";
} elseif ($operation === 'stats') {
    $start = hrtime(true);
    $result = $runtime->run(fn (): array => ['users' => User::count(), 'families' => Family::count(), 'contributions' => Contribution::count()]);
    $result['request_ms'] = (hrtime(true) - $start) / 1_000_000;
    echo json_encode($result)."\n";
} else {
    throw new RuntimeException('Unknown staging operation.');
}
