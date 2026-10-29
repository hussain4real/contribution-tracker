<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @param Closure(array{directory: string, snapshot: string, database: string, sql: string, manifest: string}): void $callback */
function withD1ConversionFixture(Closure $callback): void
{
    $directory = sys_get_temp_dir().'/familyfunds-d1-conversion-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $paths = ['directory' => $directory, 'snapshot' => $directory.'/snapshot.json', 'database' => $directory.'/source.sqlite', 'sql' => $directory.'/import.sql', 'manifest' => $directory.'/manifest.json'];
    $pdo = new PDO('sqlite:'.$paths['database']);
    $pdo->exec('create table families (id integer primary key autoincrement, name text not null, created_by integer references users(id))');
    $pdo->exec('create table users (id integer primary key autoincrement, family_id integer references families(id), active boolean, details text)');
    file_put_contents($paths['snapshot'], json_encode([
        'created_at' => '2026-01-01T00:00:00Z', 'excluded' => ['sessions' => 3],
        'tables' => [
            'families' => ['columns' => ['id' => 'bigint', 'name' => 'text', 'created_by' => 'bigint'], 'rows' => [['id' => 10, 'name' => 'Synthetic Family', 'created_by' => 20]], 'sequence' => ['last_value' => 100, 'is_called' => true]],
            'users' => ['columns' => ['id' => 'bigint', 'family_id' => 'bigint', 'active' => 'boolean', 'details' => 'jsonb'], 'rows' => [['id' => 20, 'family_id' => 10, 'active' => true, 'details' => '{"b":2,"a":1}']], 'sequence' => ['last_value' => 200, 'is_called' => true]],
        ],
    ], JSON_THROW_ON_ERROR));
    try {
        $callback($paths);
    } finally {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

/** @param array{directory: string, snapshot: string, database: string, sql: string, manifest: string} $paths */
function runD1Conversion(array $paths): Process
{
    $process = new Process(['python3', __DIR__.'/../../deployment/d1-proof/convert-postgres.py', '--snapshot', $paths['snapshot'], '--sqlite', $paths['database'], '--sql', $paths['sql'], '--manifest', $paths['manifest']]);
    $process->run();

    return $process;
}

function d1ConversionValue(PDO $pdo, string $query): mixed
{
    $statement = $pdo->query($query);
    if ($statement === false) {
        throw new RuntimeException('Conversion assertion query failed.');
    }

    return $statement->fetchColumn();
}

it('restores cyclic relationships booleans JSON and sequence high-water marks under strict autocommit', function (): void {
    withD1ConversionFixture(function (array $paths): void {
        $process = runD1Conversion($paths);
        expect($process->isSuccessful())->toBeTrue();
        $restored = new PDO('sqlite::memory:');
        $restored->exec('pragma foreign_keys=on');
        $sql = file_get_contents($paths['sql']);
        assert($sql !== false);
        $restored->exec($sql);
        expect(d1ConversionValue($restored, 'select created_by from families where id=10'))->toBe(20)
            ->and(d1ConversionValue($restored, 'select family_id from users where id=20'))->toBe(10)
            ->and(d1ConversionValue($restored, 'select active from users where id=20'))->toBe(1)
            ->and(d1ConversionValue($restored, 'select details from users where id=20'))->toBe('{"a":1,"b":2}');
        $restored->exec("insert into families(name) values('Synthetic next family')");
        expect($restored->lastInsertId())->toBe('101');
    });
});

it('refuses integers that cannot round trip through D1 JavaScript bindings', function (): void {
    withD1ConversionFixture(function (array $paths): void {
        $raw = file_get_contents($paths['snapshot']);
        assert($raw !== false);
        file_put_contents($paths['snapshot'], str_replace('"id":20', '"id":9007199254740993', $raw));
        $process = runD1Conversion($paths);
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('Integer cannot round trip')
            ->and(file_exists($paths['sql']))->toBeFalse();
    });
});

it('generates the same migration SQL when rerun on the same source snapshot', function (): void {
    withD1ConversionFixture(function (array $paths): void {
        expect(runD1Conversion($paths)->isSuccessful())->toBeTrue();
        $first = hash_file('sha256', $paths['sql']);
        expect(runD1Conversion($paths)->isSuccessful())->toBeTrue()
            ->and(hash_file('sha256', $paths['sql']))->toBe($first);
    });
});
