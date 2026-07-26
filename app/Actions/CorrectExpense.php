<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Expense;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class CorrectExpense
{
    public function __construct(private readonly ReverseExpense $reverseExpense) {}

    public function handle(
        Expense $original,
        User $actor,
        string $reason,
        int $amount,
        string $description,
        DateTimeInterface|string $spentAt,
    ): Expense {
        return DB::transaction(function () use (
            $original,
            $actor,
            $reason,
            $amount,
            $description,
            $spentAt,
        ): Expense {
            $replacement = Expense::query()->create([
                'family_id' => $original->family_id,
                'amount' => $amount,
                'description' => $description,
                'spent_at' => $spentAt,
                'recorded_by' => $actor->id,
            ]);
            $this->reverseExpense->handle($original, $actor, $reason, $replacement);

            return $replacement;
        }, 3);
    }
}
