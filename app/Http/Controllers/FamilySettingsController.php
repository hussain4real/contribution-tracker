<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncPaystackSubaccount;
use App\Models\FamilyCategory;
use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        /** @var User $user */
        $user = Auth::user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $family = $user->family;

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
        /** @var User $user */
        $user = Auth::user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:10'],
            'due_day' => ['required', 'integer', 'min:1', 'max:28'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'bank_code' => ['nullable', 'string', 'max:10'],
        ]);

        $family = $user->family;
        $bankDetailsChanged = $family->bank_code !== ($validated['bank_code'] ?? null)
            || $family->account_number !== ($validated['account_number'] ?? null);

        $family->update($validated);

        // Queue Paystack subaccount sync when bank details change
        if ($bankDetailsChanged && $family->hasBankDetails()) {
            SyncPaystackSubaccount::dispatch($family);
        }

        return redirect()->back()->with('success', 'Family settings updated.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'monthly_amount' => ['required', 'integer', 'min:0'],
        ]);

        $family = $user->family;
        $maxSortOrder = $family->categories()->max('sort_order') ?? -1;

        FamilyCategory::create([
            'family_id' => $family->id,
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'monthly_amount' => $validated['monthly_amount'],
            'sort_order' => $maxSortOrder + 1,
        ]);

        return redirect()->back()->with('success', 'Category added.');
    }

    public function updateCategory(Request $request, FamilyCategory $category): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->isAdmin() || $category->family_id !== $user->family_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'monthly_amount' => ['required', 'integer', 'min:0'],
        ]);

        $category->update([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'monthly_amount' => $validated['monthly_amount'],
        ]);

        return redirect()->back()->with('success', 'Category updated.');
    }

    public function destroyCategory(FamilyCategory $category): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

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
        /** @var User $user */
        $user = Auth::user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $banks = Cache::remember('paystack_banks', 86400, function () {
            $response = $this->paystack->listBanks();

            if (! ($response['status'] ?? false)) {
                return [];
            }

            return collect($response['data'])
                ->filter(fn (array $bank) => $bank['active'] ?? false)
                ->map(fn (array $bank) => [
                    'name' => $bank['name'],
                    'code' => $bank['code'],
                ])
                ->sortBy('name')
                ->values()
                ->all();
        });

        return response()->json($banks);
    }
}
