<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AiActionKind;
use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\CurrencyFormatter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;

class AiActionDrafts
{
    public function allowed(User $user, Family $family, AiActionKind $kind): bool
    {
        $membership = FamilyMembership::query()->active()->where('family_id', $family->id)->where('user_id', $user->id)->first();

        return Family::query()->whereKey($family->id)->whereNull('archived_at')->exists()
            && $membership instanceof FamilyMembership
            && ($kind === AiActionKind::Contributions ? $membership->role === Role::Admin : $membership->role->canRecordPayments());
    }

    private function authorize(User $user, Family $family, AiActionKind $kind): Family
    {
        $fresh = Family::query()->find($family->id);
        $membership = FamilyMembership::query()->active()->where('family_id', $family->id)->where('user_id', $user->id)->first();
        abort_unless($fresh instanceof Family && ! $fresh->isArchived() && $membership instanceof FamilyMembership
            && ($kind === AiActionKind::Contributions ? $membership->role === Role::Admin : $membership->role->canRecordPayments()), 403);

        return $fresh;
    }

    /** @return array<string, list<string>> */
    private function rules(AiActionKind $kind): array
    {
        return match ($kind) {
            AiActionKind::Payment => ['member_id' => ['required', 'integer', 'min:1'], 'member' => ['required', 'string', 'max:255'], 'amount' => ['required', 'integer', 'min:1', 'max:999999999'], 'paid_at' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:1000'], 'target_year' => ['nullable', 'required_with:target_month', 'integer', 'between:2020,2030'], 'target_month' => ['nullable', 'required_with:target_year', 'integer', 'between:1,12']],
            AiActionKind::Expense => ['amount' => ['required', 'integer', 'min:1', 'max:999999999'], 'description' => ['required', 'string', 'max:1000'], 'spent_at' => ['required', 'date_format:Y-m-d']],
            AiActionKind::Adjustment => ['amount' => ['required', 'integer', 'not_in:0', 'between:-999999999,999999999'], 'description' => ['required', 'string', 'max:1000'], 'recorded_at' => ['required', 'date_format:Y-m-d']],
            AiActionKind::Contributions => ['year' => ['required', 'integer', 'between:2020,2030'], 'month' => ['required', 'integer', 'between:1,12']],
        };
    }

    /** @param array<string, mixed> $values
     * @return array<string, int|string|null>
     */
    private function normalize(AiActionKind $kind, array $values): array
    {
        $input = new Request(Validator::make($values, $this->rules($kind))->validate());

        return match ($kind) {
            AiActionKind::Payment => ['member_id' => $input->integer('member_id'), 'member' => $input->string('member')->toString(), 'amount' => $input->integer('amount'), 'paid_at' => $input->string('paid_at')->toString(), 'notes' => $input->filled('notes') ? $input->string('notes')->toString() : null, 'target_year' => $input->filled('target_year') ? $input->integer('target_year') : null, 'target_month' => $input->filled('target_month') ? $input->integer('target_month') : null],
            AiActionKind::Expense => ['amount' => $input->integer('amount'), 'description' => $input->string('description')->toString(), 'spent_at' => $input->string('spent_at')->toString()],
            AiActionKind::Adjustment => ['amount' => $input->integer('amount'), 'description' => $input->string('description')->toString(), 'recorded_at' => $input->string('recorded_at')->toString()],
            AiActionKind::Contributions => ['year' => $input->integer('year'), 'month' => $input->integer('month')],
        };
    }

    /** @param array<string, mixed> $values
     * @return array{status: string, message: string, details: array<string, int|string|null>, review_url: string, expires_at: string}
     */
    public function create(User $user, Family $family, AiActionKind $kind, array $values): array
    {
        $family = $this->authorize($user, $family, $kind);
        $values = $this->normalize($kind, $values);
        $key = (string) Str::uuid();
        $expiry = now()->addMinutes(10);
        $payload = ['user_id' => $user->id, 'family_id' => $family->id, 'action' => $kind->value, 'values' => $values, 'expires_at' => $expiry->getTimestamp()];
        Cache::put('ai-action-draft:'.$key, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), $expiry);
        $parameters = ['current_family' => $family->slug, 'ai_draft' => $key];
        if ($kind === AiActionKind::Payment) {
            $parameters['member'] = $values['member_id'];
        }

        $summary = $kind === AiActionKind::Contributions
            ? now()->startOfMonth()->setYear((int) $values['year'])->setMonth((int) $values['month'])->format('F Y').' contributions'
            : CurrencyFormatter::format((int) $values['amount'], $family->currency).' — '.($values['member'] ?? $values['description'] ?? '');
        if ($kind === AiActionKind::Payment && $values['target_year'] !== null && $values['target_month'] !== null) {
            $summary .= ' for '.now()->startOfMonth()->setYear((int) $values['target_year'])->setMonth((int) $values['target_month'])->format('F Y');
        }

        return ['status' => 'confirmation_required', 'message' => 'Draft: '.$summary.'. Review and submit the form in FamilyFund to confirm. Nothing has been recorded.', 'details' => $values, 'review_url' => route($kind->destination(), $parameters), 'expires_at' => $expiry->toIso8601String()];
    }

    /** @return array{key: string, values: array<string, int|string|null>}|null */
    public function read(Request $request, User $user, Family $family, AiActionKind $kind): ?array
    {
        if (! $request->has('ai_draft')) {
            return null;
        }
        $this->authorize($user, $family, $kind);
        $key = $request->query('ai_draft');
        abort_unless(is_string($key) && Str::isUuid($key), 404);
        $sealed = Cache::get('ai-action-draft:'.$key);
        abort_unless(is_string($sealed), 410, 'This AI draft expired. Return to the assistant to prepare a new draft.');
        try {
            $payload = json_decode(Crypt::decryptString($sealed), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(404);
        }
        abort_unless(is_array($payload) && is_array($payload['values'] ?? null) && is_int($payload['expires_at'] ?? null), 404);
        abort_unless(($payload['user_id'] ?? null) === $user->id && ($payload['family_id'] ?? null) === $family->id && ($payload['action'] ?? null) === $kind->value, 403);
        abort_if($payload['expires_at'] <= now()->getTimestamp(), 410, 'This AI draft expired. Return to the assistant to prepare a new draft.');

        $values = [];
        foreach ($payload['values'] as $name => $value) {
            abort_unless(is_string($name), 404);
            $values[$name] = $value;
        }

        return ['key' => $key, 'values' => $this->normalize($kind, $values)];
    }
}
