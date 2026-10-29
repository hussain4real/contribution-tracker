<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Base64Url\Base64Url;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Laravel\Passport\Passport;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Utils;
use Minishlink\WebPush\VAPID;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;

final class D1ProductionMaintenance
{
    public function __construct(private readonly D1RuntimeClient $client, private readonly D1StagingRuntime $runtime, private readonly Kernel $kernel) {}

    /** @return array<string, mixed> */
    public static function decodeTask(string $json): array
    {
        if (strlen($json) > 8192) {
            throw new RuntimeException('Oversized production task.');
        }
        $task = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($task)) {
            throw new RuntimeException('Invalid production task fields.');
        }
        $fields = [];
        foreach ($task as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Invalid production task fields.');
            }
            $fields[$key] = $value;
        }

        return $fields;
    }

    public function install(): void
    {
        if (! D1RuntimeProfile::production()) {
            throw new RuntimeException('Production maintenance requires its separate runtime.');
        }
        D1StagingRuntime::install();
        D1StagingRuntime::installReadCache();
        D1RuntimeCacheStore::install($this->client);
        foreach ([
            'create table if not exists d1_runtime_webhook_inbox(id text primary key,object_key text not null,created_at integer not null,accepted_at integer)',
            'create table if not exists d1_runtime_schedule_outbox(id text primary key,kind text not null,family_id integer not null,due_at text not null,cursor integer not null default 0,dispatched_at integer,lease_token text,lease_until integer,completed_at integer)',
            'create table if not exists d1_runtime_health(name text primary key,status text not null,details text not null,checked_at integer not null)',
            'create table if not exists d1_runtime_dead_letters(id text primary key,queue text not null,body text not null,created_at integer not null,resolved_at integer)',
        ] as $sql) {
            $this->client->rows($sql);
        }
    }

    /** @return array<string, mixed> */
    public function inspectMigration(): array
    {
        if (! config('d1-production.migration_checks') || config('d1-production.queue_worker_enabled') || config('d1-production.delivery_enabled')) {
            throw new RuntimeException('Read-only migration configuration required.');
        }
        $mfa = ['enrolled' => 0, 'confirmed' => 0, 'encrypted_fields_verified' => 0];
        foreach (DB::table('users')->whereNotNull('two_factor_secret')->get(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']) as $user) {
            if (! is_string($user->two_factor_secret) || ! is_string($user->two_factor_recovery_codes)) {
                throw new RuntimeException('Missing migrated MFA ciphertext.');
            }
            $secret = Crypt::decrypt($user->two_factor_secret);
            $recovery = Crypt::decrypt($user->two_factor_recovery_codes);
            if (! is_string($secret) || preg_match('/^[A-Z2-7]+=*$/D', $secret) !== 1 || ! is_string($recovery)
                || ! is_array(json_decode($recovery, true, flags: JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Migrated MFA fields are invalid.');
            }
            $mfa['enrolled']++;
            $mfa['confirmed'] += $user->two_factor_confirmed_at === null ? 0 : 1;
            $mfa['encrypted_fields_verified'] += 2;
        }
        $privateKey = config('passport.private_key') ?: file_get_contents(Passport::keyPath('oauth-private.key'));
        $publicKey = config('passport.public_key') ?: file_get_contents(Passport::keyPath('oauth-public.key'));
        if (! is_string($privateKey) || ! is_string($publicKey)) {
            throw new RuntimeException('Missing migrated Passport key pair.');
        }
        $challenge = random_bytes(32);
        $signature = '';
        if (! openssl_sign($challenge, $signature, $privateKey, OPENSSL_ALGO_SHA256)
            || ! is_string($signature) || openssl_verify($challenge, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Migrated Passport key pair did not verify.');
        }
        $vapid = VAPID::validate(['subject' => Config::string('webpush.vapid.subject'), 'publicKey' => Config::string('webpush.vapid.public_key'), 'privateKey' => Config::string('webpush.vapid.private_key')]);
        $headers = VAPID::getVapidHeaders('https://migration-check.invalid', $vapid['subject'], $vapid['publicKey'], $vapid['privateKey'], ContentEncoding::aes128gcm);
        if (! isset($headers['Authorization']) || ! is_string($headers['Authorization']) || preg_match('/^vapid t=([^,]+),/', $headers['Authorization'], $match) !== 1) {
            throw new RuntimeException('Migrated VAPID signing failed.');
        }
        [$x, $y] = Utils::unserializePublicKey($vapid['publicKey']);
        if (! is_string($x) || ! is_string($y)) {
            throw new RuntimeException('Invalid migrated VAPID public key coordinates.');
        }
        $jwk = new JWK(['kty' => 'EC', 'crv' => 'P-256', 'x' => Base64Url::encode($x), 'y' => Base64Url::encode($y)]);
        if (! (new JWSVerifier(new AlgorithmManager([new ES256])))->verifyWithKey((new CompactSerializer)->unserialize($match[1]), $jwk, 0)) {
            throw new RuntimeException('Migrated VAPID public and private keys do not match.');
        }
        $oauth = [];
        foreach (['oauth_clients', 'oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_device_codes', 'personal_access_tokens'] as $table) {
            $oauth[$table] = DB::table($table)->count();
        }
        $files = [];
        foreach (['report_artifacts', 'reconciliation_imports'] as $table) {
            foreach (DB::table($table)->get(['disk', 'path']) as $row) {
                if (! in_array($row->disk, ['local', 'public'], true) || ! is_string($row->path) || preg_match('#(^/|\.\.|\\\\)#', $row->path)) {
                    throw new RuntimeException('Unexpected migrated private file mapping.');
                }
                $contents = Storage::disk($row->disk)->get($row->path);
                if (! is_string($contents)) {
                    throw new RuntimeException('A migrated application file is missing.');
                }
                $files[] = ['path_sha256' => hash('sha256', $row->disk.':'.$row->path), 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
            }
        }
        $providers = ['checked' => false];
        if (config('d1-production.provider_read_only_checks')) {
            $banks = Http::withToken(Config::string('services.paystack.secret_key'))->connectTimeout(5)->timeout(20)->get('https://api.paystack.co/bank', ['currency' => 'NGN', 'perPage' => 100]);
            $phone = Http::withToken(Config::string('services.whatsapp.access_token'))->connectTimeout(5)->timeout(20)->get('https://graph.facebook.com/'.Config::string('services.whatsapp.api_version').'/'.Config::string('services.whatsapp.phone_number_id'), ['fields' => 'id']);
            $providers = ['checked' => true, 'paystack_http' => $banks->status(), 'paystack_status' => $banks->json('status') === true,
                'meta_http' => $phone->status(), 'meta_sender_matches' => $phone->json('id') === config('services.whatsapp.phone_number_id'), 'messages_or_charges_created' => false];
        }

        return ['exit_code' => 0, 'read_only' => true, 'mfa' => $mfa, 'passport_pair_valid' => true, 'vapid_pair_valid' => true, 'oauth_counts' => $oauth, 'providers' => $providers,
            'key_fingerprints' => ['app_key' => hash('sha256', Config::string('app.key')), 'passport_public_key' => hash('sha256', $publicKey), 'vapid_public_key' => hash('sha256', Config::string('webpush.vapid.public_key'))],
            'files' => $files, 'jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'foreign_key_violations' => count(DB::select('pragma foreign_key_check'))];
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{exit_code: int, complete: bool, cursor: int}
     */
    public function execute(array $task): array
    {
        foreach (['id', 'kind', 'at', 'lease_token'] as $key) {
            if (! isset($task[$key]) || ! is_string($task[$key]) || $task[$key] === '') {
                throw new RuntimeException('Missing production task identity.');
            }
        }
        $family = $task['family_id'] ?? null;
        $cursor = $task['cursor'] ?? null;
        if (! is_int($family) || $family < 0 || ! is_int($cursor) || $cursor < 0
            || ! in_array($task['kind'], ['generate', 'remind25', 'remind28', 'purge', 'reports', 'drain', 'backup-sync', 'monitor'], true)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $task['at'])) {
            throw new RuntimeException('Invalid production maintenance scope.');
        }
        $at = new DateTimeImmutable($task['at']);
        if ($at > new DateTimeImmutable('now')) {
            throw new RuntimeException('Production tasks cannot run in the future.');
        }
        $stored = $this->client->rows('select kind,family_id,due_at,cursor from d1_runtime_schedule_outbox where id=? and lease_token=? and lease_until>unixepoch() and completed_at is null', [$task['id'], $task['lease_token']])[0] ?? null;
        if ($stored === null || $stored['kind'] !== $task['kind'] || $stored['family_id'] !== $family || $stored['due_at'] !== $task['at'] || $stored['cursor'] !== $cursor) {
            throw new RuntimeException('Production task lease or stored scope changed.');
        }
        if ($task['kind'] === 'drain') {
            $exit = $this->kernel->call('queue:work', ['connection' => 'd1', '--stop-when-empty' => true, '--max-jobs' => 20, '--max-time' => 40, '--sleep' => 0, '--tries' => 3, '--timeout' => 60, '--force' => true], new NullOutput);

            return ['exit_code' => $exit, 'complete' => true, 'cursor' => 0];
        }
        if ($task['kind'] === 'backup-sync') {
            throw new RuntimeException('Offsite backup tasks require the separate credential-isolated runner.');
        }
        if ($task['kind'] === 'monitor') {
            $problems = $this->client->rows("select name,status from d1_runtime_health where status<>'healthy'");
            if ($problems !== []) {
                $recipient = config('backup.notifications.mail.to');
                if (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException('Production backup alert recipient is not configured.');
                }
                Mail::raw('FamilyFunds Cloudflare maintenance needs attention. Check backup health, failed jobs and retained dead-letter events.', fn (Message $message) => $message->to($recipient)->subject('FamilyFunds maintenance alert'));
            }

            return ['exit_code' => 0, 'complete' => true, 'cursor' => 0];
        }
        if ($family < 1) {
            throw new RuntimeException('Application maintenance requires an explicit production family.');
        }

        return $this->runtime->run(function () use ($task, $family, $cursor, $at): array {
            $arguments = ['--no-interaction' => true];
            $next = $cursor;
            $complete = true;
            if (str_starts_with($task['kind'], 'remind')) {
                $ids = DB::table('contributions')->where('family_id', $family)->where('year', (int) $at->format('Y'))->where('month', (int) $at->format('n'))->where('id', '>', $cursor)->orderBy('id')->limit(100)->pluck('id');
                if ($ids->isEmpty()) {
                    return ['exit_code' => 0, 'complete' => true, 'cursor' => $cursor];
                }
                $last = $ids->last();
                if (! is_int($last)) {
                    throw new RuntimeException('Invalid contribution cursor.');
                }
                $next = $last;
                $complete = $ids->count() < 100;
                $command = 'contributions:remind';
                $arguments += ['--day' => $task['kind'] === 'remind25' ? 25 : 28, '--year' => (int) $at->format('Y'), '--month' => (int) $at->format('n'), '--family' => $family, '--after' => $cursor, '--through' => $last];
            } elseif ($task['kind'] === 'generate') {
                $command = 'contributions:generate';
                $arguments += ['--family' => $family, '--year' => (int) $at->format('Y'), '--month' => (int) $at->format('n')];
            } else {
                $command = $task['kind'] === 'purge' ? 'families:purge-archived' : 'reports:dispatch-scheduled';
            }
            $exit = $this->kernel->call($command, $arguments, new NullOutput);
            if ($exit !== 0) {
                throw new RuntimeException('Production maintenance command did not succeed.');
            }

            return ['exit_code' => 0, 'complete' => $complete, 'cursor' => $next];
        }, $family);
    }
}
