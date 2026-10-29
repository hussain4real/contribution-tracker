<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJobRecord;
use Illuminate\Support\Str;
use LogicException;

/** Synthetic D1 transport with server-clock leases and fenced acknowledgement. */
final class D1ProofQueue extends DatabaseQueue
{
    public function __construct(D1ProofConnection $database, string $table, string $default = 'default', int $retryAfter = 90, bool $dispatchAfterCommit = false)
    {
        if (! app()->environment('testing') || ! config('d1-proof.enabled') || $table !== 'jobs' || $retryAfter < 1) {
            throw new LogicException('D1 queue is restricted to the isolated proof with a finite lease.');
        }
        parent::__construct($database, $table, $default, $retryAfter, $dispatchAfterCommit);
    }

    /** @return array{sql: string, params: list<scalar|null>} */
    public static function claimStatement(string $queue, int $retryAfter, string $token): array
    {
        return [
            'sql' => 'update jobs set reserved_at = unixepoch(), attempts = attempts + 1, lease_token = ? where id = (select id from jobs where queue = ? and ((reserved_at is null and available_at <= unixepoch()) or reserved_at <= unixepoch() - ?) order by id limit 1) returning *',
            'params' => [$token, $queue, $retryAfter],
        ];
    }

    public function pop(mixed $queue = null): ?D1ProofJob
    {
        if (! $this->database instanceof D1ProofConnection || $this->table !== 'jobs') {
            throw new LogicException('D1 queue is restricted to the isolated proof.');
        }
        $queue = $this->getQueue($queue);
        if ($this->retryAfter === null) {
            throw new LogicException('D1 queue requires a finite lease duration.');
        }
        $query = self::claimStatement($queue, $this->retryAfter, (string) Str::uuid());
        $row = $this->database->selectOne($query['sql'], $query['params']);

        if ($row !== null && ! $row instanceof \stdClass) {
            throw new LogicException('Invalid D1 job record.');
        }

        return $row === null ? null : new D1ProofJob($this->container, $this, new DatabaseJobRecord($row), $this->connectionName, $queue);
    }

    public function acknowledge(string $queue, DatabaseJobRecord $record): bool
    {
        return $this->database->affectingStatement('delete from jobs where id = ? and queue = ? and lease_token = ? and reserved_at > unixepoch() - ?', [$record->__get('id'), $queue, $record->__get('lease_token'), $this->retryAfter]) === 1;
    }

    public function deleteReserved(mixed $queue, mixed $id): void
    {
        throw new LogicException('D1 acknowledgement requires the current lease token.');
    }

    public function deleteAndRelease(mixed $queue, mixed $job, mixed $delay): void
    {
        $record = $job->getJobRecord();
        $this->database->affectingStatement('update jobs set reserved_at = null, lease_token = null, available_at = unixepoch() + ? where id = ? and queue = ? and lease_token = ? and reserved_at > unixepoch() - ?', [(int) $delay, $record->__get('id'), $queue, $record->__get('lease_token'), $this->retryAfter]);
    }

    public function release(mixed $queue, mixed $job, mixed $delay): never
    {
        throw new LogicException('D1 release must retain and fence the current job record.');
    }
}
