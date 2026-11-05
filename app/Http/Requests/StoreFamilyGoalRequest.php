<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GoalMeasure;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFamilyGoalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'measure' => ['required', Rule::enum(GoalMeasure::class)],
            'target_amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'deadline' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array{name: string, measure: string, target_amount: int, starts_on: string, deadline: string} */
    public function attributesForGoal(): array
    {
        return [
            'name' => $this->string('name')->toString(), 'measure' => $this->string('measure')->toString(),
            'target_amount' => $this->integer('target_amount'), 'starts_on' => $this->string('starts_on')->toString(),
            'deadline' => $this->string('deadline')->toString(),
        ];
    }
}
