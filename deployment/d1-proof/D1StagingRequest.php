<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use App\Features\AiAssistant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class D1StagingRequest
{
    public function __construct(private readonly D1StagingRuntime $runtime) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $phase = 'plan'): Response
    {
        if (D1RuntimeProfile::production() && config(D1RuntimeProfile::key('planning'))) {
            return $next($request);
        }
        $entered = microtime(true);
        $relayToken = config('d1-staging.webhooks.relay_token');
        $providerSecret = $request->is('webhooks/paystack') ? config('d1-staging.webhooks.paystack_test_key') : config('d1-staging.webhooks.meta_app_secret');
        $providerEnabled = is_string($providerSecret) && ($request->is('webhooks/paystack')
            ? str_starts_with($providerSecret, 'sk_test_')
            : preg_match('/^[a-f0-9]{32}$/iD', $providerSecret) === 1);
        $webhookRelay = $request->isMethod('POST') && $request->is('webhooks/paystack', 'webhooks/whatsapp')
            && config('d1-staging.webhooks.enabled') && config('d1-staging.database') === 'familyfunds-d1-web-staging'
            && $providerEnabled && is_string($relayToken) && strlen($relayToken) >= 32
            && hash_equals($relayToken, (string) $request->header('X-D1-Staging-Relay'));
        if (! D1RuntimeProfile::production() && $request->is('webhooks/*', 'mcp/*', 'mcp', 'oauth/*', 'platform/*') && ! $webhookRelay) {
            abort(403, 'External integrations and platform administration are disabled in isolated staging.');
        }
        if ($phase === 'gate') {
            if (config(D1RuntimeProfile::key('startup_profile'))) {
                config([D1RuntimeProfile::key('profile_transport') => ['count' => 0, 'milliseconds' => 0.0]]);
            }
            $response = $next($request);
            if (config(D1RuntimeProfile::key('startup_profile'))) {
                $transportMs = Config::float(D1RuntimeProfile::key('profile_transport.milliseconds'), 0.0);
                $start = (float) $request->server('REQUEST_TIME_FLOAT', $entered);
                $response->headers->set('Server-Timing', sprintf(
                    'php_boot;dur=%.1f, d1_transport;dur=%.1f, d1_calls;desc="%d", php_other;dur=%.1f',
                    ($entered - $start) * 1000,
                    $transportMs,
                    Config::integer(D1RuntimeProfile::key('profile_transport.count'), 0),
                    max(0, (microtime(true) - $start) * 1000 - $transportMs),
                ));
            }

            return $response;
        }
        $actor = $request->user();
        $actorId = $actor instanceof User ? $actor->id : null;
        $segment = $request->segment(1);
        $dashboardCandidate = config(D1RuntimeProfile::key('read_only_dashboard'))
            && $request->isMethod('GET') && $request->routeIs('dashboard') && $actor instanceof User;
        $familyQuery = DB::connection(D1RuntimeProfile::connection())->table('families')->select('id')->where('slug', $segment);
        if ($dashboardCandidate) {
            $familyQuery->selectRaw('exists(select 1 from features where name=? and scope=?) as snapshot_feature', [AiAssistant::class, Feature::serializeScope($actor)]);
        }
        $family = is_string($segment) ? $familyQuery->first() : null;
        $familyId = $family?->id;
        if ($familyId === null && $request->is('login') && is_string($request->input('email'))) {
            $familyId = DB::connection(D1RuntimeProfile::connection())->table('users')->where('email', $request->input('email'))->value('family_id');
        }
        $readOnly = $dashboardCandidate
            && is_int($familyId)
            && $actor->current_family_id === $familyId && $actor->family_id === $familyId
            && (bool) ($family->snapshot_feature ?? false);
        try {
            $callback = function () use ($next, $request, $actorId): Response {
                if ($actorId !== null) {
                    Auth::guard('web')->setUser(User::findOrFail($actorId));
                }

                return $next($request);
            };
            if ($readOnly) {
                $response = $this->runtime->runReadOnly($callback, $familyId, $actor->id);
            } else {
                $response = $this->runtime->run($callback, is_int($familyId) ? $familyId : null, $actorId);
            }
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'write lease is busy')) {
                return response()->json(['message' => 'The family ledger is busy. Retry this request shortly.'], 503, ['Retry-After' => '1']);
            }
            if (! str_contains($exception->getMessage(), 'd1_staging_fresh_snapshot')) {
                throw $exception;
            }

            return response()->json(['message' => 'The financial data changed during this request. Refresh and retry.'], 409, ['Retry-After' => '1']);
        }
        if (D1RuntimeProfile::production()) {
            return $response;
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-D1-Staging', 'isolated');
        $response->headers->set('X-D1-Staging-Mode', $readOnly ? 'read-only' : 'leased');

        return $response;
    }
}
