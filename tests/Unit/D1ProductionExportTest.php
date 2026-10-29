<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1PostgresSnapshot;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
});

it('preserves production enrollment, confirmation timestamps, passwords and account fields', function (): void {
    $source = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $target = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $rows = [['id' => 1, 'email' => 'fixture@example.invalid', 'password' => 'fixture-hash', 'two_factor_secret' => $source->encrypt('fixture-totp'), 'two_factor_recovery_codes' => $source->encrypt('["fixture-recovery"]'), 'two_factor_confirmed_at' => '2026-10-26 12:00:00']];
    $result = D1PostgresSnapshot::preserveAccounts($rows, $source, $target);
    $row = $result['rows'][0];
    expect($result['verified_fields'])->toBe(2)
        ->and($row['two_factor_confirmed_at'])->toBe($rows[0]['two_factor_confirmed_at'])
        ->and($row['password'])->toBe($rows[0]['password'])
        ->and($row['email'])->toBe($rows[0]['email']);
    assert(is_string($row['two_factor_secret']) && is_string($row['two_factor_recovery_codes']));
    expect($target->decrypt($row['two_factor_secret']))->toBe('fixture-totp')
        ->and($target->decrypt($row['two_factor_recovery_codes']))->toBe('["fixture-recovery"]')
        ->and(json_encode($result))->not->toContain('fixture-totp');
    expect(fn () => $source->decrypt($row['two_factor_secret']))->toThrow(DecryptException::class);
});

it('retains users without two-factor enrollment and refuses partially corrupt exports', function (): void {
    $source = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $target = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $row = ['id' => 1, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null];
    expect(D1PostgresSnapshot::preserveAccounts([$row], $source, $target)['rows'])->toBe([$row]);
    $corrupt = [...$row, 'two_factor_secret' => 'corrupt-fixture'];
    expect(fn () => D1PostgresSnapshot::preserveAccounts([$row, $corrupt], $source, $target))->toThrow(DecryptException::class);
});

it('preserves the exact production ciphertext when retaining the current application key', function (): void {
    $source = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $rows = [['id' => 1, 'two_factor_secret' => $source->encrypt('independent-fixture-totp'), 'two_factor_recovery_codes' => $source->encrypt('["fixture-recovery"]'), 'two_factor_confirmed_at' => '2026-10-27 00:00:00']];
    $result = D1PostgresSnapshot::preserveAccounts($rows, $source, $source);
    expect($result['rows'])->toBe($rows)->and($result['verified_fields'])->toBe(2)->and($result['cleared_fields'])->toBe(0);
});

it('preserves OAuth clients, authorization codes, tokens, reset tokens and durable queued jobs only for production', function (): void {
    foreach (['oauth_clients', 'oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_device_codes', 'personal_access_tokens', 'password_reset_tokens', 'jobs', 'failed_jobs', 'job_batches'] as $table) {
        expect(D1PostgresSnapshot::excludedTables(true))->not->toContain($table)
            ->and(D1PostgresSnapshot::excludedTables(false))->toContain($table);
    }
    expect(D1PostgresSnapshot::excludedTables(true))->toBe(['sessions', 'cache', 'cache_locks']);
});

it('refuses a non-PostgreSQL source before starting a transaction', function (): void {
    $source = new Encrypter(random_bytes(32), 'AES-256-CBC');
    expect(fn () => D1PostgresSnapshot::capture(new PDO('sqlite::memory:'), $source, $source))->toThrow(RuntimeException::class, 'PostgreSQL');
});
