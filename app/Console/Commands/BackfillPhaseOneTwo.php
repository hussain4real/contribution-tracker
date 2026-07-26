<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\FamilyMembershipCategoryAssignment;
use App\Models\Payment;
use App\Models\PaymentBatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('app:backfill-phase-one-two {--chunk=200 : Records processed per chunk}')]
#[Description('Backfill canonical memberships, category snapshots, and immutable payment receipts')]
class BackfillPhaseOneTwo extends Command
{
    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $membershipCount = $this->backfillMemberships($chunkSize);
        $contributionCount = $this->backfillContributions($chunkSize);
        $paymentCount = $this->backfillPayments($chunkSize);

        $this->components->info(
            "Backfill complete: {$membershipCount} memberships, {$contributionCount} contributions, {$paymentCount} receipts.",
        );

        return self::SUCCESS;
    }

    private function backfillMemberships(int $chunkSize): int
    {
        $updated = 0;

        FamilyMembership::query()
            ->with(['user', 'family.categories'])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $memberships) use (&$updated): void {
                foreach ($memberships as $membership) {
                    DB::transaction(function () use ($membership, &$updated): void {
                        $attributes = [];
                        $user = $membership->user;

                        if ($membership->display_name === null) {
                            $attributes['display_name'] = $user->name;
                        }

                        if (
                            $membership->archived_at === null
                            && $user->archived_at !== null
                            && $user->family_id === $membership->family_id
                        ) {
                            $attributes['archived_at'] = $user->archived_at;
                            $attributes['archive_reason'] = 'Migrated from the legacy account archive state.';
                            DB::table('users')->where('id', $user->id)->update(['archived_at' => null]);
                        }

                        $category = $membership->familyCategory;

                        if (! $category instanceof FamilyCategory && $membership->category !== null) {
                            $category = $membership->family->categories
                                ->firstWhere('slug', $membership->category->value);

                            if ($category instanceof FamilyCategory) {
                                $attributes['family_category_id'] = $category->id;
                                $attributes['category'] = null;
                            }
                        }

                        if ($attributes !== []) {
                            DB::table('family_members')->where('id', $membership->id)->update([
                                ...$attributes,
                                'updated_at' => now(),
                            ]);
                            $updated++;
                        }

                        if (
                            $category instanceof FamilyCategory
                            && ! FamilyMembershipCategoryAssignment::query()
                                ->where('family_membership_id', $membership->id)
                                ->exists()
                        ) {
                            FamilyMembershipCategoryAssignment::query()->create([
                                'family_membership_id' => $membership->id,
                                'family_category_id' => $category->id,
                                'assigned_by' => null,
                                'category_name' => $category->name,
                                'category_slug' => $category->slug,
                                'monthly_amount' => $category->monthly_amount,
                                'effective_from' => ($membership->created_at ?? now())->copy()->startOfMonth()->toDateString(),
                            ]);
                        }
                    });
                }
            });

        return $updated;
    }

    private function backfillContributions(int $chunkSize): int
    {
        $updated = 0;

        Contribution::query()
            ->whereNull('category_name')
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $contributions) use (&$updated): void {
                foreach ($contributions as $contribution) {
                    $membership = FamilyMembership::query()
                        ->with(['familyCategory', 'categoryAssignments.category'])
                        ->where('family_id', $contribution->family_id)
                        ->where('user_id', $contribution->user_id)
                        ->first();

                    if (! $membership instanceof FamilyMembership) {
                        continue;
                    }

                    $snapshot = $membership->contributionCategorySnapshot($contribution->year, $contribution->month);

                    DB::table('contributions')->where('id', $contribution->id)->update([
                        ...$snapshot,
                        'category_amount' => $snapshot['category_amount'] ?? $contribution->expected_amount,
                        'updated_at' => now(),
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }

    private function backfillPayments(int $chunkSize): int
    {
        $created = 0;

        Payment::query()
            ->whereNull('payment_batch_id')
            ->with(['contribution.user'])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $payments) use (&$created): void {
                foreach ($payments as $payment) {
                    DB::transaction(function () use ($payment, &$created): void {
                        $existing = PaymentBatch::query()
                            ->where('idempotency_key', "legacy-payment:{$payment->id}")
                            ->first();

                        if ($existing instanceof PaymentBatch) {
                            DB::table('payments')->where('id', $payment->id)->update([
                                'payment_batch_id' => $existing->id,
                                'updated_at' => now(),
                            ]);

                            return;
                        }

                        $contribution = $payment->contribution()->with('user')->firstOrFail();

                        Family::query()->whereKey($contribution->family_id)->lockForUpdate()->firstOrFail();
                        $membership = FamilyMembership::query()
                            ->where('family_id', $contribution->family_id)
                            ->where('user_id', $contribution->user_id)
                            ->first();
                        $maximumReceiptNumber = PaymentBatch::query()
                            ->where('family_id', $contribution->family_id)
                            ->max('receipt_number');
                        $receiptNumber = is_numeric($maximumReceiptNumber)
                            ? ((int) $maximumReceiptNumber) + 1
                            : 1;
                        $batch = PaymentBatch::query()->create([
                            'family_id' => $contribution->family_id,
                            'family_membership_id' => $membership?->id,
                            'member_name' => $membership instanceof FamilyMembership
                                ? $membership->displayName()
                                : ($contribution->user->name ?? 'Former member'),
                            'total_amount' => $payment->amount,
                            'paid_at' => $payment->paid_at,
                            'method' => PaymentMethod::Other,
                            'source' => PaymentSource::Backfill,
                            'recorded_by' => $payment->recorded_by,
                            'notes' => $payment->notes,
                            'receipt_number' => $receiptNumber,
                            'idempotency_key' => "legacy-payment:{$payment->id}",
                        ]);

                        DB::table('payments')->where('id', $payment->id)->update([
                            'payment_batch_id' => $batch->id,
                            'updated_at' => now(),
                        ]);
                        $created++;
                    }, 3);
                }
            });

        return $created;
    }
}
