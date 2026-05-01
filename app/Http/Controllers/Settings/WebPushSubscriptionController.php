<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyWebPushSubscriptionRequest;
use App\Http\Requests\StoreWebPushSubscriptionRequest;
use Illuminate\Http\RedirectResponse;

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

        $request->user()->forgetWebPushSubscriptionCache();

        return back()->with('success', 'Browser notifications enabled.');
    }

    public function destroy(DestroyWebPushSubscriptionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->deletePushSubscription($validated['endpoint']);
        $request->user()->forgetWebPushSubscriptionCache();

        return back()->with('success', 'Browser notifications disabled.');
    }
}
