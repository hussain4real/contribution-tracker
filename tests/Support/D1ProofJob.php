<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Queue\Jobs\DatabaseJob;
use LogicException;

final class D1ProofJob extends DatabaseJob
{
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
        if (! $this->database instanceof D1ProofQueue) {
            throw new LogicException('Unexpected D1 queue transport.');
        }
        $this->database->acknowledge($this->queue, $this->job);
        $this->deleted = true;
    }
}
