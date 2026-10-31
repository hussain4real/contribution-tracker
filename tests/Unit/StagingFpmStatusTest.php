<?php

declare(strict_types=1);

function stagingFpmTestRecord(int $type, string $body = ''): string
{
    return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
}

/** @return array{exit: int, stdout: string, stderr: string} */
function runStagingFpmProbe(?string $response): array
{
    $directory = sys_get_temp_dir().'/ff-fpm-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $socketPath = $directory.'/status.sock';
    $probePath = $directory.'/probe.php';
    $source = file_get_contents(__DIR__.'/../../deployment/d1-proof/staging-fpm-status.php');
    if ($source === false) {
        throw new RuntimeException('Missing FPM probe');
    }
    file_put_contents($probePath, str_replace('/run/php/familyfunds-staging-status.sock', $socketPath, $source));
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
        rmdir($directory);
    }
}

it('returns only validated FPM activity counts from the private listener', function (): void {
    $response = "Status: 200\r\nContent-Type: application/json\r\n\r\n".json_encode([
        'pool' => 'www', 'active processes' => 1, 'listen queue' => 0, 'total processes' => 4, 'start time' => 123,
        'request uri' => '/private-not-returned',
    ], JSON_THROW_ON_ERROR);
    $result = runStagingFpmProbe($response);

    expect($result['exit'])->toBe(0)
        ->and(json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR))->toBe([
            'active_processes' => 1, 'listen_queue' => 0, 'total_processes' => 4, 'start_time' => 123,
        ]);
});

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
    'inconsistent counts' => ["Status: 200\r\n\r\n".json_encode(['pool' => 'www', 'active processes' => 2, 'listen queue' => 0, 'total processes' => 1, 'start time' => 123], JSON_THROW_ON_ERROR)],
]);
