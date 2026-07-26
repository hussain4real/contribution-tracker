<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReportDeliveryStatus;
use App\Mail\ScheduledReportMail;
use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Services\ReportArtifactService;
use App\Services\WhatsAppService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class GenerateScheduledReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $scheduleId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return "{$this->scheduleId}:{$this->periodStart}:{$this->periodEnd}";
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ReportArtifactService $artifacts, WhatsAppService $whatsApp): void
    {
        $schedule = ReportSchedule::query()->with('family')->find($this->scheduleId);

        if (! $schedule instanceof ReportSchedule || ! $schedule->is_active || $schedule->family->isArchived()) {
            return;
        }

        $filters = [...$schedule->filters, 'date_from' => $this->periodStart, 'date_to' => $this->periodEnd];
        $expectedDeliveries = $this->expectedDeliveries($schedule);
        $existingDeliveries = ReportDelivery::query()
            ->where('report_schedule_id', $schedule->id)
            ->whereDate('period_start', $this->periodStart)
            ->whereDate('period_end', $this->periodEnd)
            ->with('artifact')
            ->get();

        $alreadyDelivered = $expectedDeliveries !== []
            && $existingDeliveries->count() === count($expectedDeliveries)
            && $existingDeliveries->every(
                fn (ReportDelivery $delivery): bool => $delivery->status === ReportDeliveryStatus::Sent,
            );

        if (! $alreadyDelivered) {
            $artifact = $existingDeliveries->first()->artifact
                ?? $artifacts->generate($schedule->family, $schedule->report_type, $schedule->format, $filters, $schedule->creator);

            foreach ($expectedDeliveries as [$channel, $recipient]) {
                $delivery = $this->delivery($schedule, $artifact, $channel, $recipient);

                if ($delivery->status !== ReportDeliveryStatus::Sent) {
                    $this->deliver($delivery, $artifact, $channel, $recipient, $whatsApp);
                }
            }

            $nextRun = $schedule->frequency->nextRun(CarbonImmutable::instance($schedule->next_run_at)->setTimezone($schedule->timezone));
            $schedule->forceFill(['last_run_at' => now(), 'next_run_at' => $nextRun->utc()])->save();
        }
    }

    private function delivery(ReportSchedule $schedule, ReportArtifact $artifact, string $channel, string $recipient): ReportDelivery
    {
        $key = hash('sha256', implode('|', [$schedule->id, $this->periodStart, $this->periodEnd, $channel, $recipient]));

        return ReportDelivery::query()->firstOrCreate(['idempotency_key' => $key], [
            'uuid' => (string) Str::uuid(),
            'report_schedule_id' => $schedule->id,
            'report_artifact_id' => $artifact->id,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => ReportDeliveryStatus::Pending,
        ]);
    }

    /** @return list<array{string, string}> */
    private function expectedDeliveries(ReportSchedule $schedule): array
    {
        $deliveries = [];

        foreach ($schedule->channels as $channel) {
            foreach ($schedule->recipients as $recipient) {
                if (($channel === 'email') === str_contains($recipient, '@')) {
                    $deliveries[] = [$channel, $recipient];
                }
            }
        }

        return $deliveries;
    }

    private function deliver(
        ReportDelivery $delivery,
        ReportArtifact $artifact,
        string $channel,
        string $recipient,
        WhatsAppService $whatsApp,
    ): void {
        $delivery->forceFill(['status' => ReportDeliveryStatus::Processing, 'attempted_at' => now(), 'error' => null])->save();
        $url = URL::temporarySignedRoute('reports.deliveries.download', now()->addDays(7), ['reportDelivery' => $delivery]);

        try {
            if ($channel === 'email') {
                Mail::to($recipient)->send(new ScheduledReportMail($artifact, $url));
            } else {
                $result = $whatsApp->sendText($recipient, "Your scheduled family report is ready: {$url}");

                if (! $result['success']) {
                    throw new \RuntimeException($result['error'] ?? 'WhatsApp delivery failed.');
                }
            }

            $delivery->forceFill(['status' => ReportDeliveryStatus::Sent, 'sent_at' => now(), 'failed_at' => null])->save();
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => ReportDeliveryStatus::Failed,
                'failed_at' => now(),
                'error' => Str::limit($exception->getMessage(), 2000),
            ])->save();

            report($exception);
        }
    }
}
