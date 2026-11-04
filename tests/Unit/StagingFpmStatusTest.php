<?php

declare(strict_types=1);

function stagingFpmTestRecord(int $type, string $body = ''): string
{
    return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
}

/** @return array{exit: int, stdout: string, stderr: string} */
function runStagingFpmProbe(?string $response, ?string $tcp = '0: 0100007F:2328 00000000:0000 0A 00000000:00000000'): array
{
    $directory = sys_get_temp_dir().'/ff-fpm-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $socketPath = $directory.'/status.sock';
    $probePath = $directory.'/probe.php';
    $tcpPath = $directory.'/tcp';
    $source = file_get_contents(__DIR__.'/../../deployment/d1-proof/staging-fpm-status.php');
    if ($source === false) {
        throw new RuntimeException('Missing FPM probe');
    }
    file_put_contents($probePath, str_replace(['/run/php/familyfunds-staging-status.sock', '/proc/net/tcp'], [$socketPath, $tcpPath], $source));
    if ($tcp !== null) {
        file_put_contents($tcpPath, $tcp);
    }
    $server = null;
    $process = null;
    $pipes = [];
    try {
        if ($response !== null) {
            $server = stream_socket_server('unix://'.$socketPath, $error, $message);
            if ($server === false) {
                throw new RuntimeException('Unable to create synthetic FPM socket');
            }
        }
        $process = proc_open([PHP_BINARY, $probePath], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start synthetic FPM probe');
        }
        fclose($pipes[0]);
        if (is_resource($server)) {
            $client = stream_socket_accept($server, 3);
            if ($client === false) {
                throw new RuntimeException('Synthetic FPM probe did not connect');
            }
            fread($client, 8192);
            $wire = stagingFpmTestRecord(6, $response).stagingFpmTestRecord(6).stagingFpmTestRecord(3, str_repeat("\0", 8));
            while ($wire !== '') {
                $written = fwrite($client, $wire);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Could not send synthetic FPM response');
                }
                $wire = substr($wire, $written);
            }
            fclose($client);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;

        return ['exit' => $exit, 'stdout' => $stdout === false ? '' : $stdout, 'stderr' => $stderr === false ? '' : $stderr];
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        if (is_resource($server)) {
            fclose($server);
        }
        if (file_exists($socketPath)) {
            unlink($socketPath);
        }
        unlink($probePath);
        if (file_exists($tcpPath)) {
            unlink($tcpPath);
        }
        rmdir($directory);
    }
}

it('returns only validated FPM activity counts from the private listener', function (): void {
    $response = "Status: 200\r\nContent-Type: application/json\r\n\r\n".json_encode([
        'pool' => 'www', 'active processes' => 1, 'listen queue' => 0, 'listen queue len' => 64, 'total processes' => 4, 'start time' => 123,
        'processes' => [['state' => 'Finishing', 'request uri' => '/private-not-returned'], ['state' => 'Idle'], ['state' => 'Idle'], ['state' => 'Idle']],
    ], JSON_THROW_ON_ERROR);
    $result = runStagingFpmProbe($response);

    expect($result['exit'])->toBe(0)
        ->and(json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR))->toBe([
            'active_processes' => 1, 'listen_queue' => 0, 'listen_queue_capacity' => 64, 'total_processes' => 4, 'start_time' => 123,
        ]);
});

it('accepts a queued request when FPM and the kernel agree', function (): void {
    $response = "Status: 200\r\n\r\n".json_encode([
        'pool' => 'www', 'active processes' => 2, 'listen queue' => 1, 'listen queue len' => 64, 'total processes' => 2, 'start time' => 123,
        'processes' => [['state' => 'Running'], ['state' => 'Finishing']],
    ], JSON_THROW_ON_ERROR);
    $result = runStagingFpmProbe($response, '0: 0100007F:2328 00000000:0000 0A 00000000:00000001');
    $status = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($status)) {
        throw new RuntimeException('Invalid probe response');
    }
    expect($result['exit'])->toBe(0)
        ->and($status['listen_queue'] ?? null)->toBe(1);
});

it('rejects missing, malformed, duplicate or inconsistent kernel queue snapshots', function (?string $tcp): void {
    $response = "Status: 200\r\n\r\n".json_encode([
        'pool' => 'www', 'active processes' => 0, 'listen queue' => 0, 'listen queue len' => 64, 'total processes' => 1, 'start time' => 123,
        'processes' => [['state' => 'Idle']],
    ], JSON_THROW_ON_ERROR);
    $result = runStagingFpmProbe($response, $tcp);
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('');
})->with([
    'missing proc file' => [null],
    'missing listener' => [''],
    'malformed queue' => ['0: 0100007F:2328 00000000:0000 0A invalid'],
    'duplicate listener' => ["0: 0100007F:2328 00000000:0000 0A 00000000:00000000\n1: 0100007F:2328 00000000:0000 0A 00000000:00000000"],
    'queue mismatch' => ['0: 0100007F:2328 00000000:0000 0A 00000000:00000001'],
]);

it('rejects missing or inconsistent individual FPM process states', function (mixed $processes): void {
    $response = "Status: 200\r\n\r\n".json_encode([
        'pool' => 'www', 'active processes' => 0, 'listen queue' => 0, 'listen queue len' => 64, 'total processes' => 1, 'start time' => 123,
        'processes' => $processes,
    ], JSON_THROW_ON_ERROR);
    $result = runStagingFpmProbe($response);
    expect($result['exit'])->toBe(1)->and($result['stdout'])->toBe('');
})->with([
    'missing process list' => [null],
    'missing worker' => [[]],
    'unknown worker state' => [[['state' => 'Unknown']]],
    'active finishing worker with zero aggregate' => [[['state' => 'Finishing']]],
]);

it('fails closed when FPM status is unavailable or invalid', function (?string $response): void {
    $result = runStagingFpmProbe($response);

    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toBe('')
        ->and($result['stderr'])->toBe("Staging FPM status unavailable\n");
})->with([
    'missing socket' => [null],
    'bad status' => ["Status: 500\r\n\r\n{}"],
    'bad JSON' => ["Status: 200\r\n\r\nnot-json"],
    'wrong pool' => ["Status: 200\r\n\r\n".json_encode(['pool' => 'other'], JSON_THROW_ON_ERROR)],
    'inconsistent counts' => ["Status: 200\r\n\r\n".json_encode(['pool' => 'www', 'active processes' => 2, 'listen queue' => 0, 'listen queue len' => 64, 'total processes' => 1, 'start time' => 123], JSON_THROW_ON_ERROR)],
    'unavailable queue capacity' => ["Status: 200\r\n\r\n".json_encode(['pool' => 'www', 'active processes' => 0, 'listen queue' => 0, 'listen queue len' => 0, 'total processes' => 1, 'start time' => 123], JSON_THROW_ON_ERROR)],
    'impossible queue count' => ["Status: 200\r\n\r\n".json_encode(['pool' => 'www', 'active processes' => 0, 'listen queue' => 8842250, 'listen queue len' => 64, 'total processes' => 2, 'start time' => 123], JSON_THROW_ON_ERROR)],
]);
