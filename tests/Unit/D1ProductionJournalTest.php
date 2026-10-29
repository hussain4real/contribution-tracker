<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1RuntimeJournal;

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
});

function productionJournalDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('create table ledger(id integer primary key,amount integer,code text unique)');
    $pdo->exec('create table d1_staging_assertions(id text primary key,passed integer check(passed=1))');

    return $pdo;
}

/**
 * @param  array<string, int|string|null>  $row
 * @return array{table_name: string, operation: string, row_data: string, key_data: string}
 */
function productionJournalEntry(string $operation, array $row): array
{
    return ['table_name' => 'ledger', 'operation' => $operation, 'row_data' => json_encode($row, JSON_THROW_ON_ERROR), 'key_data' => json_encode(['id' => $row['id']], JSON_THROW_ON_ERROR)];
}

function productionJournalQuery(PDO $pdo, string $sql): PDOStatement
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Fixture query failed.');
    }

    return $statement;
}

/** @param list<array{sql: string, params: list<scalar|null>}> $queries */
function executeProductionJournal(PDO $pdo, array $queries): void
{
    $pdo->beginTransaction();
    try {
        foreach ($queries as $query) {
            expect(count($query['params']))->toBeLessThanOrEqual(100);
            $statement = $pdo->prepare($query['sql']);
            $statement->execute($query['params']);
            $statement->fetchAll();
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

it('commits a thousand-row update under the native single-batch limit without dropping freshness guards', function (): void {
    $pdo = productionJournalDatabase();
    $original = [];
    $journal = [];
    for ($id = 1; $id <= 1002; $id++) {
        $row = ['id' => $id, 'amount' => 10, 'code' => 'item-'.$id];
        $original[] = $row;
        $pdo->prepare('insert into ledger values(?,?,?)')->execute(array_values($row));
        $journal[] = productionJournalEntry('update', [...$row, 'amount' => 77]);
    }
    $plan = D1RuntimeJournal::plan($journal, ['ledger' => $original], $pdo);
    expect(count($plan))->toBe(22);
    if (getenv('D1_PRODUCTION_CANDIDATE_JOURNAL') === '1') {
        $path = __DIR__.'/../../storage/app/private/production-candidate/journal.json';
        file_put_contents($path, json_encode(['database' => 'familyfunds-production', 'synthetic_only' => true, 'original' => $original, 'plan' => $plan], JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }
    executeProductionJournal($pdo, $plan);
    expect(productionJournalQuery($pdo, 'select sum(amount) from ledger')->fetchColumn())->toBe(1002 * 77);
});

it('rolls back every mutation if one original row changed after the snapshot', function (): void {
    $pdo = productionJournalDatabase();
    $rows = [['id' => 1, 'amount' => 10, 'code' => 'first'], ['id' => 2, 'amount' => 20, 'code' => 'second']];
    foreach ($rows as $row) {
        $pdo->prepare('insert into ledger values(?,?,?)')->execute(array_values($row));
    }
    $plan = D1RuntimeJournal::plan(array_map(fn (array $row): array => productionJournalEntry('update', [...$row, 'amount' => 99]), $rows), ['ledger' => $rows], $pdo);
    $pdo->exec('update ledger set amount=55 where id=2');
    expect(fn () => executeProductionJournal($pdo, $plan))->toThrow(PDOException::class)
        ->and(productionJournalQuery($pdo, 'select sum(amount) from ledger')->fetchColumn())->toBe(65);
});

it('preserves sequential unique-value changes and repeated writes to the same identity', function (): void {
    $pdo = productionJournalDatabase();
    $rows = [['id' => 1, 'amount' => 10, 'code' => 'first'], ['id' => 2, 'amount' => 20, 'code' => 'second']];
    foreach ($rows as $row) {
        $pdo->prepare('insert into ledger values(?,?,?)')->execute(array_values($row));
    }
    $journal = [productionJournalEntry('update', [...$rows[0], 'code' => 'temporary']), productionJournalEntry('update', [...$rows[1], 'code' => 'first']), productionJournalEntry('update', [...$rows[0], 'code' => 'second'])];
    executeProductionJournal($pdo, D1RuntimeJournal::plan($journal, ['ledger' => $rows], $pdo));
    expect(productionJournalQuery($pdo, 'select code from ledger order by id')->fetchAll(PDO::FETCH_COLUMN))->toBe(['second', 'first']);
});

it('handles large insert and delete groups while preserving nulls Unicode and numeric values', function (): void {
    $pdo = productionJournalDatabase();
    $rows = [];
    for ($id = 1; $id <= 1002; $id++) {
        $rows[] = ['id' => $id, 'amount' => $id, 'code' => $id === 1 ? null : 'رمز-'.$id];
    }
    $plan = D1RuntimeJournal::plan(array_map(fn (array $row): array => productionJournalEntry('insert', $row), $rows), ['ledger' => []], $pdo);
    expect(count($plan))->toBe(11);
    executeProductionJournal($pdo, $plan);
    expect(productionJournalQuery($pdo, 'select sum(amount) from ledger')->fetchColumn())->toBe(502503)
        ->and(productionJournalQuery($pdo, 'select code from ledger where id=2')->fetchColumn())->toBe('رمز-2');
    $plan = D1RuntimeJournal::plan(array_map(fn (array $row): array => productionJournalEntry('delete', $row), $rows), ['ledger' => $rows], $pdo);
    expect(count($plan))->toBe(22);
    executeProductionJournal($pdo, $plan);
    expect(productionJournalQuery($pdo, 'select count(*) from ledger')->fetchColumn())->toBe(0);
});
