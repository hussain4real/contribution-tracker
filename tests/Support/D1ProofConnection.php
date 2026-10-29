<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use stdClass;

/**
 * @phpstan-type D1Row array<string, int|float|string|null>
 * @phpstan-type D1Result array{results: list<D1Row>, meta: array{changes: int}}
 */
class D1ProofConnection extends SQLiteConnection
{
    public function __construct(private readonly string $url, private readonly string $token)
    {
        if (! app()->environment('testing') || $url !== 'https://familyfunds-d1-proof.familyfunds-lab.workers.dev' || $token === '') {
            throw new RuntimeException('D1 proof requires the isolated synthetic testing endpoint.');
        }

        parent::__construct(new PDO('sqlite::memory:'), 'familyfunds-d1-staging-proof', '', ['driver' => 'sqlite']);
        $this->setPostProcessor(new D1ProofProcessor);
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  list<int>  $fetchUsing
     * @return list<stdClass|D1Row>
     */
    public function select(mixed $query, mixed $bindings = [], mixed $useReadPdo = true, array $fetchUsing = []): array
    {
        /** @var list<stdClass|D1Row> $result */
        $result = $this->run($query, $bindings, function (string $query, array $bindings) use ($fetchUsing): array {
            if ($this->pretending()) {
                return [];
            }

            $rows = $this->queryD1($query, $bindings)['results'];

            return $fetchUsing === [PDO::FETCH_ASSOC] ? $rows : array_map(fn (array $row): object => (object) $row, $rows);
        });

        return $result;
    }

    /** @param array<array-key, mixed> $bindings */
    public function statement(mixed $query, mixed $bindings = []): bool
    {
        return (bool) $this->run($query, $bindings, function (string $query, array $bindings): bool {
            if (! $this->pretending()) {
                $this->queryD1($query, $bindings);
                $this->recordsHaveBeenModified();
            }

            return true;
        });
    }

    /** @param array<array-key, mixed> $bindings */
    public function affectingStatement(mixed $query, mixed $bindings = []): int
    {
        /** @var int $changes */
        $changes = $this->run($query, $bindings, function (string $query, array $bindings): int {
            if ($this->pretending()) {
                return 0;
            }

            $changes = $this->queryD1($query, $bindings)['meta']['changes'];
            $this->recordsHaveBeenModified($changes > 0);

            return $changes;
        });

        return $changes;
    }

    public function unprepared(mixed $query): bool
    {
        return $this->statement($query);
    }

    protected function executeBeginTransactionStatement(): void
    {
        $this->statement('BEGIN DEFERRED TRANSACTION');
    }

    /**
     * @param  list<array{sql: string, params: list<scalar|null>}>  $queries
     * @return list<D1Result>
     */
    public function batch(array $queries): array
    {
        $result = $this->request('/batch', ['batch' => $queries]);
        if (! array_is_list($result) || count($result) !== count($queries)) {
            throw new RuntimeException('Invalid D1 batch response.');
        }

        return array_map($this->validateResult(...), $result);
    }

    /**
     * @param  list<scalar|null>  $bindings
     */
    public function integerValue(string $sql, array $bindings = []): int
    {
        $rows = $this->queryD1($sql, $bindings)['results'];
        $value = isset($rows[0]) ? reset($rows[0]) : null;
        if (! is_int($value)) {
            throw new RuntimeException('D1 did not return an integer.');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @return D1Result
     */
    private function queryD1(string $sql, array $bindings): array
    {
        return $this->validateResult($this->request('/query', ['sql' => $sql, 'params' => $this->prepareBindings($bindings)]));
    }

    /** @return D1Result */
    private function validateResult(mixed $result): array
    {
        if (! is_array($result) || ! isset($result['results'], $result['meta']) || ! is_array($result['results']) || ! array_is_list($result['results']) || ! is_array($result['meta']) || ! isset($result['meta']['changes']) || ! is_int($result['meta']['changes'])) {
            throw new RuntimeException('Invalid D1 result shape.');
        }
        $rows = [];
        foreach ($result['results'] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid D1 row.');
            }
            $validated = [];
            foreach ($row as $key => $value) {
                if (! is_string($key) || (! is_null($value) && ! is_int($value) && ! is_float($value) && ! is_string($value))) {
                    throw new RuntimeException('Invalid D1 column.');
                }
                $validated[$key] = $value;
            }
            $rows[] = $validated;
        }

        return ['results' => $rows, 'meta' => ['changes' => $result['meta']['changes']]];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     */
    private function request(string $path, array $body): array
    {
        $response = Http::withToken($this->token)->acceptJson()->timeout(30)->post($this->url.$path, $body);
        if (! $response->successful()) {
            $error = $response->json('error');
            throw new RuntimeException('D1 proof: '.(is_string($error) ? $error : 'Request failed'));
        }
        $result = $response->json('result');
        if ($response->json('database') !== 'familyfunds-d1-staging-proof' || ! is_array($result)) {
            throw new RuntimeException('D1 proof returned an unexpected database or result.');
        }

        return $result;
    }
}
