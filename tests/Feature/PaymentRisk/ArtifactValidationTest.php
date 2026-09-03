<?php

declare(strict_types=1);

use App\Actions\InstallPaymentRiskModel;
use App\Models\PaymentRiskModelVersion;
use App\Services\PaymentRiskArtifactValidator;
use App\Services\PaymentRiskModelRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

function storedPaymentRiskArtifactContents(string $path): string
{
    $contents = Storage::disk('local')->get($path);

    if (! is_string($contents)) {
        throw new RuntimeException('Expected the stored payment-risk artifact to contain a string.');
    }

    return $contents;
}

it('validates the exact payment-risk artifact schema', function () {
    $validated = app(PaymentRiskArtifactValidator::class)
        ->validateJson(json_encode(validPaymentRiskArtifact(), JSON_THROW_ON_ERROR));

    expect($validated['artifact_schema_version'])->toBe(PaymentRiskArtifactValidator::SCHEMA_VERSION)
        ->and(data_get($validated, 'training.feature_names'))->toBe(PaymentRiskArtifactValidator::FEATURE_NAMES)
        ->and(app(PaymentRiskArtifactValidator::class)->isActivationEligible($validated))->toBeTrue();
});

it('accepts Python-canonicalized activation and data-quality maps regardless of key order', function () {
    $activationChecks = [
        'consented_anonymized_provenance' => true,
        'data_quality_gate_passed' => true,
        'validation_recall_at_least_0_70' => true,
        'held_out_f1_beats_both_baselines' => true,
        'held_out_balanced_accuracy_above_0_5' => true,
        'held_out_brier_beats_training_prevalence' => true,
    ];
    $dataQualityGates = [
        'minimum_rows' => true,
        'minimum_distinct_members' => true,
        'minimum_complete_periods' => true,
        'minimum_on_time_class' => true,
        'minimum_overdue_class' => true,
    ];
    ksort($activationChecks);
    ksort($dataQualityGates);
    $artifact = paymentRiskArtifactWith(validPaymentRiskArtifact(), 'evaluation.activation_checks', $activationChecks);
    $artifact = paymentRiskArtifactWith($artifact, 'data_quality.gates', $dataQualityGates);
    $validator = app(PaymentRiskArtifactValidator::class);
    $validated = $validator->validateJson(json_encode($artifact, JSON_THROW_ON_ERROR));

    expect($validator->isActivationEligible($validated))->toBeTrue();
});

