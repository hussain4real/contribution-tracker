<?php

declare(strict_types=1);

use App\Enums\ReportDeliveryStatus;
use App\Enums\ReportFormat;
use App\Mail\ScheduledReportMail;
use App\Models\Family;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\ReportArtifactService;
use App\Services\WhatsAppService;
use FamilyFunds\D1Staging\D1StagingDeliveryJob;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    Storage::fake('local');
    Mail::fake();
    config([
        'd1-staging.enabled' => true,
        'd1-staging.database' => 'familyfunds-d1-web-staging',
        'd1-staging.delivery.enabled' => true,
        'd1-staging.delivery.email' => 'approved@example.test',
        'd1-staging.delivery.whatsapp' => '+97450000000',
        'd1-staging.delivery.whatsapp_token' => 'synthetic-test-token',
        'services.cloudflare.account_id' => '40344948b73fa9da3008958fdc3faf12',
        'services.cloudflare.token' => 'synthetic-email-token',
        'mail.default' => 'array',
    ]);
});

function stagingDeliverySchedule(): ReportSchedule
{
    $family = Family::factory()->create(['slug' => 'd1-uat-family', 'name' => 'D1 UAT Family']);
    $creator = User::factory()->admin()->create(['family_id' => $family->id, 'email' => 'delivery@example.invalid']);

    return ReportSchedule::factory()->create([
        'family_id' => $family->id, 'created_by' => $creator->id,
        'name' => 'D1-DELIVERY-PROOF-EMAIL', 'format' => ReportFormat::Csv,
        'channels' => ['email'], 'recipients' => ['approved@example.test'],
    ]);
}

it('sends one synthetic report through the selected mailer and preserves duplicate delivery history', function (): void {
    $schedule = stagingDeliverySchedule();
    $originalFrom = config('mail.from');
    $job = new D1StagingDeliveryJob($schedule->id, '2026-09-01', '2026-09-30');
    $job->handle(app(ReportArtifactService::class), app(WhatsAppService::class));
    $job->handle(app(ReportArtifactService::class), app(WhatsAppService::class));

    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail): bool => $mail->hasTo('approved@example.test'));
    Mail::assertSentCount(1);
    expect(ReportDelivery::sole()->status)->toBe(ReportDeliveryStatus::Sent)
        ->and(config('mail.default'))->toBe('array')
        ->and(config('mail.from'))->toBe($originalFrom);
});

it('rejects an unapproved recipient before any external delivery', function (): void {
    $schedule = stagingDeliverySchedule();
    $schedule->update(['recipients' => ['other@example.test']]);
    expect(fn () => (new D1StagingDeliveryJob($schedule->id, '2026-09-01', '2026-09-30'))
        ->handle(app(ReportArtifactService::class), app(WhatsAppService::class)))
        ->toThrow(LogicException::class, 'approved recipient');
    Mail::assertNothingSent();
    expect(ReportDelivery::count())->toBe(0);
});

it('refuses disabled delivery and non-web targets', function (string $setting, mixed $value): void {
    config([$setting => $value]);
    expect(fn () => (new D1StagingDeliveryJob(1, '2026-09-01', '2026-09-30'))
        ->handle(app(ReportArtifactService::class), app(WhatsAppService::class)))
        ->toThrow(LogicException::class, 'isolated web proof');
    Mail::assertNothingSent();
})->with([
    ['d1-staging.delivery.enabled', false],
    ['d1-staging.database', 'familyfunds-d1-scale-lab'],
    ['d1-staging.database', 'production'],
]);

it('uses only the Meta test sender and restores the outbound request restriction', function (): void {
    $schedule = stagingDeliverySchedule();
    $schedule->update(['channels' => ['whatsapp'], 'recipients' => ['+97450000000']]);
    $originalWhatsApp = config('services.whatsapp');
    Http::preventStrayRequests();
    Http::fake(['https://graph.facebook.com/v26.0/1044780152059564/messages' => Http::response([
        'messages' => [['id' => 'wamid.synthetic-test']],
    ])]);

    (new D1StagingDeliveryJob($schedule->id, '2026-09-01', '2026-09-30'))
        ->handle(app(ReportArtifactService::class), app(WhatsAppService::class));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v26.0/1044780152059564/messages'
        && $request['to'] === '+97450000000');
    expect(ReportDelivery::sole()->status)->toBe(ReportDeliveryStatus::Sent)
        ->and(config('services.whatsapp'))->toBe($originalWhatsApp)
        ->and(fn () => Http::get('https://example.invalid/forbidden'))->toThrow(StrayRequestException::class);
});
