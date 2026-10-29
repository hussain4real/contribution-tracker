<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * @phpstan-import-type D1Row from D1StagingConnection
 *
 * @phpstan-type Entry array{row_data: string, key_data: string, table_name: string, operation: string}
 * @phpstan-type Change array{table: string, operation: string, row: D1Row, keys: D1Row, groupable: bool}
 * @phpstan-type Statement array{sql: string, params: list<scalar|null>}
 */
final class D1RuntimeJournal
{
    /**
     * @param  list<Entry>  $journal
     * @param  array<string, list<D1Row>>  $tables
     * @return list<Statement>
     */
    public static function plan(array $journal, array $tables, PDO $snapshot): array
    {
        $guards = [];
        $guarded = [];
        $changes = [];
        $unique = [];
        $indexes = [];
        foreach ($journal as $entry) {
            $table = $entry['table_name'];
            self::identifier($table);
            $row = self::row($entry['row_data']);
            $keys = self::row($entry['key_data']);
            $identity = $table.':'.json_encode($keys, JSON_THROW_ON_ERROR);
            $index = $table.':'.implode(',', array_keys($keys));
            if (! isset($indexes[$index])) {
                $indexes[$index] = [];
                foreach ($tables[$table] ?? [] as $candidate) {
                    $indexValues = [];
                    foreach (array_keys($keys) as $column) {
                        $indexValues[$column] = $candidate[$column] ?? null;
                    }
                    $indexes[$index][json_encode($indexValues, JSON_THROW_ON_ERROR)] = $candidate;
                }
            }
            $original = $indexes[$index][json_encode($keys, JSON_THROW_ON_ERROR)] ?? null;
            if ($entry['operation'] !== 'insert' && ! isset($guarded[$identity]) && $original !== null) {
                $guards[$table][] = $original;
                $guarded[$identity] = true;
            }
            $unique[$table] ??= self::uniqueColumns($snapshot, $table);
            $stable = $original !== null
                && array_intersect_key($row, $keys) === $keys
                && array_all($unique[$table], fn (string $column): bool => ($row[$column] ?? null) === ($original[$column] ?? null));
            $changes[] = ['table' => $table, 'operation' => $entry['operation'], 'row' => $row, 'keys' => $keys, 'groupable' => $entry['operation'] !== 'update' || $stable];
        }
        $queries = [];
        foreach ($guards as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                $checks = implode(' and ', array_map(fn (string $column): string => 't.'.self::identifier($column).' is '.self::extract('j.value', $column), array_keys($chunk[0])));
                $queries[] = ['sql' => 'insert into d1_staging_assertions values (?,not exists(select 1 from json_each(?) j where not exists(select 1 from '.self::identifier($table).' t where '.$checks.')))', 'params' => [(string) Str::uuid(), json_encode($chunk, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]];
            }
        }
        $group = [];
        $identities = [];
        foreach ($changes as $change) {
            $identity = json_encode($change['keys'], JSON_THROW_ON_ERROR);
            $previous = $group[0] ?? null;
            $compatible = $previous !== null && $previous['groupable'] && $change['groupable']
                && $previous['table'] === $change['table'] && $previous['operation'] === $change['operation']
                && array_keys($previous['row']) === array_keys($change['row']) && array_keys($previous['keys']) === array_keys($change['keys'])
                && ! isset($identities[$identity]) && count($group) < 100
                && strlen(json_encode([...$group, $change], JSON_THROW_ON_ERROR)) < 60_000;
            if ($group !== [] && ! $compatible) {
                $queries[] = self::statement($group);
                $group = [];
                $identities = [];
            }
            $group[] = $change;
            $identities[$identity] = true;
        }
        if ($group !== []) {
            $queries[] = self::statement($group);
        }

        return $queries;
    }

    /**
     * @param  non-empty-list<Change>  $group
     * @return Statement
     */
    private static function statement(array $group): array
    {
        $first = $group[0];
        $table = self::identifier($first['table']);
        $columns = array_keys($first['row']);
        if ($first['operation'] === 'insert') {
            return ['sql' => 'insert into '.$table.' ('.implode(',', array_map(self::identifier(...), $columns)).') select '.implode(',', array_map(fn (string $column): string => self::extract('j.value', $column), $columns)).' from json_each(?) j', 'params' => [json_encode(array_column($group, 'row'), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]];
        }
        $where = implode(' and ', array_map(fn (string $column): string => 't.'.self::identifier($column).' is '.self::extract('j.value', $column, $first['operation'] === 'update' ? 'keys.' : ''), array_keys($first['keys'])));
        if ($first['operation'] === 'delete') {
            return ['sql' => 'delete from '.$table.' as t where exists(select 1 from json_each(?) j where '.$where.')', 'params' => [json_encode(array_column($group, 'keys'), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]];
        }
        if ($first['operation'] !== 'update') {
            throw new RuntimeException('Unknown journal operation.');
        }
        $assignments = implode(',', array_map(fn (string $column): string => self::identifier($column).'=(select '.self::extract('j.value', $column, 'row.').' from patch j where '.$where.')', $columns));

        return ['sql' => 'with patch as (select value from json_each(?)) update '.$table.' as t set '.$assignments.' where exists(select 1 from patch j where '.$where.')', 'params' => [json_encode(array_map(fn (array $change): array => ['row' => $change['row'], 'keys' => $change['keys']], $group), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]];
    }

    /** @return list<string> */
    private static function uniqueColumns(PDO $snapshot, string $table): array
    {
        $indexes = $snapshot->query('pragma index_list('.self::identifier($table).')');
        if ($indexes === false) {
            throw new RuntimeException('Could not inspect journal indexes.');
        }
        $columns = [];
        foreach ($indexes->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if (! is_array($index) || ($index['unique'] ?? null) !== 1) {
                continue;
            }
            $name = $index['name'];
            if (! is_string($name)) {
                throw new RuntimeException('Invalid journal index.');
            }
            $result = $snapshot->query('pragma index_info('.self::identifier($name).')');
            if ($result === false) {
                throw new RuntimeException('Could not inspect journal index columns.');
            }
            foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if (! is_array($column) || ! is_string($column['name'] ?? null)) {
                    return array_keys(self::rowSchema($snapshot, $table));
                }
                $columns[] = $column['name'];
            }
        }

        return array_values(array_unique($columns));
    }

    /** @return array<string, null> */
    private static function rowSchema(PDO $snapshot, string $table): array
    {
        $result = $snapshot->query('pragma table_info('.self::identifier($table).')');
        if ($result === false) {
            throw new RuntimeException('Could not inspect journal table.');
        }
        $columns = [];
        foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (! is_array($column) || ! is_string($column['name'] ?? null)) {
                throw new RuntimeException('Invalid journal column.');
            }
            $columns[$column['name']] = null;
        }

        return $columns;
    }

    private static function extract(string $expression, string $column, string $prefix = ''): string
    {
        self::identifier($column);

        return 'json_extract('.$expression.',\'$.'.$prefix.'"'.$column.'"\')';
    }

    /** @return D1Row */
    private static function row(string $json): array
    {
        $row = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($row) || $row === []) {
            throw new RuntimeException('Invalid journal row.');
        }
        $result = [];
        foreach ($row as $column => $value) {
            if (! is_string($column) || (! is_int($value) && ! is_float($value) && ! is_string($value) && $value !== null)) {
                throw new RuntimeException('Invalid journal column value.');
            }
            self::identifier($column);
            $result[$column] = $value;
        }

        return $result;
    }

    private static function identifier(string $value): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) {
            throw new RuntimeException('Invalid journal identifier.');
        }

        return '"'.$value.'"';
    }
}
