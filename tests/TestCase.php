<?php

declare(strict_types=1);

namespace Tests;

use AllowDynamicProperties;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use App\Policies\ContributionPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FundAdjustmentPolicy;
use App\Policies\PaymentPolicy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * @property User $admin
 * @property User $archivedMember
 * @property User $employedMember
 * @property User $financialSecretary
 * @property User $member
 * @property User $otherMember
 * @property User $outsider
 * @property User $payingMember
 * @property User $recorder
 * @property User $studentMember
 * @property User $targetMember
 * @property User $user
 * @property Family $family
 * @property Family $otherFamily
 * @property Contribution $contribution
 * @property Contribution $employedContribution
 * @property Contribution $memberContribution
 * @property Contribution $otherContribution
 * @property Contribution $studentContribution
 * @property Expense $expense
 * @property FundAdjustment $fundAdjustment
 * @property Payment $otherPayment
 * @property Payment $payment
 * @property ContributionPolicy|ExpensePolicy|FundAdjustmentPolicy|PaymentPolicy $policy
 */
#[AllowDynamicProperties]
abstract class TestCase extends BaseTestCase
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function renderTestViewWithoutVite(string $view, array $data = []): string
    {
        $this->withoutVite();

        return (string) $this->view($view, $data);
    }
}
