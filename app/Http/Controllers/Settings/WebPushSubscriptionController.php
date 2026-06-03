<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyWebPushSubscriptionRequest;
use App\Http\Requests\StoreWebPushSubscriptionRequest;
use Illuminate\Http\RedirectResponse;

class WebPushSubscriptionController extends Controller
{
    public function store(StoreWebPushSubscriptionRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $validated = $request->validated();
        $endpoint = $this->stringValue($validated['endpoint'] ?? null);
        $keys = is_array($validated['keys'] ?? null) ? $validated['keys'] : [];
        $p256dh = $this->stringValue($keys['p256dh'] ?? null);
        $auth = $this->stringValue($keys['auth'] ?? null);
        $contentEncoding = $this->nullableString($validated['contentEncoding'] ?? null);

        $user->updatePushSubscription(
            $endpoint,
            $p256dh,
            $auth,
            $contentEncoding,
        );

        $user->forgetWebPushSubscriptionCache();

        return back()->with('success', 'Browser notifications enabled.');
    }

    public function destroy(DestroyWebPushSubscriptionRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $validated = $request->validated();
        $endpoint = $this->stringValue($validated['endpoint'] ?? null);

        $user->deletePushSubscription($endpoint);
        $user->forgetWebPushSubscriptionCache();

        return back()->with('success', 'Browser notifications disabled.');
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
