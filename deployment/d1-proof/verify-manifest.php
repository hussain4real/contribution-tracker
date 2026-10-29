<?php

declare(strict_types=1);
use GuzzleHttp\Client;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$private = dirname(__DIR__, 2).'/storage/app/private/d1-staging';
$secrets = json_decode(file_get_contents($private.'/staging-secrets.json'), true, flags: JSON_THROW_ON_ERROR);
$expected = json_decode(file_get_contents($private.'/expected-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$client = new Client(['http_errors' => false, 'timeout' => 60]);
$response = $client->post('https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev/manifest', [
    'headers' => ['Authorization' => 'Bearer '.$secrets['sql_token']], 'json' => [],
]);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException('D1 manifest returned HTTP '.$response->getStatusCode());
}
$actual = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
$differences = [];
foreach ($expected['tables'] as $table => $manifest) {
    if (($actual['tables'][$table] ?? null) !== $manifest) {
        $differences[] = $table;
    }
}
$extra = array_values(array_diff(array_keys($actual['tables']), array_keys($expected['tables'])));
$result = [
    'status' => $differences === [] && $extra === [] && $actual['foreign_key_violations'] === 0 && $actual['quick_check'] === 'ok' ? 'passed' : 'failed',
    'tables_checked' => count($expected['tables']), 'durable_rows' => $expected['source_rows'],
    'mismatched_tables' => $differences, 'extra_tables' => $extra,
    'foreign_key_violations' => $actual['foreign_key_violations'], 'quick_check' => $actual['quick_check'],
    'source_snapshot_created_at' => $expected['source_created_at'], 'excluded_counts' => $expected['excluded'],
    'source_sql_sha256' => $expected['sql_sha256'], 'manifest' => $actual['tables'],
];
file_put_contents(__DIR__.'/migration-result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(array_diff_key($result, array_flip(['manifest', 'excluded_counts'])), JSON_THROW_ON_ERROR)."\n";
exit($result['status'] === 'passed' ? 0 : 1);
