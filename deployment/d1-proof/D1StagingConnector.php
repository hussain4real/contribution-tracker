<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class D1StagingConnector implements ConnectorInterface
{
    /** @param array<string, mixed> $config */
    public function connect(array $config): Queue
    {
        $connection = DB::connection(D1RuntimeProfile::connection());
        $queue = $config['queue'] ?? null;
        if (! $connection instanceof D1StagingConnection || ! is_string($queue)) {
            throw new LogicException('D1 proof queue requires the isolated database and a queue name.');
        }

        return new D1StagingQueue($connection, 'jobs', $queue, 90);
    }
}
