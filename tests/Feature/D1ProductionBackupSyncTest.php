<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1ProductionBackupSync;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    $this->configuration = storage_path('framework/testing/rclone-'.Str::uuid().'.conf');
    File::put($this->configuration, '[synthetic fixture; no credential]');
    chmod($this->configuration, 0600);
    Storage::fake('production-backups');
    Storage::disk('production-backups')->put('d1/2026-10-26.json.aes-gcm', json_encode(['version' => 1, 'database' => 'familyfunds-production', 'kind' => 'database', 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 32)), 'sha256' => str_repeat('a', 64)], JSON_THROW_ON_ERROR));
    $this->copier = new D1ProductionBackupSync(Storage::disk('production-backups')->getDriver(), fn (array $command): bool => Process::timeout(65)->run($command)->successful());
});

afterEach(function (): void {
    File::delete($this->configuration);
});

it('copies encrypted production snapshots without remote deletion and verifies checksums', function (): void {
    Process::fake();
    $result = $this->copier->run($this->configuration);
    expect($result)->toMatchArray(['files' => 1, 'encrypted_only' => true, 'checksum_verified' => true]);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[1] === 'copy' && $process->command[3] === 'familyfund_drive:FamilyFund/cloudflare-production/backups/live' && in_array('--immutable', $process->command, true));
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[1] === 'check' && in_array('--one-way', $process->command, true));
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[1] === 'copy'
        && in_array('--contimeout', $process->command, true) && in_array('--timeout', $process->command, true)
        && in_array('--fast-list', $process->command, true) && in_array('--tpslimit', $process->command, true) && in_array('--drive-pacer-burst', $process->command, true)
        && in_array('--low-level-retries', $process->command, true) && in_array('--retries-sleep', $process->command, true));
});

it('refuses the existing PostgreSQL backup folder and empty backup sets', function (): void {
    Process::fake();
    expect(fn () => $this->copier->run($this->configuration, 'familyfund_drive:FamilyFund/production/backups'))->toThrow(RuntimeException::class, 'separate Cloudflare');
    Process::assertNothingRan();
    Storage::disk('production-backups')->delete('d1/2026-10-26.json.aes-gcm');
    expect(fn () => $this->copier->run($this->configuration))->toThrow(RuntimeException::class, 'without a production');
});

it('fails the task when its offsite checksum verification fails', function (): void {
    Process::fake(['* copy *' => Process::result(), '* check *' => Process::result(exitCode: 1)]);
    expect(fn () => $this->copier->run($this->configuration))->toThrow(RuntimeException::class, 'checksum');
});

it('copies only the requested cutover checkpoint while preserving immutable daily history', function (): void {
    Process::fake();
    $disk = Storage::disk('production-backups');
    $contents = $disk->get('d1/2026-10-26.json.aes-gcm');
    if (! is_string($contents)) {
        throw new RuntimeException('Encrypted fixture snapshot is missing.');
    }
    $prefix = 'checkpoints/cutover-20261027t140000z/';
    $disk->put($prefix.'d1/2026-10-27.json.aes-gcm', $contents);
    $disk->put($prefix.'files/'.str_repeat('b', 64).'.json.aes-gcm', json_encode(['version' => 1, 'database' => 'familyfunds-production', 'kind' => 'file:'.str_repeat('b', 64), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 32)), 'sha256' => str_repeat('b', 64)], JSON_THROW_ON_ERROR));
    $older = 'checkpoints/cutover-20261026t140000z/';
    $disk->put($older.'d1/2026-10-26.json.aes-gcm', $contents);
    $result = $this->copier->run($this->configuration, checkpoint: 'cutover-20261027t140000z');
    expect($result)->toMatchArray(['checkpoint' => 'cutover-20261027t140000z', 'files' => 2, 'encrypted_only' => true, 'checksum_verified' => true]);
    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && $process->command[1] === 'copy' && in_array('--immutable', $process->command, true));
    $disk->assertExists($older.'d1/2026-10-26.json.aes-gcm');
    $disk->assertExists('d1/2026-10-26.json.aes-gcm');
});

it('keeps routine copies independent of older migration checkpoints', function (): void {
    Process::fake();
    $disk = Storage::disk('production-backups');
    $contents = $disk->get('d1/2026-10-26.json.aes-gcm');
    if (! is_string($contents)) {
        throw new RuntimeException('Encrypted fixture snapshot is missing.');
    }
    $disk->put('checkpoints/cutover-20261027t140000z/d1/2026-10-27.json.aes-gcm', $contents);
    expect($this->copier->run($this->configuration))->toMatchArray(['checkpoint' => null, 'files' => 1, 'checksum_verified' => true]);
});

it('refuses a missing or malformed checkpoint instead of copying another backup', function (?string $checkpoint): void {
    Process::fake();
    expect(fn () => $this->copier->run($this->configuration, checkpoint: $checkpoint))->toThrow(RuntimeException::class);
    Process::assertNothingRan();
})->with(['cutover-20261027t150000z', '../path', 'arbitrary', '0', '']);

it('refuses plaintext and unrecognized backup paths before offsite copying', function (string $path, string $contents): void {
    Process::fake();
    Storage::disk('production-backups')->put($path, $contents);
    expect(fn () => $this->copier->run($this->configuration))->toThrow(RuntimeException::class);
    Process::assertNothingRan();
})->with([
    'plaintext envelope' => ['d1/2026-10-26.json.aes-gcm', '{"plaintext":"financial records"}'],
    'unapproved path' => ['other/backup.json', '{}'],
    'checkpoint traversal' => ['checkpoints/../d1/2026-10-26.json.aes-gcm', '{}'],
    'unapproved checkpoint' => ['checkpoints/arbitrary/d1/2026-10-26.json.aes-gcm', '{}'],
]);
