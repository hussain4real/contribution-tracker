<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\OnboardNgoFamily as OnboardNgoFamilyAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('ngo:onboard
    {--name= : NGO / organization name}
    {--due-day=28 : Monthly contribution due day (1-28)}
    {--admin-name= : Admin full name}
    {--admin-email= : Admin email address}
    {--admin-whatsapp= : Admin WhatsApp number in international format, e.g. +97412345678}
    {--financial-secretary-name= : Financial Secretary full name}
    {--financial-secretary-email= : Financial Secretary email address}
    {--financial-secretary-whatsapp= : Financial Secretary WhatsApp number in international format, e.g. +97412345678}
    {--skip-email : Create accounts without sending onboarding email}
    {--skip-whatsapp : Create accounts without sending onboarding WhatsApp message}')]
#[Description('Onboard an NGO tenant with QAR 100 monthly dues and initial privileged users')]
class OnboardNgoFamily extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OnboardNgoFamilyAction $onboard): int
    {
        $sendWhatsapp = ! (bool) $this->option('skip-whatsapp');

        try {
            $result = $onboard->execute([
                'name' => $this->stringOption('name'),
                'due_day' => $this->integerOption('due-day', 28),
                'admin_name' => $this->stringOption('admin-name'),
                'admin_email' => $this->stringOption('admin-email'),
                'admin_whatsapp' => $this->stringOption('admin-whatsapp'),
                'financial_secretary_name' => $this->stringOption('financial-secretary-name'),
                'financial_secretary_email' => $this->stringOption('financial-secretary-email'),
                'financial_secretary_whatsapp' => $this->stringOption('financial-secretary-whatsapp'),
                'send_email' => ! (bool) $this->option('skip-email'),
                'send_whatsapp' => $sendWhatsapp,
            ]);
        } catch (ValidationException $exception) {
            $this->components->error('Could not onboard the NGO. Please fix the validation errors below.');

            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->line(" - {$message}");
                }
            }

            return self::FAILURE;
        }

        $family = $result['family'];
        $category = $result['category'];
        $users = $result['users'];
        $deliveries = $result['deliveries'];

        $this->components->info("Created {$family->name} with {$category->name} at QAR {$category->monthly_amount}/month.");
        $this->line("Admin: {$users['admin']->name} <{$users['admin']->email}>");
        $this->line("Financial Secretary: {$users['financial_secretary']->name} <{$users['financial_secretary']->email}>");

        foreach ($deliveries['email'] as $role => $sent) {
            $this->line(sprintf('Email delivery [%s]: %s', $role, $sent ? 'sent' : 'skipped'));
        }

        foreach ($deliveries['whatsapp'] as $role => $delivery) {
            $status = $delivery['success'] ? 'sent' : 'failed';
            $error = $delivery['error'] ? " ({$delivery['error']})" : '';

            $this->line("WhatsApp delivery [{$role}]: {$status}{$error}");
        }

        if ($sendWhatsapp && $this->hasFailedWhatsappDelivery($deliveries['whatsapp'])) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function integerOption(string $key, int $default): int
    {
        $value = $this->option($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, array{success: bool, wa_message_id: string|null, error: string|null}>  $deliveries
     */
    private function hasFailedWhatsappDelivery(array $deliveries): bool
    {
        foreach ($deliveries as $delivery) {
            if (! $delivery['success']) {
                return true;
            }
        }

        return false;
    }
}
