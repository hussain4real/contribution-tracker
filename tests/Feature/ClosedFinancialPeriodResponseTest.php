<?php

declare(strict_types=1);

use App\Enums\ReconciliationPeriodStatus;
use App\Models\Family;
use App\Models\PaymentBatch;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use Illuminate\Support\Str;

it('returns useful validation feedback and no receipt for a closed-period payment', function (bool $json): void {
    $family = Family::factory()->create();
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    ReconciliationPeriod::factory()->create(['family_id' => $family->id, 'status' => ReconciliationPeriodStatus::Closed]);
    $this->actingAs($officer)->from(route('payments.index', ['current_family' => $family->slug]));
    $data = ['member_id' => $member->id, 'amount' => 1000, 'paid_at' => now()->toDateString(), 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    $url = route('payments.store', ['current_family' => $family->slug]);
    if ($json) {
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('financial_period');
    } else {
        $this->post($url, $data)->assertRedirect()->assertSessionHas('error', 'This date belongs to a closed reconciliation period. Reopen the period before making changes.');
    }
    expect(PaymentBatch::count())->toBe(0);
})->with([true, false]);
