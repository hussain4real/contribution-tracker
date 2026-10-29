<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class D1StagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! config('d1-staging.enabled')) {
            return;
        }
        if (! $this->app->environment(['testing', 'd1-staging'])) {
            throw new LogicException('The D1 staging runtime cannot run in this application environment.');
        }
        foreach (['database', 'url', 'token'] as $key) {
            if (! is_string(config('d1-staging.'.$key)) || config('d1-staging.'.$key) === '') {
                throw new LogicException('Missing isolated D1 staging configuration.');
            }
        }
        DB::extend('d1-staging', function (): D1StagingConnection {
            $url = config('d1-staging.url');
            $token = config('d1-staging.token');
            $database = config('d1-staging.database');
            if (! is_string($url) || ! is_string($token) || ! is_string($database)) {
                throw new LogicException('Invalid isolated D1 staging configuration.');
            }

            return new D1StagingConnection($url, $token, $database);
        });
        config([
            'database.connections.d1-staging' => ['driver' => 'd1-staging'],
            'database.default' => 'd1-staging',
            'session.driver' => 'd1-staging', 'session.encrypt' => true, 'cache.default' => 'file', 'mail.default' => 'array',
            'queue.default' => 'd1-staging',
            'queue.connections.d1-staging' => ['driver' => 'd1-staging', 'queue' => 'default', 'after_commit' => false],
            'inertia.ssr.enabled' => false, 'nightwatch.enabled' => false,
            'webpush.database_connection' => null,
        ]);
    }

    public function boot(): void
    {
        if (! config('d1-staging.enabled')) {
            return;
        }
        $url = config('d1-staging.url');
        if (! is_string($url)) {
            throw new LogicException('Invalid isolated D1 staging endpoint.');
        }
        $bucket = config('d1-staging.r2.bucket');
        if (is_string($bucket) && $bucket !== '') {
            $key = config('d1-staging.r2.key');
            $secret = config('d1-staging.r2.secret');
            if (! is_string($key) || ! is_string($secret)) {
                throw new LogicException('Missing staging R2 credentials.');
            }
            config([
                'filesystems.disks.local' => D1StagingStorage::configuration($bucket, $key, $secret),
                'filesystems.disks.public' => D1StagingStorage::configuration($bucket, $key, $secret, 'public'),
            ]);
        }
        TrustProxies::at('*');
        Session::extend('d1-staging', function () use ($url): D1StagingSessionHandler {
            $token = config('d1-staging.token');
            $database = config('d1-staging.database');
            $lifetime = config('session.lifetime');
            if (! is_string($token) || ! is_string($database) || ! is_int($lifetime)) {
                throw new LogicException('Missing staging session configuration.');
            }

            return new D1StagingSessionHandler($url, $token, $database, $lifetime);
        });
        Queue::extend('d1-staging', fn (): D1StagingConnector => new D1StagingConnector);
        Http::preventStrayRequests();
        $allowedRequests = [$url.'/*'];
        if (config('d1-staging.webhooks.enabled')) {
            $paystack = config('d1-staging.webhooks.paystack_test_key');
            $meta = config('d1-staging.webhooks.meta_app_secret');
            if (config('d1-staging.database') !== 'familyfunds-d1-web-staging'
                || ! is_string($paystack) || ($paystack !== '' && ! str_starts_with($paystack, 'sk_test_'))
                || ! is_string($meta) || ($meta !== '' && preg_match('/^[a-f0-9]{32}$/iD', $meta) !== 1)) {
                throw new LogicException('Webhook checks require isolated staging provider credentials.');
            }
            config(['services.paystack.secret_key' => $paystack, 'services.paystack.webhook_secret' => $paystack, 'services.paystack.base_url' => 'https://api.paystack.co', 'services.whatsapp.app_secret' => $meta]);
            if ($paystack !== '') {
                $allowedRequests[] = 'https://api.paystack.co/transaction/verify/*';
            }
        }
        Http::allowStrayRequests($allowedRequests);
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->prependMiddleware(D1StagingRequest::class.':gate');
        $kernel->appendMiddlewareToGroup('web', D1StagingRequest::class);
        $kernel->addToMiddlewarePriorityAfter(StartSession::class, D1StagingRequest::class);
    }
}
