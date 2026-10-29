<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\ReversePaymentBatch;
use App\Models\Family;
use App\Models\PaymentBatch;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Services\PaystackContributionSettlementService;
use App\Services\ReconciliationPeriodService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Synthetic proof only: reuse domain rules while committing explicit atomic D1 batches.
 *
 * @phpstan-import-type D1Row from D1ProofConnection
 *
 * @phpstan-type SchemaItem array{type: string, name: string, sql: string}
 */
final class D1FinancialCommands
{
    private const WRITABLE = [
        'payment_batches', 'payments', 'contributions', 'paystack_transactions',
        'expenses', 'financial_reversals', 'reconciliation_periods', 'audit_events',
    ];

    public function __construct(
        private readonly PaymentAllocationService $allocation,
        private readonly PaystackContributionSettlementService $settlement,
        private readonly ReversePaymentBatch $reversal,
        private readonly ReconciliationPeriodService $reconciliation,
    ) {}

    public static function install(): void
    {
        $remote = self::remote();
        $remote->statement('create table if not exists d1_proof_epoch (id integer primary key check (id = 1), revision integer not null)');
        $remote->statement('insert or ignore into d1_proof_epoch values (1, 0)');
        $remote->statement('create table if not exists d1_proof_assertions (id text primary key, passed integer not null constraint d1_proof_fresh_snapshot check (passed = 1))');
        $queries = [];
        foreach (self::schema() as $item) {
            if ($item['type'] !== 'table') {
                continue;
            }
            foreach (['insert', 'update', 'delete'] as $operation) {
                $name = self::identifier('d1_proof_epoch_'.$item['name'].'_'.$operation);
                $queries[] = ['sql' => 'create trigger if not exists '.$name.' after '.$operation.' on '.self::identifier($item['name']).' begin update d1_proof_epoch set revision = revision + 1 where id = 1; end', 'params' => []];
            }
        }
        foreach (array_chunk($queries, 20) as $chunk) {
            $remote->batch($chunk);
        }
    }

    /** @param array<string, mixed> $arguments */
    public function execute(string $command, array $arguments): Model
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $plan = $this->plan($command, $arguments);
            try {
                $this->commit($plan);
            } catch (RuntimeException $exception) {
                if (str_contains($exception->getMessage(), 'd1_proof_fresh_snapshot')) {
                    continue;
                }
                throw $exception;
            }
            if ($plan->error !== null) {
                throw $plan->error;
            }
            if ($plan->modelClass === null || $plan->modelId === null) {
                throw new LogicException('A successful financial command must return a model.');
            }

