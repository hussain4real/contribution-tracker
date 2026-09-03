<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ScoreFamilyPaymentRisk;
use App\Enums\PaymentRiskAdvisoryBand;
use App\Enums\PaymentRiskHistoryTier;
use App\Http\Requests\PaymentRiskIndexRequest;
use App\Http\Requests\RefreshPaymentRiskRequest;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\PaymentRiskPrediction;
use App\Models\User;
use App\Services\PaymentRiskReadinessService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PaymentRiskController extends Controller
{
    private const string REFRESH_UNAVAILABLE_MESSAGE = 'Payment-risk insights are temporarily unavailable. No predictions were changed.';

    public function __construct(
        private readonly PaymentRiskReadinessService $readiness,
        private readonly ScoreFamilyPaymentRisk $scoreFamily,
    ) {}

    public function index(PaymentRiskIndexRequest $request): Response
    {
        $family = $this->family($request->user());
        $validated = $request->validated();
        $period = is_string($validated['period'] ?? null) ? $validated['period'] : now()->format('Y-m');
        [$year, $month] = array_map('intval', explode('-', $period));
        $band = is_string($validated['band'] ?? null) ? $validated['band'] : null;
        $state = $this->readiness->inspect();
        $predictions = collect();

        if ($state['readiness']['status'] === 'ready') {
            $predictionQuery = PaymentRiskPrediction::query()
                ->where('family_id', $family->id)
                ->where('payment_risk_model_version_id', $state['model_version_id'])
                ->whereIn(
                    'contribution_id',
                    Contribution::query()
                        ->where('family_id', $family->id)
                        ->forMonth($year, $month)
                        ->select('id'),
                );

            if ($band !== null) {
                $predictionQuery->where('advisory_band', $band);
            }

            $predictions = $predictionQuery
                ->with([
                    'contribution:id,family_id,user_id,year,month,expected_amount,due_date',
                    'membership:id,family_id,user_id,display_name',
                    'membership.user:id,name',
                ])
                ->orderByRaw('probability IS NULL')
                ->orderByDesc('probability')
                ->orderBy('id')
                ->get()
                ->map(function (PaymentRiskPrediction $prediction) use ($family, $period): array {
                    $contribution = $prediction->contribution;
                    $membership = $prediction->membership;

                    if (
                        ! $contribution instanceof Contribution
                        || ! $membership instanceof FamilyMembership
                        || $prediction->family_id !== $family->id
                        || $contribution->family_id !== $family->id
                        || $membership->family_id !== $family->id
                        || $membership->user_id !== $contribution->user_id
                    ) {
                        throw new RuntimeException('A payment-risk prediction has inconsistent tenant, contribution, or membership data.');
                    }

                    $factors = array_slice($prediction->factors, 0, 3);
                    $warning = $prediction->history_tier->warning();

                    if ($prediction->history_tier === PaymentRiskHistoryTier::Unavailable && isset($factors[0])) {
                        $warning = $factors[0];
                    }

                    return [
                        'contribution_id' => $prediction->contribution_id,
                        'member_name' => $membership->displayName(),
                        'period' => $period,
                        'amount' => $contribution->expected_amount,
                        'due_date' => $contribution->due_date->toDateString(),
                        'cutoff_at' => $prediction->cutoff_at->toIso8601String(),
                        'probability' => $prediction->probability,
                        'advisory_band' => $prediction->advisory_band?->value,
                        'history_tier' => $prediction->history_tier->value,
                        'history_periods' => $prediction->history_periods,
                        'factors' => $factors,
                        'warning' => $warning,
                    ];
                });
        }

        return Inertia::render('PaymentRisk/Index', [
            'readiness' => $state['readiness'],
            'model' => $state['model'],
            'filters' => ['period' => $period, 'band' => $band],
            'predictions' => $predictions->values(),
            'summary' => [
                'total' => $predictions->count(),
                'priority' => $predictions->where('advisory_band', PaymentRiskAdvisoryBand::PriorityReview->value)->count(),
                'routine' => $predictions->where('advisory_band', PaymentRiskAdvisoryBand::RoutineReview->value)->count(),
                'unavailable' => $predictions->whereNull('advisory_band')->count(),
            ],
        ]);
    }

    public function refresh(RefreshPaymentRiskRequest $request): RedirectResponse
    {
        $family = $this->family($request->user());
        $period = $request->string('period')->toString();

        [$year, $month] = array_map('intval', explode('-', $period));

        try {
            $result = $this->scoreFamily->handle($family, $year, $month);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('payment-risk.index', [
                'current_family' => $family->slug,
                'period' => $period,
            ])->with('error', self::REFRESH_UNAVAILABLE_MESSAGE);
        }

        return redirect()->route('payment-risk.index', [
            'current_family' => $family->slug,
            'period' => $period,
        ])->with('success', "Payment-risk refresh completed: {$result['created']} new, {$result['existing']} unchanged.");
    }

    private function family(?User $user): Family
    {
        abort_unless($user instanceof User, 403);
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);

        return $family;
    }
}
