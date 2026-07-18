<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PaystackWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPaystackWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly array $payload) {}

    public function handle(PaystackWebhookProcessor $processor): void
    {
        $processor->process($this->payload);
    }

    public function uniqueId(): string
    {
        $data = is_array($this->payload['data'] ?? null) ? $this->payload['data'] : [];
        $reference = $data['reference'] ?? $data['subscription_code'] ?? '';
        $event = $this->payload['event'] ?? '';

        return hash(
            'sha256',
            (is_scalar($event) ? (string) $event : '').':'.(is_scalar($reference) ? (string) $reference : ''),
        );
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }
}
