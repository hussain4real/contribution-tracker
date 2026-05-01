<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebPushSubscriptionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebPushSubscriptionController extends Controller
{
    public function store(StoreWebPushSubscriptionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? null,
        );

        return back()->with('success', 'Browser notifications enabled.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:500'],
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return back()->with('success', 'Browser notifications disabled.');
    }
}
