<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Leased request planner shared by independently validated runtime profiles.
 *
 * @phpstan-import-type D1Row from D1StagingConnection
 * @phpstan-import-type D1Result from D1StagingConnection
 *
 * @phpstan-type SchemaItem array{type: string, name: string, sql: string}
 */
final class D1StagingRuntime
{
    public static function install(): void
    {
        $remote = self::remote();
        $columns = $remote->batch([['sql' => 'pragma table_info(jobs)', 'params' => []]])[0]['results'];
        if (! in_array('lease_token', array_column($columns, 'name'), true)) {
            $remote->batch([['sql' => 'alter table jobs add column lease_token text', 'params' => []]]);
        }
        $remote->batch([['sql' => 'create table if not exists d1_staging_leases (scope text primary key, token text not null, expires_at integer not null)', 'params' => []]]);
        $remote->batch([['sql' => 'create table if not exists d1_staging_sequences (name text primary key, next_id integer not null)', 'params' => []]]);
        $remote->batch([['sql' => 'create table if not exists d1_staging_assertions (id text primary key, passed integer not null constraint d1_staging_fresh_snapshot check (passed = 1))', 'params' => []]]);
        $queries = [];
        foreach (self::schema() as $item) {
            if ($item['type'] === 'table' && str_contains(strtolower($item['sql']), 'autoincrement')) {
                $queries[] = ['sql' => 'insert into d1_staging_sequences(name,next_id) values (?,coalesce((select seq from sqlite_sequence where name=?),0)) on conflict(name) do update set next_id=max(next_id,excluded.next_id)', 'params' => [$item['name'], $item['name']]];
            }
        }
        foreach ($remote->batch([['sql' => "select name from sqlite_master where type='trigger' and name glob 'd1_staging_epoch_*'", 'params' => []]])[0]['results'] as $trigger) {
            if (! is_string($trigger['name'])) {
                throw new RuntimeException('Invalid staging trigger name.');
            }
            $queries[] = ['sql' => 'drop trigger '.self::identifier($trigger['name']), 'params' => []];
        }
        foreach (array_chunk($queries, 100) as $chunk) {
            $remote->batch($chunk);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Closure $callback, ?int $familyId = null, ?int $actorId = null): mixed
    {
        return $this->runWithLease($callback, $familyId, $actorId);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function runWithLease(Closure $callback, ?int $familyId, ?int $actorId, bool $readOnly = false): mixed
    {
        if (config(D1RuntimeProfile::key('planning'))) {
            throw new LogicException('Nested staging request planners are not supported.');
        }
        if (($familyId !== null && $familyId < 1) || ($actorId !== null && $actorId < 1)) {
            throw new LogicException('Invalid staging family scope.');
        }
        $scope = $readOnly || $familyId === null ? 'global' : 'family:'.$familyId;
        $token = (string) Str::uuid();
        $deadline = microtime(true) + 45;
        do {
            $lease = self::remote()->batch([self::leaseStatement($scope, $token), self::schemaQuery()]);
            $acquired = $lease[0]['results'];
            if ($acquired !== []) {
                break;
            }
            usleep(random_int(50_000, 100_000));
        } while (microtime(true) < $deadline);
        if ($acquired === []) {
            throw new RuntimeException('D1 staging write lease is busy; retry this request.');
        }
        try {
            $result = $this->execute($callback, $familyId, $actorId, $scope, $readOnly ? null : $token, self::schemaRows($lease[1]['results']));
            if ($readOnly && self::remote()->integerValue('select count(*) from d1_staging_leases where scope=? and token=? and expires_at>unixepoch()', [$scope, $token]) !== 1) {
                throw new RuntimeException('The staging read lease expired before the snapshot completed.');
            }

            return $result;
        } finally {
            self::remote()->batch([['sql' => 'delete from d1_staging_leases where scope=? and token=?', 'params' => [$scope, $token]]]);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function runReadOnly(Closure $callback, int $familyId, int $actorId): mixed
    {
        if (config(D1RuntimeProfile::key('planning')) || $familyId < 1 || $actorId < 1) {
            throw new LogicException('Invalid or nested read-only staging request.');
        }

        if (config(D1RuntimeProfile::key('read_snapshot_cache'))) {
            return $this->cachedReadOnly($callback, $familyId, $actorId);
        }

        return $this->execute($callback, $familyId, $actorId, 'family:'.$familyId, null);
    }

    public static function installReadCache(): void
    {
        $remote = self::remote();
        $remote->batch([['sql' => 'create table if not exists d1_staging_read_revision (id integer primary key check(id=1), revision integer not null)', 'params' => []]]);
        $remote->batch([['sql' => 'insert or ignore into d1_staging_read_revision values(1,0)', 'params' => []]]);
        $queries = [];
        foreach (self::schema() as $item) {
            if ($item['type'] !== 'table' || in_array($item['name'], ['sessions', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks'], true)) {
                continue;
            }
            foreach (['insert', 'update', 'delete'] as $operation) {
                $queries[] = ['sql' => 'create trigger if not exists '.self::identifier('d1_read_revision_'.$item['name'].'_'.$operation).' after '.$operation.' on '.self::identifier($item['name']).' begin update d1_staging_read_revision set revision=revision+1 where id=1; end', 'params' => []];
            }
        }
        foreach (array_chunk($queries, 100) as $chunk) {
            $remote->batch($chunk);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function cachedReadOnly(Closure $callback, int $familyId, int $actorId): mixed
    {
        $directory = config(D1RuntimeProfile::key('read_snapshot_path'));
        $database = config(D1RuntimeProfile::key('database'));
        if (! is_string($directory) || ! is_string($database)
            || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new RuntimeException('Could not prepare the private staging read cache.');
        }
        $path = $directory.'/'.hash('sha256', $database.':'.$familyId).'.sqlite';
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the staging read cache lock.');
        }
        chmod($path.'.lock', 0600);
        try {
            if (! flock($lock, LOCK_SH)) {
                throw new RuntimeException('Could not lock the staging read cache.');
            }
            $version = is_file($path) ? $this->readVersion($familyId, $actorId) : '';
            if ($version === null) {
                return $this->execute($callback, $familyId, $actorId, 'family:'.$familyId, null);
            }
            if (! $this->cacheMatches($path, $version)) {
                flock($lock, LOCK_UN);
                if (! flock($lock, LOCK_EX)) {
                    throw new RuntimeException('Could not lock the staging read cache builder.');
                }
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $version = is_file($path) ? $this->readVersion($familyId, $actorId) : '';
                    if ($version === null) {
                        return $this->execute($callback, $familyId, $actorId, 'family:'.$familyId, null);
                    }
                    if ($this->cacheMatches($path, $version)) {
                        break;
                    }
                    $temporary = $path.'.'.Str::uuid().'.tmp';
                    try {
                        [$schema, $tables, $sequences, $capturedVersion, $expandedParents] = $this->snapshot($familyId, $actorId, false, true);
                        if ($capturedVersion === null || ($expandedParents && $this->readVersion($familyId, $actorId) !== $capturedVersion)) {
                            continue;
                        }
                        $version = $capturedVersion;
                        $pdo = new PDO('sqlite:'.$temporary);
                        chmod($temporary, 0600);
                        $this->importSnapshot($pdo, $schema, $tables, $sequences);
                        $pdo->exec('create table d1_staging_cache_version (version text not null)');
                        $pdo->prepare('insert into d1_staging_cache_version values(?)')->execute([$version]);
                        unset($pdo);
                        if (! rename($temporary, $path)) {
                            throw new RuntimeException('Could not publish the staging read cache.');
                        }
                        break;
                    } finally {
                        if (is_file($temporary)) {
                            unlink($temporary);
                        }
                    }
                }
                if (! $this->cacheMatches($path, $version)) {
                    flock($lock, LOCK_UN);

                    return $this->runWithLease($callback, $familyId, $actorId, true);
                }
                flock($lock, LOCK_SH);
            }
            config(['database.connections.d1-staging-snapshot' => ['driver' => 'sqlite', 'database' => $path, 'foreign_key_constraints' => true]]);
            DB::purge('d1-staging-snapshot');

            return $this->readCallback($callback, DB::connection('d1-staging-snapshot')->getPdo());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readVersion(int $familyId, int $actorId): ?string
    {
        return self::parseReadVersion(self::remote()->batch(self::readVersionQueries($familyId, $actorId)));
    }

    /** @return list<array{sql: string, params: list<scalar|null>}> */
    private static function readVersionQueries(int $familyId, int $actorId): array
    {
        return [
            ['sql' => 'select revision from d1_staging_read_revision where id=1 and exists(select 1 from family_members where family_id=? and user_id=?)', 'params' => [$familyId, $actorId]],
            ['sql' => "select group_concat(sql,char(10)) as definition from (select sql from sqlite_master where sql is not null and name not glob 'sqlite_*' and name not glob 'd1_staging_*' and name not glob '_cf_*' order by type,name)", 'params' => []],
            ['sql' => "select count(*) as missing from sqlite_master t where t.type='table' and t.name not glob 'sqlite_*' and t.name not glob 'd1_*' and t.name not glob '_cf_*' and t.name not in ('sessions','jobs','failed_jobs','job_batches','cache','cache_locks') and (select count(*) from sqlite_master r where r.type='trigger' and r.tbl_name=t.name and r.name in ('d1_read_revision_'||t.name||'_insert','d1_read_revision_'||t.name||'_update','d1_read_revision_'||t.name||'_delete'))<>3", 'params' => []],
        ];
    }

    /** @param list<D1Result> $results */
    private static function parseReadVersion(array $results): ?string
    {
        if (($results[2]['results'][0]['missing'] ?? null) !== 0) {
            throw new RuntimeException('Install read revision triggers for the current staging schema before caching it.');
        }
        if ($results[0]['results'] === []) {
            return null;
        }
        $revision = $results[0]['results'][0]['revision'] ?? null;
        $schema = $results[1]['results'][0]['definition'] ?? null;
        if (! is_int($revision) || ! is_string($schema)) {
            throw new RuntimeException('Invalid staging read revision.');
        }

        return $revision.':'.hash('sha256', $schema);
    }

    private function cacheMatches(string $path, string $version): bool
    {
        if (! is_file($path)) {
            return false;
        }
        try {
            $pdo = new PDO('sqlite:'.$path);
            $statement = $pdo->query('select version from d1_staging_cache_version');

            return $statement !== false && $statement->fetchColumn() === $version;
        } catch (PDOException) {
            return false;
        }
    }

    /** @return array{sql: string, params: list<scalar|null>} */
    public static function leaseStatement(string $scope, string $token): array
    {
        return [
            'sql' => "insert into d1_staging_leases(scope,token,expires_at) select ?,?,unixepoch()+120 where not exists(select 1 from d1_staging_leases where expires_at>unixepoch() and (scope='global' or ?='global')) on conflict(scope) do update set token=excluded.token,expires_at=excluded.expires_at where expires_at<=unixepoch() returning token",
            'params' => [$scope, $token, $scope],
        ];
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @param  list<SchemaItem>|null  $schema
     * @return TResult
     */
    private function execute(Closure $callback, ?int $familyId, ?int $actorId, string $scope, ?string $token, ?array $schema = null): mixed
    {
        if (config(D1RuntimeProfile::key('planning'))) {
            throw new LogicException('Nested staging request planners are not supported.');
        }
        [$schema, $tables, $sequences] = $this->snapshot($familyId, $actorId, $token !== null, schema: $schema);
        config(['database.connections.d1-staging-snapshot' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::purge('d1-staging-snapshot');
        $local = DB::connection('d1-staging-snapshot');
        $pdo = $local->getPdo();
        $this->importSnapshot($pdo, $schema, $tables, $sequences);
        if ($token === null) {
            return $this->readCallback($callback, $pdo);
        }
        $pdo->exec('create table d1_staging_journal (sequence integer primary key, table_name text, operation text, row_data text, key_data text)');
        foreach (array_keys($tables) as $table) {
            $statement = $pdo->query('pragma table_info('.self::identifier($table).')');
            if ($statement === false) {
                throw new RuntimeException('Could not inspect staging snapshot columns.');
            }
            /** @var list<array{name: string, pk: int}> $columns */
            $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
            $keys = array_values(array_filter($columns, fn (array $column): bool => $column['pk'] > 0));
            foreach (['insert', 'update', 'delete'] as $operation) {
                $prefix = $operation === 'delete' ? 'old' : 'new';
                $keyPrefix = $operation === 'insert' ? 'new' : 'old';
                $json = implode(', ', array_map(fn (array $column): string => $pdo->quote($column['name']).', '.$prefix.'.'.self::identifier($column['name']), $columns));
                $keyJson = implode(', ', array_map(fn (array $column): string => $pdo->quote($column['name']).', '.$keyPrefix.'.'.self::identifier($column['name']), $keys));
                $body = $keys === []
                    ? "select raise(abort, 'D1 staging cannot write a table without a primary key');"
                    : 'insert into d1_staging_journal (table_name, operation, row_data, key_data) values ('.$pdo->quote($table).', '.$pdo->quote($operation).', json_object('.$json.'), json_object('.$keyJson.'));';
                $pdo->exec('create trigger '.self::identifier('d1_staging_'.$table.'_'.$operation).' after '.$operation.' on '.self::identifier($table).' begin '.$body.' end');
            }
        }
        $default = DB::getDefaultConnection();
        $result = null;
        $error = null;
        DB::setDefaultConnection('d1-staging-snapshot');
        config([D1RuntimeProfile::key('planning') => true]);
        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $error = $exception;
        } finally {
            DB::setDefaultConnection($default);
            config([D1RuntimeProfile::key('planning') => false]);
        }
        try {
            $statement = $pdo->query('select * from d1_staging_journal order by sequence');
            if ($statement === false) {
                throw new RuntimeException('Could not inspect staging write journal.');
            }
            /** @var list<array{row_data: string, key_data: string, table_name: string, operation: string}> $journal */
            $journal = $statement->fetchAll(PDO::FETCH_ASSOC);
            $queries = [];
            $guards = [];
            $guarded = [];
            $starts = [];
            foreach ($sequences as $sequence) {
                if (! is_string($sequence['name']) || ! is_int($sequence['seq'])) {
                    throw new RuntimeException('Invalid reserved staging identifier range.');
                }
                $starts[$sequence['name']] = $sequence['seq'];
            }
            foreach ($journal as $entry) {
                $row = $this->journalRow($entry['row_data']);
                $keys = $this->journalRow($entry['key_data']);
                $table = self::identifier($entry['table_name']);
                if ($entry['operation'] === 'insert' && isset($starts[$entry['table_name']]) && isset($row['id']) && is_int($row['id']) && ($row['id'] <= $starts[$entry['table_name']] || $row['id'] > $starts[$entry['table_name']] + D1RuntimeProfile::identifierReservation())) {
                    throw new RuntimeException('D1 staging exhausted its reserved identifier range.');
                }
                if (D1RuntimeProfile::production()) {
                    continue;
                }
                $identity = $entry['table_name'].json_encode($keys, JSON_THROW_ON_ERROR);
                if ($entry['operation'] !== 'insert' && ! isset($guarded[$identity])) {
                    $original = array_find($tables[$entry['table_name']], fn (array $candidate): bool => array_intersect_key($candidate, $keys) === $keys);
                    if ($original !== null) {
                        $checks = implode(' and ', array_map(fn (string $column): string => self::identifier($column).' is ?', array_keys($original)));
                        $guards[] = ['sql' => 'insert into d1_staging_assertions values (?,exists(select 1 from '.$table.' where '.$checks.'))', 'params' => [(string) Str::uuid(), ...array_values($original)]];
                    }
                    $guarded[$identity] = true;
                }
                $where = implode(' and ', array_map(fn (string $key): string => self::identifier($key).' is ?', array_keys($keys)));
                if ($entry['operation'] === 'insert') {
                    $queries[] = ['sql' => 'insert into '.$table.' ('.implode(', ', array_map(self::identifier(...), array_keys($row))).') values ('.implode(', ', array_fill(0, count($row), '?')).')', 'params' => array_values($row)];
                } elseif ($entry['operation'] === 'update') {
                    $queries[] = ['sql' => 'update '.$table.' set '.implode(', ', array_map(fn (string $column): string => self::identifier($column).' = ?', array_keys($row))).' where '.$where, 'params' => [...array_values($row), ...array_values($keys)]];
                } else {
                    $queries[] = ['sql' => 'delete from '.$table.' where '.$where, 'params' => array_values($keys)];
                }
            }
            if (D1RuntimeProfile::production() && $journal !== []) {
                $queries = D1RuntimeJournal::plan($journal, $tables, $pdo);
            }
            if ($queries !== []) {
                $this->commit($scope, $token, [...$guards, ...$queries]);
            }
        } finally {
            DB::purge('d1-staging-snapshot');
            $this->restoreProductionAlias();
        }
        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    /** @param list<array{sql: string, params: list<scalar|null>}> $queries */
    private function commit(string $scope, string $token, array $queries): void
    {
        if (count($queries) > 997) {
            throw new RuntimeException('D1 staging exceeds its single-batch write budget.');
        }
        $guard = (string) Str::uuid();
        self::remote()->batch([
            ['sql' => 'insert into d1_staging_assertions values (?, coalesce((select token=? and expires_at>unixepoch() from d1_staging_leases where scope=?),0))', 'params' => [$guard, $token, $scope]],
            ['sql' => 'pragma defer_foreign_keys = on', 'params' => []],
            ...$queries,
            ['sql' => 'delete from d1_staging_assertions', 'params' => []],
        ]);
    }

    /**
     * @param  list<SchemaItem>|null  $schema
     * @return array{list<SchemaItem>, array<string, list<D1Row>>, list<D1Row>, ?string, bool}
     */
    private function snapshot(?int $familyId, ?int $actorId, bool $reserveIdentifiers = true, bool $captureVersion = false, ?array $schema = null): array
    {
        $remote = self::remote();
        $schemaVersion = null;
        if ($captureVersion) {
            if ($familyId === null || $actorId === null || $reserveIdentifiers) {
                throw new LogicException('A versioned read snapshot requires a family member and cannot reserve identifiers.');
            }
            $schemaResults = $remote->batch([self::schemaQuery(), ...self::readVersionQueries($familyId, $actorId)]);
            $schema = self::schemaRows($schemaResults[0]['results']);
            $schemaVersion = self::parseReadVersion(array_slice($schemaResults, 1));
        } else {
            $schema ??= self::schema();
        }
        $tables = array_values(array_map(fn (array $item): string => $item['name'], array_filter($schema, fn (array $item): bool => $item['type'] === 'table')));
        $queries = [];
        foreach ($tables as $table) {
            $item = array_find($schema, fn (array $item): bool => $item['name'] === $table);
            assert($item !== null);
            $queries[] = ['sql' => 'select * from '.self::identifier($table).self::scopePredicate($table, $item['sql'], $familyId, $actorId), 'params' => []];
        }
        if ($reserveIdentifiers) {
            $reservation = D1RuntimeProfile::identifierReservation();
            $queries[] = ['sql' => 'update d1_staging_sequences set next_id=max(next_id,coalesce((select seq from sqlite_sequence where name=d1_staging_sequences.name),0))+'.$reservation.' returning name,next_id-'.$reservation.' as seq', 'params' => []];
        }
        $versionOffset = count($queries);
        if ($captureVersion) {
            array_push($queries, ...self::readVersionQueries($familyId, $actorId));
        }
        $results = $remote->batch($queries);
        $capturedVersion = $captureVersion ? self::parseReadVersion(array_slice($results, $versionOffset)) : null;
        if ($schemaVersion !== null && $capturedVersion !== null
            && (explode(':', $schemaVersion, 2)[1] ?? '') !== (explode(':', $capturedVersion, 2)[1] ?? '')) {
            throw new RuntimeException('The staging schema changed during snapshot capture.');
        }
        $data = [];
        foreach ($tables as $index => $table) {
            $data[$table] = $results[$index]['results'];
        }
        $sequences = $reserveIdentifiers ? $results[count($tables)]['results'] : [];
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 20_000_000) {
            throw new RuntimeException('Isolated D1 staging family snapshot exceeds its size budget.');
        }
        $expandedParents = $this->includeReferencedParents($schema, $data);

        return [$schema, $data, $sequences, $capturedVersion, $expandedParents];
    }

    private static function scopePredicate(string $table, string $ddl, ?int $familyId, ?int $actorId): string
    {
        if ($table === 'sessions') {
            return ' where 0';
        }
        if ($familyId === null) {
            return '';
        }
        $users = 'select user_id from family_members where family_id='.$familyId;
        if ($table === 'families') {
            return ' where id='.$familyId;
        }
        if ($table === 'users') {
            return ' where family_id='.$familyId.' or id in ('.$users.')'.($actorId === null ? '' : ' or id='.$actorId);
        }
        if ($table === 'family_members') {
            return ' where family_id='.$familyId.' or user_id in ('.$users.')'.($actorId === null ? '' : ' or user_id='.$actorId);
        }
        if (str_contains($ddl, '"family_id"')) {
            return ' where family_id='.$familyId;
        }

        return match ($table) {
            'migrations', 'platform_plans', 'features' => '',
            'payments' => ' where contribution_id in (select id from contributions where family_id='.$familyId.')',
            'family_membership_category_assignments' => ' where family_membership_id in (select id from family_members where family_id='.$familyId.')',
            'report_deliveries' => ' where report_schedule_id in (select id from report_schedules where family_id='.$familyId.')',
            'provider_settlement_items' => ' where provider_settlement_group_id in (select id from provider_settlement_groups where family_id='.$familyId.')',
            'passkeys', 'agent_conversations', 'agent_conversation_messages' => ' where user_id in ('.$users.')',
            'notifications' => ' where notifiable_id in ('.$users.')',
            'push_subscriptions' => ' where subscribable_id in ('.$users.')',
            default => ' where 0',
        };
    }

    /**
     * @param  list<SchemaItem>  $schema
     * @param  array<string, list<D1Row>>  $data
     */
    private function includeReferencedParents(array $schema, array &$data): bool
    {
        $expanded = false;
        $queries = [];
        $relations = [];
        foreach ($schema as $item) {
            if ($item['type'] !== 'table') {
                continue;
            }
            preg_match_all('/foreign key\("([a-z_]+)"\) references "?([a-z_]+)"?\("([a-z_]+)"\)/i', $item['sql'], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $relations[] = ['child' => $item['name'], 'column' => $match[1], 'parent' => $match[2], 'key' => $match[3]];
            }
        }
        for ($depth = 0; $depth < 16; $depth++) {
            $queries = [];
            $parents = [];
            foreach ($relations as $relation) {
                $present = array_column($data[$relation['parent']] ?? [], $relation['key']);
                $needed = array_values(array_unique(array_filter(array_column($data[$relation['child']] ?? [], $relation['column']), fn (mixed $value): bool => $value !== null && ! in_array($value, $present, true))));
                foreach (array_chunk($needed, 100) as $chunk) {
                    $queries[] = ['sql' => 'select * from '.self::identifier($relation['parent']).' where '.self::identifier($relation['key']).' in ('.implode(',', array_fill(0, count($chunk), '?')).')', 'params' => $chunk];
                    $parents[] = $relation['parent'];
                }
            }
            if ($queries === []) {
                return $expanded;
            }
            $expanded = true;
            $results = self::remote()->batch($queries);
            $added = 0;
            foreach ($results as $index => $result) {
                foreach ($result['results'] as $row) {
                    if (! in_array($row, $data[$parents[$index]], true)) {
                        $data[$parents[$index]][] = $row;
                        $added++;
                    }
                }
            }
            if ($added === 0) {
                throw new RuntimeException('Staging snapshot contains an unresolved foreign key.');
            }
        }
        throw new RuntimeException('Staging snapshot parent closure exceeded its budget.');
    }

    /** @return list<SchemaItem> */
    private static function schema(): array
    {
        return self::schemaRows(self::remote()->batch([self::schemaQuery()])[0]['results']);
    }

    /** @return array{sql: string, params: list<scalar|null>} */
    private static function schemaQuery(): array
    {
        return ['sql' => "select type, name, sql from sqlite_master where type in ('table', 'index') and sql is not null and name not glob 'sqlite_*' and name not glob 'd1_*' and name not glob '_cf_*' order by case type when 'table' then 0 else 1 end, name", 'params' => []];
    }

    /**
     * @param  list<D1Row>  $items
     * @return list<SchemaItem>
     */
    private static function schemaRows(array $items): array
    {
        $schema = [];
        foreach ($items as $item) {
            if (! isset($item['type'], $item['name'], $item['sql']) || ! is_string($item['type']) || ! is_string($item['name']) || ! is_string($item['sql'])) {
                throw new RuntimeException('Invalid D1 schema response.');
            }
            $schema[] = ['type' => $item['type'], 'name' => $item['name'], 'sql' => $item['sql']];
        }

        return $schema;
    }

    /**
     * @param  list<SchemaItem>  $schema
     * @param  array<string, list<D1Row>>  $tables
     * @param  list<D1Row>  $sequences
     */
    private function importSnapshot(PDO $pdo, array $schema, array $tables, array $sequences): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        try {
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
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function readCallback(Closure $callback, PDO $pdo): mixed
    {
        $pdo->exec('PRAGMA query_only = ON');
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('d1-staging-snapshot');
        config([D1RuntimeProfile::key('planning') => true]);
        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($default);
            config([D1RuntimeProfile::key('planning') => false]);
            DB::purge('d1-staging-snapshot');
            $this->restoreProductionAlias();
        }
    }

    private function restoreProductionAlias(): void
    {
        if (D1RuntimeProfile::production()) {
            config(['database.connections.d1-staging-snapshot' => ['driver' => 'd1']]);
        }
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
        if (! is_array($row) || $row === []) {
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

    private static function identifier(string $name): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            throw new LogicException('Invalid proof schema identifier.');
        }

        return '"'.$name.'"';
    }

    private static function remote(): D1StagingConnection
    {
        $connection = DB::connection(D1RuntimeProfile::connection());
        D1RuntimeProfile::assertEnabled();
        if (! $connection instanceof D1StagingConnection) {
            throw new LogicException('D1 financial commands are restricted to the isolated proof.');
        }

        return $connection;
    }
}