it('validates artifacts from files and rejects unreadable paths empty payloads and malformed JSON', function () {
    $validator = app(PaymentRiskArtifactValidator::class);
    $file = paymentRiskArtifactFile(validPaymentRiskArtifact());

    expect($validator->validateFile($file->getPathname()))->toHaveKey('model_version', 'test-model-v1')
        ->and(fn () => $validator->validateFile($file->getPathname().'.missing'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validateJson(''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validateJson('{'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validateJson('[]'))
        ->toThrow(InvalidArgumentException::class);
});

it('preserves the portable synthetic golden fixture while keeping it activation-ineligible', function () {
    $contents = file_get_contents(dirname(__DIR__, 3).'/ml/payment-risk/tests/fixtures/golden-scoring.json');

    expect($contents)->toBeString();

    if (! is_string($contents)) {
        throw new RuntimeException('Expected the Python golden scoring fixture to be readable.');
    }

    $fixture = decodeJsonObject($contents);
    $artifactJson = json_encode($fixture['artifact'] ?? null, JSON_THROW_ON_ERROR);
    $artifact = app(PaymentRiskArtifactValidator::class)->validateJson($artifactJson);

    expect(data_get($artifact, 'training.source_type'))->toBe('synthetic')
        ->and(app(PaymentRiskArtifactValidator::class)->isActivationEligible($artifact))->toBeFalse();
});

it('rejects malformed payment-risk artifact contracts', function (string $path, mixed $value) {
    $artifact = paymentRiskArtifactWith(validPaymentRiskArtifact(), $path, $value);

    expect(fn () => app(PaymentRiskArtifactValidator::class)->validateJson(
        json_encode($artifact, JSON_THROW_ON_ERROR),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'schema version' => ['artifact_schema_version', 'unsupported'],
    'model version' => ['model_version', 'invalid version'],
    'timestamp type' => ['generated_at', 123],
    'timestamp value' => ['generated_at', 'not-a-timestamp'],
    'timestamp without timezone' => ['generated_at', '2026-08-10T12:00:00'],
    'timestamp parser failure' => ['generated_at', '2026-08-10T12:00:00+99:99'],
    'timestamp calendar value' => ['generated_at', '2026-02-30T12:00:00Z'],
    'source type' => ['training.source_type', 'scraped'],
    'dataset id' => ['training.dataset_id', 'x'],
    'feature contract' => ['training.feature_contract_version', 'v2'],
    'feature order' => ['training.feature_names', array_reverse(PaymentRiskArtifactValidator::FEATURE_NAMES)],
    'window order' => ['training.window.start', now()->addYear()->toDateString()],
    'seed' => ['training.seed', 1],
    'prevalence' => ['training.overdue_prevalence', 1.1],
    'split policy' => ['training.split.policy', 'random'],
    'preprocessing contract' => ['preprocessing.type', 'min_max'],
    'model type' => ['model.type', 'tree'],
    'model semantics' => ['model.solver', 'saga'],
    'decision classes' => ['decision.negative_class', 'paid'],
    'decision threshold' => ['decision.threshold', 0.0],
    'decision bands shape' => ['decision.risk_bands', []],
    'decision bands semantics' => ['decision.risk_bands.0.label', 'low'],
    'negative data-quality count' => ['data_quality.on_time_count', -1],
    'data-quality class sum' => ['data_quality.on_time_count', 359],
    'data-quality gate type' => ['data_quality.gate_passed', 'true'],
    'data-quality gates keys' => ['data_quality.gates', ['minimum_rows' => true]],
    'data-quality gates value type' => ['data_quality.gates.minimum_rows', 1],
    'data-quality provenance' => ['data_quality.source_type', 'synthetic'],
    'data-quality dataset' => ['data_quality.dataset_id', 'different'],
    'activation decision type' => ['evaluation.activation_eligible', 'true'],
    'metric range' => ['evaluation.held_out.overdue_f1', 1.1],
    'split boundaries' => ['training.split.validation.start', now()->subMonths(3)->startOfMonth()->toDateString()],
    'incomplete held-out period' => ['training.split.held_out.end', now()->startOfMonth()->toDateString()],
    'split period count type' => ['training.split.training.period_count', '10'],
    'split period allocation' => ['training.split.training.period_count', 9],
    'split row sum' => ['training.split.validation.row_count', 99],
    'split positive row count' => ['training.split.validation.row_count', 0],
    'activation policy' => ['evaluation.activation_policy_version', 'v2'],
    'selection semantics' => ['evaluation.selection.objective', 'accuracy'],
    'validation recall' => ['evaluation.validation.recall_overdue', 1.1],
    'activation checks keys' => ['evaluation.activation_checks', ['data_quality_gate_passed' => true]],
    'activation check type' => ['evaluation.activation_checks.data_quality_gate_passed', 'true'],
    'explanation shape' => ['explanation.factors', []],
    'explanation order' => ['explanation.factors.0.feature', 'days_until_due'],
    'explanation label' => ['explanation.factors.0.label', ''],
    'explanation coefficient' => ['explanation.factors.0.coefficient', 'bad'],
    'explanation sign' => ['explanation.factors.0.sign', 'unknown'],
    'vector length' => ['model.coefficients', []],
    'vector value' => ['preprocessing.scale.0', -1.0],
    'numeric field' => ['model.intercept', 'bad'],
    'string field' => ['model_version', 123],
    'integer field' => ['data_quality.valid_row_count', '600'],
    'object field' => ['training.split', []],
    'date format' => ['training.window.start', '08/10/2026'],
    'date value' => ['training.window.start', '2026-02-30'],
]);

it('accepts only the five-minute generation clock-skew boundary', function () {
    $now = now()->startOfSecond();
    CarbonImmutable::setTestNow($now);

    try {
        $atBoundary = paymentRiskArtifactWith(
            validPaymentRiskArtifact(),
            'generated_at',
            $now->addMinutes(5)->toIso8601String(),
        );
        $beyondBoundary = paymentRiskArtifactWith(
            validPaymentRiskArtifact(),
            'generated_at',
            $now->addMinutes(5)->addSecond()->toIso8601String(),
        );
        $validator = app(PaymentRiskArtifactValidator::class);

        expect($validator->validateJson(json_encode($atBoundary, JSON_THROW_ON_ERROR)))
            ->toHaveKey('model_version')
            ->and(fn () => $validator->validateJson(json_encode($beyondBoundary, JSON_THROW_ON_ERROR)))
            ->toThrow(InvalidArgumentException::class, 'future');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('rejects invalid synthetic split policies and non-string object keys', function () {
    $synthetic = validPaymentRiskArtifact();
    $synthetic = paymentRiskArtifactWith($synthetic, 'training.source_type', 'synthetic');
    $synthetic = paymentRiskArtifactWith($synthetic, 'data_quality.source_type', 'synthetic');
    $synthetic = paymentRiskArtifactWith($synthetic, 'training.split.policy', 'random');
    $invalidObject = ['node' => [1 => 'numeric key', 'valid' => 'value']];
    $validator = app(PaymentRiskArtifactValidator::class);

    expect(fn () => $validator->validateJson(json_encode($synthetic, JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->associativeArrayAt($invalidObject, 'node'))
        ->toThrow(InvalidArgumentException::class);
});

it('recomputes every activation gate instead of trusting supplied flags', function () {
    $validator = app(PaymentRiskArtifactValidator::class);
    $tooSmall = validPaymentRiskArtifact();
    $tooSmall = paymentRiskArtifactWith($tooSmall, 'data_quality.valid_row_count', 499);
    $tooSmall = paymentRiskArtifactWith($tooSmall, 'data_quality.gates.minimum_rows', false);
    $thresholdMismatch = paymentRiskArtifactWith(
        validPaymentRiskArtifact(),
        'evaluation.selection.validation_threshold',
        '0.55',
    );
    $weakRecall = paymentRiskArtifactWith(
        validPaymentRiskArtifact(),
        'evaluation.validation.recall_overdue',
        0.69,
    );
    $falseCheck = paymentRiskArtifactWith(
        validPaymentRiskArtifact(),
        'evaluation.activation_checks.held_out_f1_beats_both_baselines',
        false,
    );
    $mutations = [
        paymentRiskArtifactWith(validPaymentRiskArtifact(), 'training.source_type', 'synthetic'),
        paymentRiskArtifactWith(validPaymentRiskArtifact(), 'preprocessing.type', 'min_max'),
        paymentRiskArtifactWith(validPaymentRiskArtifact(), 'model.solver', 'saga'),
        paymentRiskArtifactWith(validPaymentRiskArtifact(), 'decision.negative_class', 'paid'),
        paymentRiskArtifactWith(validPaymentRiskArtifact(), 'evaluation.selection.objective', 'accuracy'),
        $tooSmall,
        $thresholdMismatch,
        $weakRecall,
        $falseCheck,
        paymentRiskArtifactWith(validPaymentRiskArtifact(), 'data_quality.gates', []),
    ];

    foreach ($mutations as $artifact) {
        expect($validator->isActivationEligible($artifact))->toBeFalse();
    }
});

it('rejects unsupported fields feature order and unsafe scaling', function () {
    $validator = app(PaymentRiskArtifactValidator::class);
    $extraField = validPaymentRiskArtifact();
    $extraField['member_email'] = 'private@example.test';
    $reordered = validPaymentRiskArtifact();
    $reordered = paymentRiskArtifactWith($reordered, 'training.feature_names', array_reverse(PaymentRiskArtifactValidator::FEATURE_NAMES));
    $zeroScale = validPaymentRiskArtifact();
    $zeroScale = paymentRiskArtifactWith($zeroScale, 'preprocessing.scale.0', 0.0);

    expect(fn () => $validator->validateJson(json_encode($extraField, JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validateJson(json_encode($reordered, JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validateJson(json_encode($zeroScale, JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidArgumentException::class);
});

it('independently enforces data volume class balance and held-out activation gates', function () {
    $validator = app(PaymentRiskArtifactValidator::class);
    $tooSmall = validPaymentRiskArtifact();
    $tooSmall = paymentRiskArtifactWith($tooSmall, 'data_quality.valid_row_count', 499);
    $weakF1 = validPaymentRiskArtifact();
    $weakF1 = paymentRiskArtifactWith($weakF1, 'evaluation.held_out.overdue_f1', 0.58);
    $weakBalance = validPaymentRiskArtifact();
    $weakBalance = paymentRiskArtifactWith($weakBalance, 'evaluation.held_out.balanced_accuracy', 0.5);
    $weakCalibration = validPaymentRiskArtifact();
    $weakCalibration = paymentRiskArtifactWith($weakCalibration, 'evaluation.held_out.brier_score', 0.24);
    $syntheticSource = validPaymentRiskArtifact();
    $syntheticSource = paymentRiskArtifactWith($syntheticSource, 'training.source_type', 'synthetic');

    expect($validator->isActivationEligible($tooSmall))->toBeFalse()
        ->and($validator->isActivationEligible($weakF1))->toBeFalse()
        ->and($validator->isActivationEligible($weakBalance))->toBeFalse()
        ->and($validator->isActivationEligible($weakCalibration))->toBeFalse()
        ->and($validator->isActivationEligible($syntheticSource))->toBeFalse();
});

it('installs a checksummed private artifact and activates exactly one model', function () {
    Storage::fake('local');
    $installer = app(InstallPaymentRiskModel::class);
    $firstFile = paymentRiskArtifactFile(validPaymentRiskArtifact());
    $first = $installer->handle($firstFile->getPathname(), activate: true);
    $secondArtifact = validPaymentRiskArtifact();
    $secondArtifact['model_version'] = 'test-model-v2';
    $secondFile = paymentRiskArtifactFile($secondArtifact);
    $second = $installer->handle($secondFile->getPathname(), activate: true);

    expect($first->refresh()->is_active)->toBeFalse()
        ->and($second->is_active)->toBeTrue()
        ->and(PaymentRiskModelVersion::query()->where('is_active', true)->count())->toBe(1)
        ->and($second->artifact_sha256)->toBe(hash('sha256', storedPaymentRiskArtifactContents($second->artifact_path)));
    Storage::disk('local')->assertExists($first->artifact_path);
    Storage::disk('local')->assertExists($second->artifact_path);
    Storage::disk('public')->assertMissing($second->artifact_path);
});

it('requires an authentic sibling checksum manifest and model filename', function () {
    Storage::fake('local');
    $missingManifest = paymentRiskArtifactFile(validPaymentRiskArtifact());
    File::delete(dirname($missingManifest->getPathname()).'/checksums.sha256');
    $emptyManifest = paymentRiskArtifactFile(validPaymentRiskArtifact(), ' ');
    $wrongDigest = paymentRiskArtifactFile(
        validPaymentRiskArtifact(),
        str_repeat('0', 64).'  model.json'.PHP_EOL,
    );
    $duplicateEntryJson = json_encode(validPaymentRiskArtifact(), JSON_THROW_ON_ERROR);
    $duplicateEntries = paymentRiskArtifactFile(
        validPaymentRiskArtifact(),
        hash('sha256', $duplicateEntryJson).'  model.json'.PHP_EOL.hash('sha256', $duplicateEntryJson).'  model.json'.PHP_EOL,
    );
    $wrongName = paymentRiskArtifactFile(validPaymentRiskArtifact(), filename: 'artifact.json');
    $installer = app(InstallPaymentRiskModel::class);

    foreach ([$missingManifest, $emptyManifest, $wrongDigest, $duplicateEntries, $wrongName] as $file) {
        expect(fn () => $installer->handle($file->getPathname()))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('rejects public artifact disks by name or configured visibility', function (string $disk, ?string $visibility) {
    Storage::fake('local');
    Config::set('payment-risk.artifact_disk', $disk);
    Config::set("filesystems.disks.{$disk}", [
        'driver' => 'local',
        'root' => storage_path("framework/testing/disks/{$disk}"),
        'visibility' => $visibility,
    ]);

    expect(fn () => app(InstallPaymentRiskModel::class)->handle(
        paymentRiskArtifactFile(validPaymentRiskArtifact())->getPathname(),
    ))->toThrow(InvalidArgumentException::class, 'private filesystem');
})->with([
    'reserved public disk' => ['public', null],
    'custom publicly visible disk' => ['risk-export', 'public'],
]);

it('fails closed when private artifact storage rejects the write', function () {
    $disk = typedMock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);

    expect(fn () => app(InstallPaymentRiskModel::class)->handle(
        paymentRiskArtifactFile(validPaymentRiskArtifact())->getPathname(),
    ))->toThrow(RuntimeException::class, 'private storage');
});

it('returns the same immutable model for an identical reinstall', function () {
    Storage::fake('local');
    $file = paymentRiskArtifactFile(validPaymentRiskArtifact());
    $installer = app(InstallPaymentRiskModel::class);
    $first = $installer->handle($file->getPathname());
    $second = $installer->handle($file->getPathname());

    expect($second->is($first))->toBeTrue()
        ->and(PaymentRiskModelVersion::query()->count())->toBe(1);
});

it('keeps ineligible and stale artifacts inactive', function () {
    Storage::fake('local');
    $ineligibleArtifact = validPaymentRiskArtifact();
    $ineligibleArtifact['model_version'] = 'ineligible-model';
    $ineligibleArtifact = paymentRiskArtifactWith($ineligibleArtifact, 'data_quality.valid_row_count', 499);
    $ineligibleArtifact = paymentRiskArtifactWith($ineligibleArtifact, 'data_quality.on_time_count', 259);
    $ineligibleArtifact = paymentRiskArtifactWith($ineligibleArtifact, 'data_quality.gate_passed', false);
    $ineligibleArtifact = paymentRiskArtifactWith($ineligibleArtifact, 'data_quality.gates.minimum_rows', false);
    $ineligibleArtifact = paymentRiskArtifactWith($ineligibleArtifact, 'training.split.training.row_count', 299);
    $ineligibleFile = paymentRiskArtifactFile($ineligibleArtifact);

    expect(fn () => app(InstallPaymentRiskModel::class)->handle($ineligibleFile->getPathname(), activate: true))
        ->toThrow(InvalidArgumentException::class);

    expect(PaymentRiskModelVersion::query()->where('version', 'ineligible-model')->value('is_active'))->toBeFalse();

    $staleArtifact = validPaymentRiskArtifact();
    $staleArtifact['model_version'] = 'stale-model';
    $staleStart = now()->subYears(3)->startOfMonth();
    $staleEnd = now()->subYears(2)->startOfMonth();
    $staleArtifact = paymentRiskArtifactWith($staleArtifact, 'training.window.start', $staleStart->toDateString());
    $staleArtifact = paymentRiskArtifactWith($staleArtifact, 'training.window.end', $staleEnd->toDateString());
    $staleArtifact = paymentRiskArtifactWith($staleArtifact, 'training.split.training.start', $staleStart->toDateString());
    $staleArtifact = paymentRiskArtifactWith($staleArtifact, 'training.split.training.end', $staleEnd->toDateString());
    $staleArtifact = paymentRiskArtifactWith($staleArtifact, 'training.split.validation.start', $staleEnd->addMonth()->toDateString());
    $staleArtifact = paymentRiskArtifactWith($staleArtifact, 'training.split.validation.end', $staleEnd->addMonth()->toDateString());
    $staleArtifact = paymentRiskArtifactWith($staleArtifact, 'training.split.held_out.start', $staleEnd->addMonths(2)->toDateString());
    $staleArtifact = paymentRiskArtifactWith($staleArtifact, 'training.split.held_out.end', $staleEnd->addMonths(2)->toDateString());
    $staleFile = paymentRiskArtifactFile($staleArtifact);

    expect(fn () => app(InstallPaymentRiskModel::class)->handle($staleFile->getPathname(), activate: true))
        ->toThrow(InvalidArgumentException::class);

    $staleModel = PaymentRiskModelVersion::query()->where('version', 'stale-model')->sole();

    expect($staleModel->is_active)->toBeFalse();

    DB::table('payment_risk_model_versions')
        ->where('id', $staleModel->id)
        ->update(['is_active' => true, 'activated_at' => now()]);

    expect(fn () => app(PaymentRiskModelRepository::class)->active())
        ->toThrow(RuntimeException::class, 'stale');
});

it('rejects version checksum reuse and fails closed for a corrupt active artifact', function () {
    Storage::fake('local');
    $installer = app(InstallPaymentRiskModel::class);
    $artifact = validPaymentRiskArtifact();
    $file = paymentRiskArtifactFile($artifact);
    $model = $installer->handle($file->getPathname(), activate: true);
    $changed = $artifact;
    $changed = paymentRiskArtifactWith($changed, 'model.intercept', 1.5);
    $changedFile = paymentRiskArtifactFile($changed);

    expect(fn () => $installer->handle($changedFile->getPathname()))
        ->toThrow(InvalidArgumentException::class);

    expect(hash('sha256', storedPaymentRiskArtifactContents($model->artifact_path)))
        ->toBe($model->artifact_sha256);

    Storage::disk('local')->put($model->artifact_path, '{"corrupt":true}');

    expect(fn () => app(PaymentRiskModelRepository::class)->active())
        ->toThrow(RuntimeException::class, 'checksum');
});

it('keeps installed model metadata immutable', function () {
    $model = PaymentRiskModelVersion::factory()->create();

    expect(fn () => $model->update(['threshold' => 0.9]))
        ->toThrow(LogicException::class);
});
