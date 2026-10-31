<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @param resource $stream */
function readStagingFpmBytes($stream, int $length): string
{
    $bytes = '';
    while (strlen($bytes) < $length) {
        $remaining = $length - strlen($bytes);
        if ($remaining < 1) {
            throw new RuntimeException('Invalid FastCGI response length');
        }
        $part = fread($stream, $remaining);
        if ($part === false || $part === '') {
            throw new RuntimeException('Incomplete FastCGI response');
        }
        $bytes .= $part;
    }

    return $bytes;
}

function stagingFpmRecord(int $type, string $body = ''): string
{
    return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
}

try {
    $stream = @stream_socket_client('unix:///run/php/familyfunds-staging-status.sock', $error, $message, 2);
    if ($stream === false) {
        throw new RuntimeException('Cannot connect to staging FPM');
    }
    stream_set_timeout($stream, 2);
    $params = [
        'REQUEST_METHOD' => 'GET',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SCRIPT_FILENAME' => '/__staging_fpm_status',
        'SCRIPT_NAME' => '/__staging_fpm_status',
        'REQUEST_URI' => '/__staging_fpm_status?json&full',
        'QUERY_STRING' => 'json&full',
    ];
    $encoded = '';
    foreach ($params as $name => $value) {
        $encoded .= chr(strlen($name)).chr(strlen($value)).$name.$value;
    }
    $request = stagingFpmRecord(1, pack('nCxxxxx', 1, 0)).stagingFpmRecord(4, $encoded).stagingFpmRecord(4).stagingFpmRecord(5);
    while ($request !== '') {
        $written = fwrite($stream, $request);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Incomplete FastCGI request');
        }
        $request = substr($request, $written);
    }
    $output = '';
    do {
        $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', readStagingFpmBytes($stream, 8));
        if ($header === false || $header['version'] !== 1 || $header['id'] !== 1
            || ! is_int($header['length']) || ! is_int($header['padding'])) {
            throw new RuntimeException('Invalid FastCGI response');
        }
        $content = readStagingFpmBytes($stream, $header['length']);
        readStagingFpmBytes($stream, $header['padding']);
        if ($header['type'] === 6) {
            $output .= $content;
        } elseif ($header['type'] === 7 && $content !== '') {
            throw new RuntimeException('FastCGI status failed');
        }
        if (strlen($output) > 65536) {
            throw new RuntimeException('Excessive FastCGI response');
        }
    } while ($header['type'] !== 3);
    fclose($stream);
    if (strlen($content) !== 8 || substr($content, 0, 5) !== "\0\0\0\0\0") {
        throw new RuntimeException('FastCGI status did not complete');
    }
    $parts = explode("\r\n\r\n", $output, 2);
    if (count($parts) !== 2 || (preg_match('/^Status:\s*(\d{3})/mi', $parts[0], $matches) && $matches[1] !== '200')) {
        throw new RuntimeException('Invalid FPM status response');
    }
    $status = json_decode($parts[1], true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($status) || ($status['pool'] ?? null) !== 'www') {
        throw new RuntimeException('Invalid FPM status pool');
    }
    foreach (['active processes', 'listen queue', 'listen queue len', 'total processes', 'start time'] as $field) {
        if (! is_int($status[$field] ?? null) || $status[$field] < 0) {
            throw new RuntimeException('Invalid FPM status count');
        }
    }
    if ($status['total processes'] < 1 || $status['active processes'] > $status['total processes'] || $status['start time'] < 1
        || $status['listen queue len'] < 1 || $status['listen queue'] > $status['listen queue len'] + 1) {
        throw new RuntimeException('Inconsistent FPM status');
    }
    $processes = $status['processes'] ?? null;
    if (! is_array($processes) || ! array_is_list($processes) || count($processes) !== $status['total processes']) {
        throw new RuntimeException('Incomplete FPM process status');
    }
    $activeProcesses = 0;
    foreach ($processes as $process) {
        $state = is_array($process) ? ($process['state'] ?? null) : null;
        if (! is_string($state) || ! in_array($state, ['Creating', 'Idle', 'Reading headers', 'Getting request information', 'Running', 'Ending', 'Finishing'], true)) {
            throw new RuntimeException('Invalid FPM process state');
        }
        if ($state !== 'Idle') {
            $activeProcesses++;
        }
    }
    if ($activeProcesses !== $status['active processes']) {
        throw new RuntimeException('Inconsistent FPM process status');
    }
    $tcp = @file_get_contents('/proc/net/tcp', false, null, 0, 1048577);
    if ($tcp === false || strlen($tcp) > 1048576) {
        throw new RuntimeException('Kernel TCP status unavailable');
    }
    $queues = [];
    foreach (explode("\n", $tcp) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (is_array($fields) && ($fields[1] ?? '') === '0100007F:2328' && ($fields[3] ?? '') === '0A') {
            if (! preg_match('/^[0-9A-F]{8}:([0-9A-F]{8})$/', $fields[4] ?? '', $queue)) {
                throw new RuntimeException('Invalid kernel TCP status');
            }
            $queues[] = hexdec($queue[1]);
        }
    }
    if (count($queues) !== 1 || $queues[0] !== $status['listen queue']) {
        throw new RuntimeException('Inconsistent kernel TCP status');
    }
    echo json_encode([
        'active_processes' => $status['active processes'],
        'listen_queue' => $status['listen queue'],
        'listen_queue_capacity' => $status['listen queue len'],
        'total_processes' => $status['total processes'],
        'start_time' => $status['start time'],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Staging FPM status unavailable\n");
    exit(1);
}
