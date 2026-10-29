<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Encryption\Encrypter;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class D1PostgresSnapshot
{
    /** @return list<string> */
    public static function excludedTables(bool $production): array
    {
        $transient = ['sessions', 'cache', 'cache_locks'];

        return $production ? $transient : [...$transient, 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'oauth_access_tokens', 'oauth_auth_codes', 'oauth_device_codes', 'oauth_refresh_tokens', 'oauth_clients', 'personal_access_tokens'];
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows
     * @return array{rows: list<array<string, scalar|null>>, verified_fields: int, cleared_fields: int}
     */
    public static function preserveAccounts(array $rows, Encrypter $source, Encrypter $target): array
    {
        $result = [];
        $verified = 0;
        foreach ($rows as $row) {
            foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
                if (! array_key_exists($column, $row)) {
                    throw new RuntimeException('Production export requires the complete MFA column set.');
                }
            }
            foreach (['two_factor_secret', 'two_factor_recovery_codes'] as $column) {
                $ciphertext = $row[$column];
                if ($ciphertext === null) {
                    continue;
                }
                if (! is_string($ciphertext)) {
                    throw new RuntimeException('Invalid production MFA ciphertext.');
                }
                $plaintext = $source->decrypt($ciphertext, false);
                if (! is_string($plaintext)) {
                    throw new RuntimeException('Invalid production MFA payload.');
                }
                $targetCiphertext = hash_equals($source->getKey(), $target->getKey()) ? $ciphertext : $target->encrypt($plaintext, false);
                $row[$column] = $targetCiphertext;
                $verifiedPlaintext = $target->decrypt($targetCiphertext, false);
                if (! is_string($verifiedPlaintext) || ! hash_equals($plaintext, $verifiedPlaintext)) {
                    throw new RuntimeException('Production MFA did not round trip.');
                }
                $verified++;
            }
            $result[] = $row;
        }

        return ['rows' => $result, 'verified_fields' => $verified, 'cleared_fields' => 0];
    }

    /** @return array<string, mixed> */
    public static function capture(PDO $pdo, Encrypter $source, ?Encrypter $productionTarget = null): array
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('The migration source must be PostgreSQL.');
        }
        $production = $productionTarget !== null;
        $excluded = self::excludedTables($production);
        $snapshot = ['source' => 'PostgreSQL repeatable-read read-only snapshot', 'created_at' => gmdate(DATE_ATOM), 'purpose' => $production ? 'production-preserving' : 'independent-staging', 'excluded' => [], 'tables' => []];
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $tables = self::query($pdo, "select tablename from pg_tables where schemaname='public' order by tablename")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                if (! is_string($table) || ! preg_match('/^[a-z_][a-z0-9_]*$/D', $table)) {
                    throw new RuntimeException('Invalid source table name.');
                }
                if (in_array($table, $excluded, true)) {
                    $snapshot['excluded'][$table] = (int) self::query($pdo, 'select count(*) from "'.$table.'"')->fetchColumn();

                    continue;
                }
                $statement = $pdo->prepare("select column_name,data_type from information_schema.columns where table_schema='public' and table_name=? order by ordinal_position");
                $statement->execute([$table]);
                $columns = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
                if (in_array('bytea', $columns, true)) {
                    throw new RuntimeException('Binary source columns require an explicit conversion.');
                }
                $rows = self::query($pdo, 'select * from "'.$table.'"')->fetchAll(PDO::FETCH_ASSOC);
                if ($table === 'users') {
                    $accounts = self::accountRows($rows);
                    $converted = $productionTarget !== null
                        ? self::preserveAccounts($accounts, $source, $productionTarget)
                        : D1StagingCipherMigration::isolate($accounts, $source);
                    $rows = $converted['rows'];
                    $snapshot[$production ? 'preserved_production_mfa' : 'independent_staging_mfa'] = [
                        'verified_encrypted_fields' => $converted['verified_fields'],
                        'cleared_fields' => $converted['cleared_fields'],
                        'confirmation_timestamps_preserved' => $production,
                        'production_key_exported' => false,
                    ];
                }
                $sequence = null;
                if (array_key_exists('id', $columns)) {
                    $statement = $pdo->prepare('select pg_get_serial_sequence(?,?)');
                    $statement->execute(['public.'.$table, 'id']);
                    $name = $statement->fetchColumn();
                    if (is_string($name)) {
                        if (! preg_match('/^public\.[a-z_][a-z0-9_]*$/D', $name)) {
                            throw new RuntimeException('Invalid source sequence name.');
                        }
                        $sequence = self::query($pdo, 'select last_value,is_called from '.$name)->fetch(PDO::FETCH_ASSOC);
                    }
                }
                $snapshot['tables'][$table] = ['columns' => $columns, 'rows' => $rows, 'sequence' => $sequence];
            }
            $pdo->commit();

            return $snapshot;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('PostgreSQL snapshot query failed.');
        }

        return $statement;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array<string, scalar|null>>
     */
    private static function accountRows(array $rows): array
    {
        $validated = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid source account row.');
            }
            $values = [];
            foreach ($row as $key => $value) {
                if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                    throw new RuntimeException('Invalid source account column.');
                }
                $values[$key] = $value;
            }
            $validated[] = $values;
        }

        return $validated;
    }
}
