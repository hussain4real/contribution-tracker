<?php

declare(strict_types=1);

use App\Models\PaymentRiskModelVersion;
use App\Services\PaymentRiskModelRepository;
use App\Services\PaymentRiskReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('fails closed when no eligible active model or multiple eligible active models exist', function () {
    $repository = app(PaymentRiskModelRepository::class);

    expect(fn () => $repository->active())
        ->toThrow(RuntimeException::class, 'No active');

    PaymentRiskModelVersion::factory()->create([
        'is_active' => true,
        'activation_eligible' => true,
        'activated_at' => now(),
    ]);
    PaymentRiskModelVersion::factory()->create([
        'is_active' => true,
        'activation_eligible' => false,
        'activated_at' => now(),
    ]);

    expect(fn () => $repository->active())
        ->toThrow(RuntimeException::class, 'Multiple active');
});

it('fails closed when the sole active model is not activation eligible', function () {
    PaymentRiskModelVersion::factory()->create([
        'is_active' => true,
        'activation_eligible' => false,
        'activated_at' => now(),
    ]);

    expect(fn () => app(PaymentRiskModelRepository::class)->active())
        ->toThrow(RuntimeException::class, 'not activation-eligible');
});

it('fails closed for missing corrupt or metadata-mismatched active artifacts', function (string $state) {
    if ($state === 'missing') {
        PaymentRiskModelVersion::factory()->create([
            'is_active' => true,
            'activation_eligible' => true,
            'activated_at' => now(),
        ]);
    } elseif ($state === 'corrupt') {
        $json = '{';
        $model = PaymentRiskModelVersion::factory()->create([
            'is_active' => true,
            'activation_eligible' => true,
            'activated_at' => now(),
            'artifact_sha256' => hash('sha256', $json),
        ]);
        Storage::disk('local')->put($model->artifact_path, $json);
    } else {
        installActiveTestPaymentRiskModel();
        $model = PaymentRiskModelVersion::query()->where('is_active', true)->sole();

        $updates = match ($state) {
            'threshold' => ['threshold' => 0.99],
            'training start' => ['training_window_start' => $model->training_window_start->addDay()->toDateString()],
            'training end' => ['training_window_end' => $model->training_window_end->addDay()->toDateString()],
            'trained timestamp' => ['trained_at' => $model->trained_at->subDay()],
            'dataset summary' => ['dataset_summary' => '{}'],
            'evaluation metrics' => ['metrics' => '{}'],
            default => throw new InvalidArgumentException("Unsupported metadata-mismatch state [{$state}]."),
        };

        DB::table('payment_risk_model_versions')
            ->where('id', $model->id)
            ->update($updates);
    }

    expect(fn () => app(PaymentRiskModelRepository::class)->active())
        ->toThrow(RuntimeException::class);
})->with([
    'missing',
    'corrupt',
    'threshold',
    'training start',
    'training end',
    'trained timestamp',
    'dataset summary',
    'evaluation metrics',
]);

it('returns the exact verified model in readiness metadata', function () {
    installActiveTestPaymentRiskModel();
    $model = PaymentRiskModelVersion::query()->where('is_active', true)->sole();
    $state = app(PaymentRiskReadinessService::class)->inspect();

    expect($state['readiness']['status'])->toBe('ready')
        ->and($state['model_version_id'])->toBe($model->id)
        ->and(data_get($state, 'model.version'))->toBe($model->version);
});

it('converts verification failures into a generic unavailable state', function (string $failureType) {
    PaymentRiskModelVersion::factory()->create([
        'is_active' => true,
        'activation_eligible' => true,
        'activated_at' => now(),
    ]);
    Storage::shouldReceive('disk')
        ->once()
        ->andThrow($failureType === 'runtime'
            ? new RuntimeException('/private/models/payment-risk/model.json could not be read.')
            : new Error('Unexpected adapter failure.'));

    $state = app(PaymentRiskReadinessService::class)->inspect();

    expect($state['readiness'])->toBe([
        'status' => 'unavailable',
        'message' => 'The payment-risk model could not be verified safely.',
        'can_refresh' => false,
    ])->and($state['model'])->toBeNull()
        ->and($state['model_version_id'])->toBeNull();
})->with([
    'runtime adapter failure' => 'runtime',
    'unexpected adapter failure' => 'error',
]);
