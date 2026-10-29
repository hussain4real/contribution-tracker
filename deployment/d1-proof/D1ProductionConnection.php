<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

final class D1ProductionConnection extends D1StagingConnection
{
    public function transaction(Closure $callback, mixed $attempts = 1): mixed
    {
        if ($attempts !== 1) {
            throw new LogicException('D1 financial transactions cannot be replayed automatically.');
        }

        if (config('d1-production.planning')) {
            return DB::connection('d1-staging-snapshot')->transaction(fn (): mixed => $callback($this));
        }

        return app(D1StagingRuntime::class)->run(
            fn (): mixed => DB::connection('d1-staging-snapshot')->transaction(fn (): mixed => $callback($this)),
        );
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  list<int>  $fetchUsing
     * @return list<\stdClass|array<string, int|float|string|null>>
     */
    public function select(mixed $query, mixed $bindings = [], mixed $useReadPdo = true, array $fetchUsing = []): array
    {
        if (! config('d1-production.planning') && ! preg_match('/^\s*(select|pragma)\b/i', $query)) {
            return app(D1StagingRuntime::class)->run(fn (): array => $this->select($query, $bindings, $useReadPdo, $fetchUsing));
        }
        if (config('d1-production.planning')) {
            $result = [];
            foreach (DB::connection('d1-staging-snapshot')->select($query, $bindings, $useReadPdo, $fetchUsing) as $row) {
                if ($row instanceof \stdClass) {
                    $result[] = $row;
                } elseif (is_array($row)) {
                    $values = [];
                    foreach ($row as $column => $value) {
                        if (! is_string($column) || (! is_int($value) && ! is_float($value) && ! is_string($value) && $value !== null)) {
                            throw new LogicException('Invalid D1 planned result column.');
                        }
                        $values[$column] = $value;
                    }
                    $result[] = $values;
                } else {
                    throw new LogicException('Invalid D1 planned result row.');
                }
            }

            return $result;
        }

        return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
    }

    /** @param array<array-key, mixed> $bindings */
    public function statement(mixed $query, mixed $bindings = []): bool
    {
        if (preg_match('/^\s*(create|alter|drop|attach|detach|vacuum)\b/i', $query)) {
            throw new LogicException('Production schema changes require the explicit D1 migration procedure.');
        }
        if (! config('d1-production.planning')) {
            return app(D1StagingRuntime::class)->run(fn (): bool => $this->statement($query, $bindings));
        }

        return DB::connection('d1-staging-snapshot')->statement($query, $bindings);
    }

    /** @param array<array-key, mixed> $bindings */
    public function affectingStatement(mixed $query, mixed $bindings = []): int
    {
        if (! config('d1-production.planning')) {
            return app(D1StagingRuntime::class)->run(fn (): int => $this->affectingStatement($query, $bindings));
        }

        return DB::connection('d1-staging-snapshot')->affectingStatement($query, $bindings);
    }

    public function beginTransaction(): void
    {
        throw new LogicException('D1 requires a transaction closure; a transaction cannot span remote requests.');
    }
}
