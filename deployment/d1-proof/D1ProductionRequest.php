<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class D1ProductionRequest
{
    public function __construct(private readonly D1StagingRuntime $runtime) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        D1RuntimeProfile::assertEnabled();
        if (config('d1-production.migration_checks') && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')
            && ! ($request->isMethod('POST') && $request->is('login', 'logout', 'two-factor-challenge', 'user/confirm-password', 'passkeys/login/options', 'passkeys/login'))) {
            abort(423, 'This migration mirror only permits inspection and authentication.');
        }
        if ($request->is('__staging_admin', '__staging_admin/*')) {
            abort(404);
        }
        if ($request->is('webhooks/*')) {
            $token = Config::string('d1-production.relay_token');
            abort_unless(config('d1-production.webhooks_enabled') && strlen($token) >= 32 && hash_equals($token, (string) $request->header('X-FamilyFunds-Relay')), 403);
        }
        if (($request->isMethod('GET') || $request->isMethod('HEAD')) && $request->getPathInfo() === EndpointResolver::scriptPath(minified: true)) {
            return $next($request);
        }
        if ($request->isMethod('GET') && preg_match('#^[^/]+/dashboard$#D', $request->path()) === 1) {
            return $next($request);
        }
        try {
            return $this->runtime->run(fn (): Response => $next($request));
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'write lease is busy')) {
                return response()->json(['message' => 'The ledger is busy. Retry shortly.'], 503, ['Retry-After' => '1']);
            }
            if (str_contains($exception->getMessage(), 'd1_staging_fresh_snapshot')) {
                return response()->json(['message' => 'The data changed. Refresh and retry.'], 409);
            }
            throw $exception;
        }
    }
}
