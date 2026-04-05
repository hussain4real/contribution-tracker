<?php

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetMemberOverview implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Retrieves an overview of all active family members including their role, contribution category, monthly amount, and current month payment status.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $members = User::query()
            ->where('family_id', $this->user->family_id)
            ->active()
            ->with(['familyCategory', 'contributions' => function ($q) {
                $q->where('year', now()->year)
                    ->where('month', now()->month)
                    ->with('payments');
            }])
            ->get();

        $memberData = $members->map(function (User $member) {
            $currentContribution = $member->contributions->first();
            $monthlyAmount = $member->getMonthlyAmount();
            $paidThisMonth = $currentContribution?->payments->sum('amount') ?? 0;

            return [
                'name' => $member->name,
                'role' => $member->role->value,
                'category' => $member->familyCategory?->name ?? $member->category?->value ?? 'None',
                'monthly_amount' => $monthlyAmount,
                'paid_this_month' => $paidThisMonth,
                'outstanding_this_month' => max(0, ($monthlyAmount ?? 0) - $paidThisMonth),
                'status' => $currentContribution?->status->value ?? 'no_contribution',
            ];
        })->toArray();

        return json_encode([
            'total_members' => $members->count(),
            'active_paying_members' => $members->filter(fn ($m) => $m->getMonthlyAmount() !== null)->count(),
            'current_period' => now()->format('F Y'),
            'members' => $memberData,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
