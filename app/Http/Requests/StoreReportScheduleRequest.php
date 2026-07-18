<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('generate-reports') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'report_type' => ['required', Rule::enum(ReportType::class)],
            'format' => ['required', Rule::enum(ReportFormat::class)],
            'filters' => ['required', 'array'],
            'filters.date_from' => ['required', 'date_format:Y-m-d'],
            'filters.date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:filters.date_from'],
            'filters.member_id' => ['nullable', 'integer'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', Rule::in(['email', 'whatsapp'])],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'string', 'max:190'],
            'frequency' => ['required', Rule::enum(ReportScheduleFrequency::class)],
            'timezone' => ['required', 'timezone'],
            'next_run_at' => ['required', 'date', 'after_or_equal:now'],
        ];
    }
}
