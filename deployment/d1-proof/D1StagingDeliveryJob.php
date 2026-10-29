<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use App\Jobs\GenerateScheduledReport;
use App\Models\ReportSchedule;
use App\Services\ReportArtifactService;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use LogicException;

final class D1StagingDeliveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $scheduleId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
    ) {}

    public function handle(ReportArtifactService $artifacts, WhatsAppService $whatsApp): void
    {
        if (! app()->environment(['testing', 'd1-staging'])
            || ! config('d1-staging.enabled') || ! config('d1-staging.delivery.enabled')
            || config('d1-staging.database') !== 'familyfunds-d1-web-staging') {
            throw new LogicException('Real delivery checks require the isolated web proof.');
        }
        $schedule = ReportSchedule::query()->with(['family', 'creator'])->findOrFail($this->scheduleId);
        $url = config('d1-staging.url');
        if (! is_string($url)) {
            throw new LogicException('Missing isolated SQL endpoint.');
        }
        $channel = $schedule->channels[0] ?? '';
        $recipient = config('d1-staging.delivery.'.$channel);
        if ($schedule->family->slug !== 'd1-uat-family' || $schedule->family->name !== 'D1 UAT Family'
            || ! str_ends_with($schedule->creator->email ?? '', '@example.invalid')
            || ! str_starts_with($schedule->name, 'D1-DELIVERY-PROOF-')
            || ! in_array($channel, ['email', 'whatsapp'], true)
            || $schedule->channels !== [$channel] || ! is_string($recipient) || $recipient === ''
            || $schedule->recipients !== [$recipient]) {
            throw new LogicException('Delivery checks may send only synthetic reports to the approved recipient.');
        }
        $settings = ['mail.default', 'mail.from', 'services.whatsapp'];
        $original = [];
        foreach ($settings as $key) {
            $original[$key] = config($key);
        }
        try {
            if ($channel === 'email') {
                if (config('services.cloudflare.account_id') !== '40344948b73fa9da3008958fdc3faf12'
                    || ! is_string(config('services.cloudflare.token')) || config('services.cloudflare.token') === '') {
                    throw new LogicException('Missing temporary Cloudflare email credentials.');
                }
                config([
                    'mail.default' => 'cloudflare',
                    'mail.from' => ['address' => 'no-reply@staging-mail.familyfunds.app', 'name' => 'FamilyFunds D1 Staging'],
                ]);
            } else {
                $token = config('d1-staging.delivery.whatsapp_token');
                if (! is_string($token) || $token === '') {
                    throw new LogicException('Missing Meta test-account credentials.');
                }
                config(['services.whatsapp' => [
                    'base_url' => 'https://graph.facebook.com', 'api_version' => 'v26.0',
                    'phone_number_id' => '1044780152059564', 'access_token' => $token,
                ]]);
                Http::allowStrayRequests([
                    $url.'/*',
                    'https://graph.facebook.com/v26.0/1044780152059564/messages',
                ]);
            }
            (new GenerateScheduledReport($this->scheduleId, $this->periodStart, $this->periodEnd))->handle($artifacts, $whatsApp);
        } finally {
            config($original);
            Http::allowStrayRequests([$url.'/*']);
        }
    }
}
