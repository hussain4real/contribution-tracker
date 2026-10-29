<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJobRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Synthetic D1 transport with server-clock leases and fenced acknowledgement. */
final class D1StagingQueue extends DatabaseQueue
{
    public function __construct(D1StagingConnection $database, string $table, string $default = 'default', int $retryAfter = 90, bool $dispatchAfterCommit = false)
    {
        D1RuntimeProfile::assertEnabled();
        if ($table !== 'jobs' || $retryAfter < 1) {
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

    protected function pushToDatabase(mixed $queue, mixed $payload, mixed $delay = 0, mixed $attempts = 0): int
    {
        if (DB::getDefaultConnection() !== 'd1-staging-snapshot') {
            return $this->container->make(D1StagingRuntime::class)->run(fn (): int => $this->pushToDatabase($queue, $payload, $delay, $attempts));
        }
        $connection = DB::connection('d1-staging-snapshot');

        /** @var array<string, mixed> $record */
        $record = $this->buildDatabaseRecord($this->getQueue($queue), $payload, $this->availableAt($delay), $attempts);

        return $connection->table($this->table)->insertGetId($record);
    }

    /** @param array<array-key, string|object> $jobs */
    public function bulk(mixed $jobs, mixed $data = '', mixed $queue = null): bool
    {
        if (DB::getDefaultConnection() !== 'd1-staging-snapshot') {
            return $this->container->make(D1StagingRuntime::class)->run(fn (): bool => $this->bulk($jobs, $data, $queue));
        }
        foreach ($jobs as $job) {
            $delay = is_object($job) ? $this->getAttributeValue($job, Delay::class, 'delay') : null;
            if ($delay !== null && ! is_int($delay) && ! $delay instanceof \DateInterval && ! $delay instanceof \DateTimeInterface) {
                throw new LogicException('Invalid staging job delay.');
            }
            $this->pushToDatabase($queue, $this->createPayload($job, $this->getQueue($queue), $data, $delay), $delay ?? 0);
        }

        return true;
    }

    public function pop(mixed $queue = null): ?D1StagingJob
    {
        if (! $this->database instanceof D1StagingConnection || $this->table !== 'jobs') {
            throw new LogicException('D1 queue is restricted to the isolated proof.');
        }
        $queue = $this->getQueue($queue);
        if ($this->retryAfter === null) {
            throw new LogicException('D1 queue requires a finite lease duration.');
        }
        $query = self::claimStatement($queue, $this->retryAfter, (string) Str::uuid());
        $rows = $this->database->batch([$query])[0]['results'];
        if (count($rows) > 1) {
            throw new LogicException('A D1 queue claim returned multiple jobs.');
        }

        return $rows === [] ? null : new D1StagingJob($this->container, $this, new DatabaseJobRecord((object) $rows[0]), $this->connectionName, $queue);
    }

    public function acknowledge(string $queue, DatabaseJobRecord $record): bool
    {
        $lease = $this->lease($record);

        return $this->remote()->batch([['sql' => 'delete from jobs where id = ? and queue = ? and lease_token = ? and reserved_at > unixepoch() - ? returning id', 'params' => [$lease['id'], $queue, $lease['token'], $lease['retry']]]])[0]['results'] !== [];
    }

    public function deleteReserved(mixed $queue, mixed $id): void
    {
        throw new LogicException('D1 acknowledgement requires the current lease token.');
    }

    public function deleteAndRelease(mixed $queue, mixed $job, mixed $delay): void
    {
        $record = $job->getJobRecord();
        $lease = $this->lease($record);
        $this->remote()->batch([['sql' => 'update jobs set reserved_at = null, lease_token = null, available_at = unixepoch() + ? where id = ? and queue = ? and lease_token = ? and reserved_at > unixepoch() - ?', 'params' => [$this->secondsUntil($delay), $lease['id'], $this->getQueue($queue), $lease['token'], $lease['retry']]]]);
    }

    public function release(mixed $queue, mixed $job, mixed $delay): never
    {
        throw new LogicException('D1 release must retain and fence the current job record.');
    }

    private function remote(): D1StagingConnection
    {
        if (! $this->database instanceof D1StagingConnection) {
            throw new LogicException('Unexpected D1 queue connection.');
        }

        return $this->database;
    }

    /** @return array{id: int, token: string, retry: int} */
    private function lease(DatabaseJobRecord $record): array
    {
        $id = $record->__get('id');
        $token = $record->__get('lease_token');
        if (! is_int($id) || $id < 1 || ! is_string($token) || $token === '' || $this->retryAfter === null) {
            throw new LogicException('Invalid D1 queue lease record.');
        }

        return ['id' => $id, 'token' => $token, 'retry' => $this->retryAfter];
    }
}
