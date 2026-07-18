<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user?->can('generate-reports') === true) {
            return true;
        }

        return $user instanceof User
            && $this->input('type') === ReportType::MemberStatement->value
            && is_numeric($this->input('member_id'))
            && intval($this->input('member_id')) === $user->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $family = $user instanceof User ? ($user->currentFamily ?? $user->family) : null;

        return [
            'type' => ['required', Rule::enum(ReportType::class)],
            'format' => ['required', Rule::enum(ReportFormat::class)],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'member_id' => [
                'nullable',
                Rule::requiredIf($this->input('type') === ReportType::MemberStatement->value),
                'integer',
                Rule::exists('family_members', 'user_id')->where('family_id', $family instanceof Family ? $family->id : 0),
            ],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'min_outstanding' => ['nullable', 'integer', 'min:0'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filters = collect($this->safe()->except(['type', 'format']))
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();

        return array_combine(array_map('strval', array_keys($filters)), array_values($filters));
    }

    public function reportType(): ReportType
    {
        $value = $this->validated('type');

        return ReportType::from(is_string($value) ? $value : '');
    }

    public function reportFormat(): ReportFormat
    {
        $value = $this->validated('format');

        return ReportFormat::from(is_string($value) ? $value : '');
    }
}
