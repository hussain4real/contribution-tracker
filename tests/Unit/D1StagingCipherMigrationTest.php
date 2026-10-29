<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1StagingCipherMigration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
});

it('preserves Fortify encrypted values while replacing the encryption key', function (): void {
    $source = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $target = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $rows = [['id' => 42, 'two_factor_secret' => $source->encrypt('synthetic-totp-secret'), 'two_factor_recovery_codes' => $source->encrypt('["synthetic-code"]')], ['id' => 43, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null]];
    $result = D1StagingCipherMigration::reencrypt($rows, $source, $target);
    assert(is_string($result[0]['two_factor_secret']));
    assert(is_string($result[0]['two_factor_recovery_codes']));
    expect($target->decrypt($result[0]['two_factor_secret']))->toBe('synthetic-totp-secret')
        ->and($target->decrypt($result[0]['two_factor_recovery_codes']))->toBe('["synthetic-code"]')
        ->and($result[1])->toBe($rows[1])
        ->and($result[0]['two_factor_secret'])->not->toBe($rows[0]['two_factor_secret']);
    expect(fn () => $source->decrypt($result[0]['two_factor_secret']))->toThrow(DecryptException::class);
});

it('refuses undecryptable source data without returning a partial migration', function (): void {
    $source = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $target = new Encrypter(random_bytes(32), 'AES-256-CBC');
    expect(fn () => D1StagingCipherMigration::reencrypt([['id' => 42, 'two_factor_secret' => 'corrupt-data', 'two_factor_recovery_codes' => null]], $source, $target))
        ->toThrow(DecryptException::class);
});

it('verifies MFA on the source and exports independent enrollment without changing other account fields', function (): void {
    $source = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $rows = [
        ['id' => 42, 'email' => 'source@example.invalid', 'password' => 'synthetic-hash', 'two_factor_secret' => $source->encrypt('synthetic-totp-secret', false), 'two_factor_recovery_codes' => $source->encrypt('["synthetic-code"]', false), 'two_factor_confirmed_at' => '2026-10-26 00:00:00'],
        ['id' => 43, 'email' => 'other@example.invalid', 'password' => 'other-hash', 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null],
    ];
    $result = D1StagingCipherMigration::isolate($rows, $source);
    expect($result['verified_fields'])->toBe(2)
        ->and($result['cleared_fields'])->toBe(3)
        ->and($result['rows'][1])->toBe($rows[1]);
    foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
        expect($result['rows'][0][$column])->toBeNull();
        unset($rows[0][$column]);
    }
    expect(array_diff_key($result['rows'][0], array_flip(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])))->toBe($rows[0]);
});

it('refuses an undecryptable independent-MFA export instead of silently discarding corruption', function (): void {
    $source = new Encrypter(random_bytes(32), 'AES-256-CBC');
    expect(fn () => D1StagingCipherMigration::isolate([
        ['id' => 42, 'two_factor_secret' => 'corrupt-data', 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null],
    ], $source))->toThrow(DecryptException::class);
});
