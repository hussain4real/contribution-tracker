<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Queue\Jobs\DatabaseJob;
use LogicException;
use Throwable;

final class D1StagingJob extends DatabaseJob
{
    private bool $executing = false;

    private bool $pendingDelete = false;

    private ?int $pendingRelease = null;

    public function fire(): void
    {
        $this->executing = true;
        try {
            $this->container->make(D1StagingRuntime::class)->run(function (): void {
                parent::fire();
            });
        } catch (Throwable $exception) {
            $this->deleted = false;
            $this->released = false;
            $this->pendingDelete = false;
            $this->pendingRelease = null;
            throw $exception;
        } finally {
            $this->executing = false;
        }
        if ($this->pendingDelete) {
            $this->delete();
        } elseif ($this->pendingRelease !== null) {
            $this->release($this->pendingRelease);
        }
    }

    public function release(mixed $delay = 0): void
    {
        if ($this->executing) {
            $this->pendingRelease = (int) $delay;
            $this->released = true;

            return;
        }
        parent::release($delay);
    }

    public function leaseToken(): string
    {
        $token = $this->job->__get('lease_token');
        if (! is_string($token)) {
            throw new LogicException('Invalid D1 lease token.');
        }

        return $token;
    }

    public function delete(): void
    {
        if ($this->executing) {
            $this->pendingDelete = true;
            $this->deleted = true;

            return;
        }
        if (! $this->database instanceof D1StagingQueue) {
            throw new LogicException('Unexpected D1 queue transport.');
        }
        $this->database->acknowledge($this->queue, $this->job);
        $this->deleted = true;
    }
}