            return $plan->modelClass::on('d1-proof')->findOrFail($plan->modelId);
        }
        throw new RuntimeException('D1 financial command exhausted its conflict retry budget.');
    }

    /** @param array<string, mixed> $arguments */
    public function plan(string $command, array $arguments): D1CommandPlan
    {
        self::remote();
        if (! in_array($command, ['allocate', 'settle', 'reverse', 'close'], true)) {
            throw new LogicException('Unsupported D1 financial command.');
        }
        [$revision, $schema, $tables, $sequences] = $this->snapshot();
        config(['database.connections.d1-planner' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::purge('d1-planner');
        $local = DB::connection('d1-planner');
        $pdo = $local->getPdo();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($schema as $item) {
            $pdo->exec($item['sql']);
        }
        foreach ($tables as $table => $rows) {
            foreach ($rows as $row) {
                $this->insertRow($pdo, $table, $row);
            }
        }
        $pdo->exec('delete from sqlite_sequence');
        foreach ($sequences as $row) {
            $this->insertRow($pdo, 'sqlite_sequence', $row);
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('create table d1_planner_journal (sequence integer primary key, table_name text, operation text, row_data text)');
        foreach ($tables as $table => $rows) {
            $statement = $pdo->query('pragma table_info('.self::identifier($table).')');
            if ($statement === false) {
                throw new RuntimeException('Could not read planner columns.');
            }
            /** @var list<array{name: string}> $columns */
            $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach (['insert', 'update', 'delete'] as $operation) {
                $prefix = $operation === 'delete' ? 'old' : 'new';
                $json = implode(', ', array_map(fn (array $column): string => $pdo->quote($column['name']).', '.$prefix.'.'.self::identifier($column['name']), $columns));
                $body = in_array($table, self::WRITABLE, true)
                    ? 'insert into d1_planner_journal (table_name, operation, row_data) values ('.$pdo->quote($table).', '.$pdo->quote($operation).', json_object('.$json.'));'
                    : "select raise(abort, 'Unsupported D1 planner write');";
                $pdo->exec('create trigger '.self::identifier('d1_planner_'.$table.'_'.$operation).' after '.$operation.' on '.self::identifier($table).' begin '.$body.' end');
            }
        }
        $result = null;
        $error = null;
        DB::setDefaultConnection('d1-planner');
        try {
            $result = $this->invoke($command, $arguments);
        } catch (Throwable $exception) {
            $error = $exception;
        } finally {
            DB::setDefaultConnection('d1-proof');
        }
        try {
            $queries = [];
            $statement = $pdo->query('select * from d1_planner_journal order by sequence');
            if ($statement === false) {
                throw new RuntimeException('Could not read planner journal.');
            }
            /** @var list<array{row_data: string, table_name: string, operation: string}> $journal */
            $journal = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($journal as $entry) {
                $row = $this->journalRow($entry['row_data']);
                $table = self::identifier($entry['table_name']);
                if ($entry['operation'] === 'insert') {
                    $queries[] = ['sql' => 'insert into '.$table.' ('.implode(', ', array_map(self::identifier(...), array_keys($row))).') values ('.implode(', ', array_fill(0, count($row), '?')).')', 'params' => array_values($row)];
                } elseif ($entry['operation'] === 'update') {
                    $queries[] = ['sql' => 'update '.$table.' set '.implode(', ', array_map(fn (string $column): string => self::identifier($column).' = ?', array_keys($row))).' where id = ?', 'params' => [...array_values($row), $row['id']]];
                } else {
                    $queries[] = ['sql' => 'delete from '.$table.' where id = ?', 'params' => [$row['id']]];
                }
            }
            if (count($queries) > 17) {
                throw new RuntimeException('D1 proof command exceeds its single-batch write budget.');
            }

            $modelId = $result?->getKey();
            if ($modelId !== null && ! is_int($modelId) && ! is_string($modelId)) {
                throw new LogicException('Invalid financial model identifier.');
            }

            return new D1CommandPlan($revision, $queries, $result === null ? null : $result::class, $modelId, $error);
        } finally {
            DB::purge('d1-planner');
        }
    }

    public function commit(D1CommandPlan $plan): void
    {
        self::remote()->batch($this->batch($plan));
    }

    /** @return list<array{sql: string, params: list<scalar|null>}> */
    public function batch(D1CommandPlan $plan): array
    {
        $guard = (string) Str::uuid();

        return [
            ['sql' => 'insert into d1_proof_assertions values (?, coalesce((select revision = ? from d1_proof_epoch where id = 1), 0))', 'params' => [$guard, $plan->revision]],
            ['sql' => 'pragma defer_foreign_keys = on', 'params' => []],
            ...$plan->queries,
            ['sql' => 'delete from d1_proof_assertions where id = ?', 'params' => [$guard]],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(string $command, array $arguments): Model
    {
        return match ($command) {
            'allocate' => $this->allocation->createBatch(
                member: User::findOrFail($this->integer($arguments, 'member_id')),
                amount: $this->integer($arguments, 'amount'), paidAt: $this->text($arguments, 'paid_at'),
                recordedBy: User::findOrFail($this->integer($arguments, 'actor_id')),
                family: Family::findOrFail($this->integer($arguments, 'family_id')),
                idempotencyKey: $this->text($arguments, 'key'),
            ),
            'settle' => $this->settlement->settle($this->text($arguments, 'reference'), $this->providerData($arguments)),
            'reverse' => $this->reversal->handle(PaymentBatch::findOrFail($this->integer($arguments, 'batch_id')), User::findOrFail($this->integer($arguments, 'actor_id')), $this->text($arguments, 'reason')),
            'close' => $this->reconciliation->close(ReconciliationPeriod::findOrFail($this->integer($arguments, 'period_id')), User::findOrFail($this->integer($arguments, 'actor_id'))),
            default => throw new LogicException('Unsupported D1 financial command.'),
        };
    }

    /** @return array{int, list<SchemaItem>, array<string, list<D1Row>>, list<D1Row>} */
    private function snapshot(): array
    {
        $remote = self::remote();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $revision = $remote->integerValue('select revision from d1_proof_epoch where id = 1');
            $schema = self::schema();
            $tables = array_values(array_map(fn (array $item): string => $item['name'], array_filter($schema, fn (array $item): bool => $item['type'] === 'table')));
            $data = [];
            foreach (array_chunk($tables, 20) as $chunk) {
                $results = $remote->batch(array_map(fn (string $table): array => ['sql' => 'select * from '.self::identifier($table), 'params' => []], $chunk));
                foreach ($chunk as $index => $table) {
                    $data[$table] = $results[$index]['results'];
                }
            }
            $sequences = $remote->batch([['sql' => 'select * from sqlite_sequence', 'params' => []]])[0]['results'];
            if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 5_000_000) {
                throw new RuntimeException('Synthetic D1 snapshot exceeds the proof size budget.');
            }
            if ($revision === $remote->integerValue('select revision from d1_proof_epoch where id = 1')) {
                return [$revision, $schema, $data, $sequences];
            }
        }
        throw new RuntimeException('Could not obtain a stable synthetic D1 snapshot.');
    }

    /** @return list<SchemaItem> */
    private static function schema(): array
    {
        $items = self::remote()->batch([['sql' => "select type, name, sql from sqlite_master where type in ('table', 'index') and sql is not null and name not glob 'sqlite_*' and name not glob 'd1_*' and name not glob '_cf_*' order by case type when 'table' then 0 else 1 end, name", 'params' => []]])[0]['results'];
        $schema = [];
        foreach ($items as $item) {
            if (! isset($item['type'], $item['name'], $item['sql']) || ! is_string($item['type']) || ! is_string($item['name']) || ! is_string($item['sql'])) {
                throw new RuntimeException('Invalid D1 schema response.');
            }
            $schema[] = ['type' => $item['type'], 'name' => $item['name'], 'sql' => $item['sql']];
        }

        return $schema;
    }

    /** @param D1Row $row */
    private function insertRow(PDO $pdo, string $table, array $row): void
    {
        $pdo->prepare('insert into '.self::identifier($table).' ('.implode(', ', array_map(self::identifier(...), array_keys($row))).') values ('.implode(', ', array_fill(0, count($row), '?')).')')->execute(array_values($row));
    }

    /** @return D1Row */
    private function journalRow(string $json): array
    {
        $row = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($row) || ! isset($row['id']) || (! is_int($row['id']) && ! is_string($row['id']))) {
            throw new LogicException('Invalid financial journal row.');
        }
        $validated = [];
        foreach ($row as $key => $value) {
            if (! is_string($key) || (! is_null($value) && ! is_int($value) && ! is_float($value) && ! is_string($value))) {
                throw new LogicException('Invalid financial journal column.');
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    /** @param array<string, mixed> $arguments */
    private function integer(array $arguments, string $key): int
    {
        if (! isset($arguments[$key]) || ! is_int($arguments[$key])) {
            throw new LogicException('Expected integer command argument: '.$key);
        }

        return $arguments[$key];
    }

    /** @param array<string, mixed> $arguments */
    private function text(array $arguments, string $key): string
    {
        if (! isset($arguments[$key]) || ! is_string($arguments[$key])) {
            throw new LogicException('Expected string command argument: '.$key);
        }

        return $arguments[$key];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function providerData(array $arguments): array
    {
        $data = $arguments['provider_data'] ?? null;
        if (! is_array($data)) {
            throw new LogicException('Expected provider verification data.');
        }
        $validated = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException('Expected named provider fields.');
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    private static function identifier(string $name): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            throw new LogicException('Invalid proof schema identifier.');
        }

        return '"'.$name.'"';
    }

    private static function remote(): D1ProofConnection
    {
        $connection = DB::connection('d1-proof');
        if (! app()->environment('testing') || ! config('d1-proof.enabled') || ! $connection instanceof D1ProofConnection) {
            throw new LogicException('D1 financial commands are restricted to the isolated proof.');
        }

        return $connection;
    }
}
