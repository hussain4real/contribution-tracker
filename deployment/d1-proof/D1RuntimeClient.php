<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Closure;
use GuzzleHttp\Utils;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class D1RuntimeClient
{
    private readonly Closure $transport;

    public function __construct(private readonly string $url, private readonly string $token, private readonly string $database, private readonly int $timeout = 30)
    {
        D1RuntimeProfile::assertTarget($database, $url, $token);
        if ($timeout < 1 || $timeout > 30) {
            throw new RuntimeException('D1 transport timeout must be between one and thirty seconds.');
        }
        $this->transport = Closure::fromCallable(Utils::chooseHandler());
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     */
    public function request(string $path, array $body): array
    {
        if (! in_array($path, ['/query', '/batch'], true)) {
            throw new RuntimeException('Unknown D1 transport operation.');
        }
        $started = microtime(true);
        $response = Http::withToken($this->token)->acceptJson()->connectTimeout(5)->timeout($this->timeout)
            ->setHandler($this->transport)->post($this->url.$path, $body);
        if (config(D1RuntimeProfile::key('startup_profile'))) {
            config([D1RuntimeProfile::key('profile_transport') => [
                'count' => Config::integer(D1RuntimeProfile::key('profile_transport.count'), 0) + 1,
                'milliseconds' => Config::float(D1RuntimeProfile::key('profile_transport.milliseconds'), 0.0) + (microtime(true) - $started) * 1000,
            ]]);
        }
        if (! $response->successful()) {
            $error = $response->json('error');
            $classification = is_string($error) && str_contains($error, 'd1_staging_fresh_snapshot') ? ' d1_staging_fresh_snapshot' : '';
            throw new RuntimeException('D1 operation failed'.$classification.'; its commit outcome may be unknown.');
        }
        $result = $response->json('result');
        if ($response->json('database') !== $this->database || ! is_array($result)) {
            throw new RuntimeException('D1 returned an unexpected database or result.');
        }

        return $result;
    }

    /**
     * @param  list<scalar|null>  $params
     * @return list<array<string, int|float|string|null>>
     */
    public function rows(string $sql, array $params = []): array
    {
        $result = $this->request('/query', ['sql' => $sql, 'params' => $params]);
        $rows = $result['results'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('Invalid D1 result rows.');
        }
        $validated = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid D1 result row.');
            }
            $values = [];
            foreach ($row as $column => $value) {
                if (! is_string($column) || (! is_null($value) && ! is_int($value) && ! is_float($value) && ! is_string($value))) {
                    throw new RuntimeException('Invalid D1 result value.');
                }
                $values[$column] = $value;
            }
            $validated[] = $values;
        }

        return $validated;
    }
}
