<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class D1PostgresRollback
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @param  array<string, int>  $highWater
     * @return array<string, mixed>
     */
    public static function reconcile(PDO $postgres, array $tables, array $highWater = []): array
    {
        if ($postgres->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('Rollback rehearsal requires PostgreSQL.');
        }
        $identity = self::rows($postgres, 'select current_database() name,inet_server_addr() address')[0];
        if (! is_string($identity['name']) || (! is_string($identity['address']) && $identity['address'] !== null)) {
            throw new RuntimeException('Invalid PostgreSQL target identity.');
        }
        if (! preg_match('/^familyfunds_d1_rollback_[0-9_]+$/D', $identity['name']) || ! in_array($identity['address'], [null, '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Rollback rehearsal refuses a production or remote database.');
        }
        $names = array_map(fn (array $row): string => self::string($row['tablename']), self::rows($postgres, "select tablename from pg_tables where schemaname='public' order by tablename"));
        $provided = array_keys($tables);
        sort($provided);
        if ($provided !== $names || count($names) < 40) {
            throw new RuntimeException('Rollback requires the complete application table set.');
        }
        foreach ($names as $name) {
            self::quote($name);
        }
        $constraints = array_map(fn (array $row): array => ['conname' => self::string($row['conname']), 'convalidated' => self::boolean($row['convalidated']), 'table_name' => self::string($row['table_name']), 'definition' => self::string($row['definition'])], self::rows($postgres, "select c.conname,c.convalidated,t.relname table_name,pg_get_constraintdef(c.oid) definition from pg_constraint c join pg_class t on t.oid=c.conrelid join pg_namespace n on n.oid=t.relnamespace where c.contype='f' and n.nspname='public' order by t.relname,c.conname"));
        $sequences = [];
        $report = ['database' => $identity['name'], 'tables' => [], 'foreign_keys_validated' => 0, 'sequences' => []];
        $postgres->beginTransaction();
        try {
            $postgres->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $postgres->exec('LOCK TABLE '.implode(',', array_map(self::quote(...), $names)).' IN ACCESS EXCLUSIVE MODE');
            foreach ($constraints as $constraint) {
                if (! $constraint['convalidated'] || ! in_array($constraint['table_name'], $names, true)) {
                    throw new RuntimeException('Unexpected or unvalidated PostgreSQL constraint.');
                }
            }
            $prepared = [];
            foreach ($names as $name) {
                $statement = self::statement($postgres, "select column_name,data_type from information_schema.columns where table_schema='public' and table_name=? order by ordinal_position");
                $statement->execute([$name]);
                $columns = [];
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    if (! is_array($column)) {
                        throw new RuntimeException('Invalid PostgreSQL column metadata.');
                    }
                    $columns[self::string($column['column_name'])] = self::string($column['data_type']);
                }
                $rows = self::prepareRows($name, $tables[$name], $columns);
                $existing = self::rows($postgres, 'select * from '.self::quote($name));
                $report['tables'][$name] = ['before_rows' => count($existing), 'after_rows' => count($rows),
                    'before_sha256' => self::hashRows($existing, $columns), 'source_sha256' => self::hashRows($rows, $columns)];
                $prepared[$name] = ['columns' => $columns, 'rows' => $rows];
                if (array_key_exists('id', $columns)) {
                    $sequence = self::statement($postgres, "select pg_get_serial_sequence(?, 'id')");
                    $sequence->execute(['public.'.$name]);
                    $sequenceName = $sequence->fetchColumn();
                    if (is_string($sequenceName)) {
                        if (! preg_match('/^public\.[a-z_][a-z0-9_]*$/D', $sequenceName)) {
                            throw new RuntimeException('Unexpected PostgreSQL sequence.');
                        }
                        $state = self::rows($postgres, 'select last_value,is_called from '.$sequenceName)[0];
                        $state = ['last_value' => self::integer($state['last_value']), 'is_called' => self::boolean($state['is_called'])];
                        $sequences[$name] = ['name' => $sequenceName, ...$state];
                    }
                }
            }
            foreach ($constraints as $constraint) {
                $postgres->exec('alter table '.self::quote($constraint['table_name']).' drop constraint '.self::quote($constraint['conname']));
            }
            foreach ($names as $name) {
                $postgres->exec('delete from '.self::quote($name));
            }
            foreach ($prepared as $name => $data) {
                $columns = array_keys($data['columns']);
                if ($data['rows'] === []) {
                    continue;
                }
                $sql = 'insert into '.self::quote($name).'('.implode(',', array_map(self::quote(...), $columns)).') values ('.implode(',', array_fill(0, count($columns), '?')).')';
                $insert = self::statement($postgres, $sql);
                foreach ($data['rows'] as $row) {
                    foreach ($columns as $index => $column) {
                        $value = $row[$column];
                        $type = $value === null ? PDO::PARAM_NULL : (is_bool($value) ? PDO::PARAM_BOOL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
                        $insert->bindValue($index + 1, $value, $type);
                    }
                    $insert->execute();
                }
            }
            foreach ($constraints as $constraint) {
                $table = self::quote($constraint['table_name']);
                $key = self::quote($constraint['conname']);
                $postgres->exec('alter table '.$table.' add constraint '.$key.' '.$constraint['definition'].' NOT VALID');
                $postgres->exec('alter table '.$table.' validate constraint '.$key);
                $report['foreign_keys_validated']++;
            }
            foreach ($prepared as $name => $data) {
                $actual = self::rows($postgres, 'select * from '.self::quote($name));
                $hash = self::hashRows($actual, $data['columns']);
                if ($hash !== $report['tables'][$name]['source_sha256']) {
                    throw new RuntimeException('PostgreSQL rollback table differs: '.$name);
                }
                $report['tables'][$name]['restored_sha256'] = $hash;
            }
            foreach ($sequences as $name => $sequence) {
                $maximum = self::integer(self::rows($postgres, 'select coalesce(max(id),0) maximum from '.self::quote($name))[0]['maximum']);
                $reserved = $highWater[$name] ?? 1;
                if ($reserved < 1) {
                    throw new RuntimeException('Invalid D1 identifier high-water mark.');
                }
                $last = max(1, $maximum, $reserved - 1, (int) $sequence['last_value']);
                $called = $maximum > 0 || $reserved > 1 || $sequence['is_called'];
                $set = self::statement($postgres, 'select setval(?::regclass,?,?)');
                $set->bindValue(1, $sequence['name']);
                $set->bindValue(2, $last, PDO::PARAM_INT);
                $set->bindValue(3, $called, PDO::PARAM_BOOL);
                $set->execute();
                $report['sequences'][$name] = ['last_value' => $last, 'is_called' => $called, 'maximum_row_id' => $maximum, 'd1_next_reserved' => $reserved];
            }
            $postgres->commit();
            $report['status'] = 'passed';

            return $report;
        } catch (Throwable $exception) {
            if ($postgres->inTransaction()) {
                $postgres->rollBack();
            }
            foreach ($sequences as $sequence) {
                $restore = self::statement($postgres, 'select setval(?::regclass,?,?)');
                $restore->bindValue(1, $sequence['name']);
                $restore->bindValue(2, $sequence['last_value'], PDO::PARAM_INT);
                $restore->bindValue(3, $sequence['is_called'], PDO::PARAM_BOOL);
                $restore->execute();
            }
            throw $exception;
        }
    }

    private static function statement(PDO $postgres, string $sql): PDOStatement
    {
        $statement = $postgres->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare rollback statement.');
        }

        return $statement;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDO $postgres, string $sql): array
    {
        $statement = self::statement($postgres, $sql);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Unexpected PostgreSQL rollback row.');
            }
            $validated = [];
            foreach ($row as $key => $value) {
                $validated[self::string($key)] = $value;
            }
            $rows[] = $validated;
        }

        return $rows;
    }

    private static function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('Expected a PostgreSQL string.');
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (! is_int($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed === false) {
                throw new RuntimeException('Expected a PostgreSQL integer.');
            }

            return $parsed;
        }

        return $value;
    }

    private static function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new RuntimeException('Expected a PostgreSQL boolean.');
        }

        return $value;
    }

    private static function quote(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $identifier)) {
            throw new RuntimeException('Invalid rollback identifier.');
        }

        return '"'.$identifier.'"';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $columns
     * @return list<array<string, mixed>>
     */
    private static function prepareRows(string $table, array $rows, array $columns): array
    {
        $result = [];
        foreach ($rows as $row) {
            if ($table === 'jobs') {
                if (($row['reserved_at'] ?? null) !== null) {
                    throw new RuntimeException('Freeze and release all queue claims before rollback.');
                }
                unset($row['lease_token']);
            }
            if (array_diff(array_keys($row), array_keys($columns)) !== [] || array_diff(array_keys($columns), array_keys($row)) !== []) {
                throw new RuntimeException('Rollback column mismatch: '.$table);
            }
            foreach ($columns as $name => $type) {
                $value = $row[$name];
                if ($value === null) {
                    continue;
                }
                if (in_array($type, ['json', 'jsonb'], true)) {
                    $row[$name] = json_encode(is_string($value) ? json_decode($value, flags: JSON_THROW_ON_ERROR) : $value, JSON_THROW_ON_ERROR);
                } elseif ($type === 'boolean') {
                    if (! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                        throw new RuntimeException('Invalid rollback boolean.');
                    }
                    $row[$name] = $value === true || $value === 1 || $value === '1';
                } elseif ($type === 'bytea') {
                    throw new RuntimeException('Binary rollback columns need an explicit mapping.');
                }
            }
            $result[] = $row;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, string>  $columns
     */
    private static function hashRows(array $rows, array $columns): string
    {
        $normalized = [];
        foreach ($rows as $row) {
            foreach ($columns as $column => $type) {
                if ($row[$column] === null) {
                    continue;
                }
                if (in_array($type, ['json', 'jsonb'], true)) {
                    $value = is_string($row[$column]) ? json_decode($row[$column], flags: JSON_THROW_ON_ERROR) : $row[$column];
                    $row[$column] = self::ordered($value);
                } elseif ($type === 'boolean') {
                    $row[$column] = in_array($row[$column], [true, 1, '1'], true);
                } elseif (str_contains($type, 'timestamp')) {
                    $row[$column] = (new DateTimeImmutable(self::string($row[$column]), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                } elseif ($type === 'date') {
                    $row[$column] = (new DateTimeImmutable(self::string($row[$column])))->format('Y-m-d');
                } elseif (in_array($type, ['integer', 'bigint', 'smallint'], true)) {
                    $row[$column] = self::integer($row[$column]);
                }
            }
            ksort($row);
            $normalized[] = json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        sort($normalized);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private static function ordered(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map(self::ordered(...), $properties);
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::ordered(...), $value);
        }

        return $value;
    }
}
