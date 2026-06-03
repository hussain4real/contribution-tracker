<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncPaystackSubaccount;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FamilySettingsController extends Controller
{
    public function __construct(
        private PaystackService $paystack
    ) {}

    public function edit(): Response
    {
        $family = $this->adminFamily();

        $categories = $family->categories()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (FamilyCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'monthly_amount' => $category->monthly_amount,
                'sort_order' => $category->sort_order,
                'members_count' => $category->users()->count(),
            ]);

        return Inertia::render('Family/Settings', [
            'family' => [
                'id' => $family->id,
                'name' => $family->name,
                'currency' => $family->currency,
                'due_day' => $family->due_day,
                'bank_name' => $family->bank_name,
                'account_name' => $family->account_name,
                'account_number' => $family->account_number,
                'bank_code' => $family->bank_code,
                'has_paystack_subaccount' => $family->hasPaystackSubaccount(),
            ],
            'categories' => $categories,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $family = $this->adminFamily();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:10'],
            'due_day' => ['required', 'integer', 'min:1', 'max:28'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'bank_code' => ['nullable', 'string', 'max:10'],
        ]);

        $attributes = $this->familyAttributes($validated);
        $bankDetailsChanged = $family->bank_code !== $attributes['bank_code']
            || $family->account_number !== $attributes['account_number'];

        $family->update($attributes);

        // Queue Paystack subaccount sync when bank details change
        if ($bankDetailsChanged && $family->hasBankDetails()) {
            SyncPaystackSubaccount::dispatch($family);
        }

        return redirect()->back()->with('success', 'Family settings updated.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $family = $this->adminFamily();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'monthly_amount' => ['required', 'integer', 'min:0'],
        ]);
        $attributes = $this->categoryAttributes($validated);

        $maxSortOrder = $family->categories()->max('sort_order') ?? -1;
        $maxSortOrder = is_numeric($maxSortOrder) ? (int) $maxSortOrder : -1;

        FamilyCategory::create([
            'family_id' => $family->id,
            'name' => $attributes['name'],
            'slug' => Str::slug($attributes['name']),
            'monthly_amount' => $attributes['monthly_amount'],
            'sort_order' => $maxSortOrder + 1,
        ]);

        return redirect()->back()->with('success', 'Category added.');
    }

    public function updateCategory(Request $request, FamilyCategory $category): RedirectResponse
    {
        $user = $this->authUser();

        if (! $user->isAdmin() || $category->family_id !== $user->family_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'monthly_amount' => ['required', 'integer', 'min:0'],
        ]);
        $attributes = $this->categoryAttributes($validated);

        $category->update([
            'name' => $attributes['name'],
            'slug' => Str::slug($attributes['name']),
            'monthly_amount' => $attributes['monthly_amount'],
        ]);

        return redirect()->back()->with('success', 'Category updated.');
    }

    public function destroyCategory(FamilyCategory $category): RedirectResponse
    {
        $user = $this->authUser();

        if (! $user->isAdmin() || $category->family_id !== $user->family_id) {
            abort(403);
        }

        if ($category->users()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a category with active members.');
        }

        $category->delete();

        return redirect()->back()->with('success', 'Category deleted.');
    }

    /**
     * List available banks from Paystack (cached for 24 hours).
     */
    public function banks(): JsonResponse
    {
        $user = $this->authUser();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $banks = Cache::remember('paystack_banks', 86400, function () {
            $response = $this->paystack->listBanks();

            if (! ($response['status'] ?? false)) {
                return [];
            }

            return $this->bankOptions($response['data'] ?? []);
        });

        return response()->json($banks);
    }

    private function adminFamily(): Family
    {
        $user = $this->authUser();

        if (! $user->isAdmin() || ! $user->family instanceof Family) {
            abort(403);
        }

        return $user->family;
    }

    /**
     * @return array{name: string, currency: string, due_day: int, bank_name: string|null, account_name: string|null, account_number: string|null, bank_code: string|null}
     */
    private function familyAttributes(mixed $validated): array
    {
        $validated = is_array($validated) ? $validated : [];

        return [
            'name' => is_string($validated['name'] ?? null) ? $validated['name'] : '',
            'currency' => is_string($validated['currency'] ?? null) ? $validated['currency'] : '₦',
            'due_day' => is_numeric($validated['due_day'] ?? null) ? (int) $validated['due_day'] : 28,
            'bank_name' => is_string($validated['bank_name'] ?? null) ? $validated['bank_name'] : null,
            'account_name' => is_string($validated['account_name'] ?? null) ? $validated['account_name'] : null,
            'account_number' => is_string($validated['account_number'] ?? null) ? $validated['account_number'] : null,
            'bank_code' => is_string($validated['bank_code'] ?? null) ? $validated['bank_code'] : null,
        ];
    }

    /**
     * @return array{name: string, monthly_amount: int}
     */
    private function categoryAttributes(mixed $validated): array
    {
        $validated = is_array($validated) ? $validated : [];

        return [
            'name' => is_string($validated['name'] ?? null) ? $validated['name'] : '',
            'monthly_amount' => is_numeric($validated['monthly_amount'] ?? null) ? (int) $validated['monthly_amount'] : 0,
        ];
    }

    /**
     * @return list<array{name: string, code: string}>
     */
    private function bankOptions(mixed $banks): array
    {
        $options = [];

        foreach (is_array($banks) ? $banks : [] as $bank) {
            if (! is_array($bank) || ($bank['active'] ?? false) !== true) {
                continue;
            }

            $name = $bank['name'] ?? null;
            $code = $bank['code'] ?? null;

            if (is_string($name) && is_string($code)) {
                $options[] = ['name' => $name, 'code' => $code];
            }
        }

        usort($options, fn (array $first, array $second): int => $first['name'] <=> $second['name']);

        return $options;
    }
}
