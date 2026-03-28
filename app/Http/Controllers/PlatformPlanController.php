<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformPlanController extends Controller
{
    public function index(): Response
    {
        $plans = PlatformPlan::query()
            ->withCount('families')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PlatformPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price' => $plan->price,
                'formatted_price' => $plan->formattedPrice(),
                'max_members' => $plan->max_members,
                'features' => $plan->features ?? [],
                'is_active' => $plan->is_active,
                'sort_order' => $plan->sort_order,
                'families_count' => $plan->families_count,
                'paystack_plan_code' => $plan->paystack_plan_code,
                'created_at' => $plan->created_at->toDateString(),
            ]);

        return Inertia::render('Platform/Plans', [
            'plans' => $plans,
            'available_features' => [
                'basic_contributions' => 'Monthly Contributions',
                'manual_payments' => 'Manual Payment Recording',
                'online_payments' => 'Online Payments (Paystack)',
                'reports' => 'Financial Reports',
                'exports' => 'CSV Exports',
                'priority_support' => 'Priority Support',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:platform_plans,slug'],
            'price' => ['required', 'integer', 'min:0'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        PlatformPlan::create($validated);

        return redirect()->back()->with('success', 'Plan created successfully.');
    }

    public function update(Request $request, PlatformPlan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:platform_plans,slug,'.$plan->id],
            'price' => ['required', 'integer', 'min:0'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $plan->update($validated);

        return redirect()->back()->with('success', 'Plan updated successfully.');
    }

    public function toggleActive(PlatformPlan $plan): RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        $status = $plan->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "Plan \"{$plan->name}\" has been {$status}.");
    }

    public function destroy(PlatformPlan $plan): RedirectResponse
    {
        if ($plan->families()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a plan that has families assigned to it.');
        }

        $plan->delete();

        return redirect()->back()->with('success', 'Plan deleted successfully.');
    }
}
