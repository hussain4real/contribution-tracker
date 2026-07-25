<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use App\Models\Family;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('generate-reports') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $family = $user instanceof User ? ($user->currentFamily ?? $user->family) : null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'report_type' => ['required', Rule::enum(ReportType::class)],
            'format' => ['required', Rule::enum(ReportFormat::class)],
            'filters' => ['required', 'array'],
            'filters.date_from' => ['required', 'date_format:Y-m-d'],
            'filters.date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:filters.date_from'],
            'filters.member_id' => [
                'nullable',
                Rule::requiredIf($this->input('report_type') === ReportType::MemberStatement->value),
                'integer',
                Rule::exists('family_members', 'user_id')->where('family_id', $family instanceof Family ? $family->id : 0),
            ],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', Rule::in(['email', 'whatsapp'])],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'string', 'max:190'],
            'frequency' => ['required', Rule::enum(ReportScheduleFrequency::class)],
            'timezone' => ['required', 'timezone'],
            'next_run_at' => ['required', 'date'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['timezone', 'next_run_at'])) {
                return;
            }

            $timezone = $this->string('timezone')->toString();
            $nextRunAt = $this->string('next_run_at')->toString();

            if (CarbonImmutable::parse($nextRunAt, $timezone)->isBefore(now())) {
                $validator->errors()->add('next_run_at', 'The next run at field must be a date after or equal to now.');
            }
        }];
    }
}
