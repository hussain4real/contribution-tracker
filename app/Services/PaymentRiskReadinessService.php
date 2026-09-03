<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

final class PaymentRiskReadinessService
{
    private const string UNAVAILABLE_MESSAGE = 'The payment-risk model could not be verified safely.';

    public function __construct(private readonly PaymentRiskModelRepository $models) {}

    /**
     * @return array{
     *     readiness: array{status: string, message: string, can_refresh: bool},
     *     model: array{version: string, trained_at: string, training_window: string, prevalence: float, threshold: float}|null,
     *     model_version_id: int|null
     * }
     */
    public function inspect(): array
    {
        try {
            $loaded = $this->models->active();
            $model = $loaded['model'];
            $prevalence = data_get($loaded['artifact'], 'training.overdue_prevalence');

            return [
                'readiness' => [
                    'status' => 'ready',
                    'message' => 'The active advisory model passed its data, integrity, freshness, and held-out activation gates.',
                    'can_refresh' => true,
                ],
                'model' => [
                    'version' => $model->version,
                    'trained_at' => $model->trained_at->toDateString(),
                    'training_window' => $model->training_window_start->format('M Y').' – '.$model->training_window_end->format('M Y'),
                    'prevalence' => is_numeric($prevalence) ? (float) $prevalence : 0.0,
                    'threshold' => $model->threshold,
                ],
                'model_version_id' => $model->id,
            ];
        } catch (RuntimeException $exception) {
            return [
                'readiness' => [
                    'status' => 'unavailable',
                    'message' => self::UNAVAILABLE_MESSAGE,
                    'can_refresh' => false,
                ],
                'model' => null,
                'model_version_id' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'readiness' => [
                    'status' => 'unavailable',
                    'message' => self::UNAVAILABLE_MESSAGE,
                    'can_refresh' => false,
                ],
                'model' => null,
                'model_version_id' => null,
            ];
        }
    }
}
