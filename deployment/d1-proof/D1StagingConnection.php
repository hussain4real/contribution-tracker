<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Database\SQLiteConnection;
use PDO;
use RuntimeException;
use stdClass;

/**
 * @phpstan-type D1Row array<string, int|float|string|null>
 * @phpstan-type D1Result array{results: list<D1Row>, meta: array{changes: int}}
 */
class D1StagingConnection extends SQLiteConnection
{
    private readonly D1RuntimeClient $client;

    public const TARGETS = [
        'familyfunds-d1-web-staging' => 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev',
        'familyfunds-d1-scale-lab' => 'https://familyfunds-d1-scale-sql.familyfunds-lab.workers.dev',
    ];

    public function __construct(private readonly string $url, private readonly string $token, private readonly string $databaseName)
    {
        D1RuntimeProfile::assertTarget($databaseName, $url, $token);
        parent::__construct(new PDO('sqlite::memory:'), $databaseName, '', ['driver' => 'sqlite']);
        $this->setPostProcessor(new D1StagingProcessor);
        $this->client = new D1RuntimeClient($url, $token, $databaseName);
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
        $this->assertOutsidePlanning();
        $result = $this->request('/batch', ['batch' => $queries]);
        if (! array_is_list($result) || count($result) !== count($queries)) {
            throw new RuntimeException('Invalid D1 staging batch response.');
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
        $this->assertOutsidePlanning();

        return $this->validateResult($this->request('/query', ['sql' => $sql, 'params' => $this->prepareBindings($bindings)]));
    }

    private function assertOutsidePlanning(): void
    {
        if (config(D1RuntimeProfile::key('planning'))) {
            throw new RuntimeException('Explicit remote database access is forbidden while planning a staging request.');
        }
    }

    /** @return D1Result */
    private function validateResult(mixed $result): array
    {
        if (! is_array($result) || ! isset($result['results'], $result['meta']) || ! is_array($result['results']) || ! array_is_list($result['results']) || ! is_array($result['meta']) || ! isset($result['meta']['changes']) || ! is_int($result['meta']['changes'])) {
            throw new RuntimeException('Invalid D1 staging result shape.');
        }
        $rows = [];
        foreach ($result['results'] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid D1 staging row.');
            }
            $validated = [];
            foreach ($row as $key => $value) {
                if (! is_string($key) || (! is_null($value) && ! is_int($value) && ! is_float($value) && ! is_string($value))) {
                    throw new RuntimeException('Invalid D1 staging column.');
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
        return $this->client->request($path, $body);
    }
}
