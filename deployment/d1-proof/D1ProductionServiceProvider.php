<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\OutgoingRequest;
use LogicException;
use Psr\Http\Message\RequestInterface;

final class D1ProductionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        D1RuntimeProfile::assertEnabled();
        if (config('d1-production.migration_checks')
            && (config('d1-production.delivery_enabled') || config('d1-production.webhooks_enabled')
                || config('d1-production.queue_worker_enabled') || config('nightwatch.enabled'))) {
            throw new LogicException('Production migration checks require delivery, webhooks, background workers and monitoring to be disabled.');
        }
        if (config('d1-production.provider_read_only_checks') && ! config('d1-production.migration_checks')) {
            throw new LogicException('Read-only provider checks require the isolated migration mirror.');
        }
        $url = Config::string('d1-production.url');
        $token = Config::string('d1-production.token');
        $database = Config::string('d1-production.database');
        D1RuntimeProfile::assertTarget($database, $url, $token);
        if (config('d1-production.webhooks_enabled')
            && (! str_starts_with(Config::string('services.paystack.webhook_secret'), 'sk_live_')
                || preg_match('/^[a-f0-9]{32}$/iD', Config::string('services.whatsapp.app_secret')) !== 1)) {
            throw new LogicException('Production webhooks require both production signing credentials.');
        }
        $this->app->singleton(D1RuntimeClient::class, fn (): D1RuntimeClient => new D1RuntimeClient($url, $token, $database));
        DB::extend('d1', fn (): D1ProductionConnection => new D1ProductionConnection($url, $token, $database));
        $this->callAfterResolving('cache', function (CacheManager $manager): void {
            $manager->extend('d1', fn (): Repository => $manager->repository(new D1RuntimeCacheStore($this->app->make(D1RuntimeClient::class))));
        });
        config([
            'database.connections.d1' => ['driver' => 'd1'],
            'database.default' => 'd1',
            'session.driver' => 'd1', 'session.encrypt' => true, 'session.secure' => true,
            'session.cookie' => 'familyfunds_production_session',
            'session.lifetime' => 10080, 'session.expire_on_close' => false,
            'queue.default' => 'd1',
            'queue.connections.d1' => ['driver' => 'd1', 'queue' => 'default', 'after_commit' => false],
            'cache.default' => 'd1', 'cache.stores.d1' => ['driver' => 'd1'],
            'inertia.ssr.enabled' => false,
            'webpush.database_connection' => null,
            'nightwatch.redact_headers' => [...Config::array('nightwatch.redact_headers'), 'X-FamilyFunds-Preview', 'X-FamilyFunds-Relay'],
        ]);
        $this->app->extend(RateLimiter::class, fn (RateLimiter $limiter): D1RuntimeRateLimiter => new D1RuntimeRateLimiter($this->app->make('cache')->store('d1'), $limiter));
        if (! config('d1-production.delivery_enabled')) {
            config(['mail.default' => 'array']);
        } elseif (config('mail.default') !== 'cloudflare'
            || strlen(Config::string('services.cloudflare.token')) < 32
            || ! str_starts_with(Config::string('services.paystack.secret_key'), 'sk_live_')) {
            throw new LogicException('Production delivery requires the reviewed mail transport and live payment credentials.');
        }
    }

    public function boot(): void
    {
        $url = Config::string('d1-production.url');
        Nightwatch::rejectOutgoingRequests(fn (OutgoingRequest $request): bool => str_starts_with($request->url, $url.'/'));
        Session::extend('d1', fn (): D1StagingSessionHandler => new D1StagingSessionHandler($url, Config::string('d1-production.token'), Config::string('d1-production.database'), Config::integer('session.lifetime')));
        Queue::extend('d1', fn (): D1StagingConnector => new D1StagingConnector);
        config([
            'filesystems.disks.local' => D1StagingStorage::configuration(Config::string('d1-production.r2.bucket'), Config::string('d1-production.r2.key'), Config::string('d1-production.r2.secret')),
            'filesystems.disks.public' => D1StagingStorage::configuration(Config::string('d1-production.r2.bucket'), Config::string('d1-production.r2.key'), Config::string('d1-production.r2.secret'), 'public'),
        ]);
        if (config('backup.google_drive.enabled')) {
            throw new \RuntimeException('Offsite backup credentials belong only in the separate offsite runner.');
        }
        TrustProxies::at('*');
        if (config('d1-production.delivery_enabled')) {
            if (Config::string('d1-production.bank_lookup_test_key') !== '') {
                throw new LogicException('Candidate bank credentials cannot enable production delivery.');
            }
            Http::preventStrayRequests(false);
        } else {
            Http::preventStrayRequests();
            $allowed = [$url.'/*'];
            $bankKey = Config::string('d1-production.bank_lookup_test_key');
            $readOnlyProviders = config('d1-production.provider_read_only_checks') === true;
            if ($bankKey !== '') {
                if (! str_starts_with($bankKey, 'sk_test_')) {
                    throw new LogicException('Candidate bank lookup requires a Paystack test credential.');
                }
                config(['services.paystack.secret_key' => $bankKey, 'services.paystack.base_url' => 'https://api.paystack.co']);
            }
            if ($readOnlyProviders) {
                if (! str_starts_with(Config::string('services.paystack.secret_key'), 'sk_live_')) {
                    throw new LogicException('Production provider checks require the approved live credential.');
                }
                config(['services.paystack.base_url' => 'https://api.paystack.co']);
            }
            if ($bankKey !== '' || $readOnlyProviders) {
                $allowed[] = 'https://api.paystack.co/bank?*';
                Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
                    if ($request->getUri()->getHost() === 'api.paystack.co'
                        && ($request->getMethod() !== 'GET' || $request->getUri()->getPath() !== '/bank')) {
                        throw new LogicException('Only read-only bank listing is available in the candidate.');
                    }

                    return $request;
                });
            }
            if ($readOnlyProviders) {
                $phone = Config::string('services.whatsapp.phone_number_id');
                $version = Config::string('services.whatsapp.api_version');
                if (preg_match('/^[0-9]{5,}$/D', $phone) !== 1 || preg_match('/^v[0-9]+\.[0-9]+$/D', $version) !== 1) {
                    throw new LogicException('Invalid production Meta read-only scope.');
                }
                $path = '/'.$version.'/'.$phone;
                $allowed[] = 'https://graph.facebook.com'.$path.'?fields=id';
                Http::globalRequestMiddleware(function (RequestInterface $request) use ($path): RequestInterface {
                    if ($request->getUri()->getHost() === 'graph.facebook.com'
                        && ($request->getMethod() !== 'GET' || $request->getUri()->getPath() !== $path || $request->getUri()->getQuery() !== 'fields=id')) {
                        throw new LogicException('Only read-only Meta sender inspection is available in the migration mirror.');
                    }

                    return $request;
                });
            }
            Http::allowStrayRequests($allowed);
        }
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->prependMiddleware(D1ProductionRequest::class);
        if (config('d1-production.startup_profile')) {
            $kernel->prependMiddleware(D1StagingRequest::class.':gate');
        }
        $kernel->appendMiddlewareToGroup('web', D1StagingRequest::class);
        $kernel->addToMiddlewarePriorityAfter(StartSession::class, D1StagingRequest::class);
    }
}
