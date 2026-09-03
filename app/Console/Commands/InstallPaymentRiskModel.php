<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\InstallPaymentRiskModel as InstallPaymentRiskModelAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payment-risk:install {artifact : Path to a payment-risk model.json artifact} {--activate : Activate the model after validation}')]
#[Description('Validate and install an immutable offline-trained payment-risk artifact')]
class InstallPaymentRiskModel extends Command
{
    public function handle(InstallPaymentRiskModelAction $install): int
    {
        $artifact = $this->argument('artifact');

        if (trim($artifact) === '') {
            $this->error('An artifact path is required.');

            return self::FAILURE;
        }

        try {
            $model = $install->handle($artifact, (bool) $this->option('activate'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Version', 'Checksum', 'Eligible', 'Active'], [[
            $model->version,
            $model->artifact_sha256,
            $model->activation_eligible ? 'yes' : 'no',
            $model->is_active ? 'yes' : 'no',
        ]]);

        return self::SUCCESS;
    }
}
