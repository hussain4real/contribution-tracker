<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class D1ProofPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array{family_id: int, actor_id: int, member_id: int, amount: int, paid_at: string, key: string} $arguments */
    public function __construct(public readonly array $arguments) {}

    public function handle(D1FinancialCommands $commands): void
    {
        $commands->execute('allocate', $this->arguments);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
