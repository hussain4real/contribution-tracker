<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

final class D1StagingCipherMigration
{
    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: list<array<string, scalar|null>>, verified_fields: int, cleared_fields: int}
     */
    public static function isolate(array $rows, Encrypter $source): array
    {
        $result = [];
        $verified = 0;
        $cleared = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid source account row.');
            }
            $account = [];
            foreach ($row as $column => $value) {
                if (! is_string($column) || (! is_scalar($value) && $value !== null)) {
                    throw new RuntimeException('Invalid source account field.');
                }
                $account[$column] = $value;
            }
            foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
                if (! array_key_exists($column, $account)) {
                    throw new RuntimeException('Missing source MFA column.');
                }
                $value = $account[$column];
                if ($value !== null) {
                    if ($column !== 'two_factor_confirmed_at') {
                        if (! is_string($value) || ! is_string($source->decrypt($value, false))) {
                            throw new RuntimeException('Invalid encrypted source account field.');
                        }
                        $verified++;
                    }
                    $cleared++;
                }
                $account[$column] = null;
            }
            $result[] = $account;
        }

        return ['rows' => $result, 'verified_fields' => $verified, 'cleared_fields' => $cleared];
    }

    /**
     * @param  list<array{id: int, two_factor_secret: ?string, two_factor_recovery_codes: ?string}>  $rows
     * @return list<array{id: int, two_factor_secret: ?string, two_factor_recovery_codes: ?string}>
     */
    public static function reencrypt(array $rows, Encrypter $source, Encrypter $target): array
    {
        $result = [];
        foreach ($rows as $row) {
            foreach (['two_factor_secret', 'two_factor_recovery_codes'] as $column) {
                $value = $row[$column];
                if ($value === null) {
                    continue;
                }
                $plaintext = $source->decrypt($value, false);
                if (! is_string($plaintext)) {
                    throw new RuntimeException('Encrypted account payload is not a string.');
                }
                $encrypted = $target->encrypt($plaintext, false);
                $verified = $target->decrypt($encrypted, false);
                if (! is_string($verified) || ! hash_equals($plaintext, $verified)) {
                    throw new RuntimeException('Encrypted account migration failed its round-trip check.');
                }
                $row[$column] = $encrypted;
            }
            $result[] = $row;
        }

        return $result;
    }
}
