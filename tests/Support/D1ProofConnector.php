<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class D1ProofConnector implements ConnectorInterface
{
    /** @param array<string, mixed> $config */
    public function connect(array $config): Queue
    {
        $connection = DB::connection('d1-proof');
        $queue = $config['queue'] ?? null;
        if (! $connection instanceof D1ProofConnection || ! is_string($queue)) {
            throw new LogicException('D1 proof queue requires the isolated database and a queue name.');
        }

        return new D1ProofQueue($connection, 'jobs', $queue, 90);
    }
}
