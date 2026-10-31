import assert from 'node:assert/strict';
import {createHash, webcrypto} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import test from 'node:test';
import {runInNewContext} from 'node:vm';
import {listProductionObjects, openProductionBackup, pendingProductionWebhookReferences, productionBackupPrefix, productionBackupSlot, sealProductionBackup, BACKUP_PREFIX} from './production-backup.mjs';

function bucket(initial = {}) {
    const data = new Map(Object.entries(initial));
    const metadata = new Map();
    return {data, async head(key) { return data.has(key) ? {key} : null; },
        async list({prefix}) { return {objects: [...data.keys()].filter(key => key.startsWith(prefix)).map(key => ({key})), truncated: false}; },
        async get(key) { const value = data.get(key); if (value === undefined) return null; const bytes = typeof value === 'string' ? new TextEncoder().encode(value) : value;
            return {size: bytes.length, httpMetadata: metadata.get(key)?.httpMetadata, customMetadata: metadata.get(key)?.customMetadata, arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength), text: async () => new TextDecoder().decode(bytes)}; },
        async put(key, value, options = {}) { if (options.onlyIf?.etagDoesNotMatch === '*' && data.has(key)) return null; data.set(key, value); metadata.set(key, options); return {key}; }, async delete(key) { data.delete(key); metadata.delete(key); }};
}

function fixture(overrides = {}, clock = Date, cryptography = webcrypto) {
    const source = readFileSync(new URL('./production-migration-recovery.mjs', import.meta.url), 'utf8')
        .replace(/^import .*;\n/gm, '').replace('export async function ', 'async function ').replace('export class ', 'class ');
    const env = {MIGRATION_RECOVERY_ENABLED: 'true', DATABASE_NAME: 'familyfunds-production', ENABLED: 'false', BACKUPS_ENABLED: 'false', OFFSITE_ENABLED: 'false',
        RECOVERY_NAME: 'familyfunds-production-migration-recovery',
        DB: {prepare: sql => ({first: async () => ({families: 44, users: 66, jobs: 0}), all: async () => ({results: sql.includes('report_artifacts') ? [{disk: 'local', path: 'reports/actual.pdf'}] : []})})},
        APPLICATION_FILES: bucket({'private/reports/actual.pdf': 'actual', 'private/native/synthetic.pdf': 'synthetic', 'private/webhook-inbox/old.json': 'retain'}),
        BACKUP_FILES: bucket({[BACKUP_PREFIX + 'd1/2026-10-27.json.aes-gcm']: 'old encrypted synthetic'}),
        RECOVERY_DB: {prepare: () => ({first: async () => ({n: 1})})}, ...overrides};
    const Recovery = runInNewContext(source + '\nProductionMigrationRecovery', {WorkerEntrypoint: class {constructor(env) {this.env = env;}},
        crypto: cryptography, TextEncoder, TextDecoder, Date: clock, Set, BACKUP_PREFIX, listProductionObjects, openProductionBackup, pendingProductionWebhookReferences, productionBackupPrefix, productionBackupSlot,
        createProductionBackup: async (_env, _at, checkpoint) => ({key: productionBackupPrefix(checkpoint) + 'd1/fixture'})});
    return {env, recovery: new Recovery(env)};
}

test('actual-data recovery refuses active schedulers and wrong snapshot identities', async () => {
    for (const change of [{MIGRATION_RECOVERY_ENABLED: 'false'}, {ENABLED: 'true'}, {BACKUPS_ENABLED: 'true'}, {OFFSITE_ENABLED: 'true'},
        {DB: {prepare: () => ({first: async () => ({families: 2, users: 1003, jobs: 0})})}}]) {
        const f = fixture(change);
        await assert.rejects(f.recovery.run('archive-synthetic'));
        assert.equal(f.env.BACKUP_FILES.data.size, 1);
    }
});

test('an isolated synthetic checkpoint restores tables, sequence values and verified files', async () => {
    const db = new DatabaseSync(':memory:');
    const makeTarget = db => ({
        prepare(sql) {
            const statement = {sql, values: [], bind(...values) { this.values = values; return this; },
                async all() { return {results: db.prepare(sql).all(...this.values)}; },
                async first() { return db.prepare(sql).get(...this.values); }};
            return statement;
        },
        async batch(statements) {
            db.exec('BEGIN');
            try {
                const results = [];
                for (const statement of statements) results.push(await statement.all());
                db.exec('COMMIT');
                return results;
            } catch (error) {
                db.exec('ROLLBACK');
                throw error;
            }
        },
    });
    const target = makeTarget(db);
    const checkpoint = 'cutover-20261030t180000z';
    const capturedAt = new Date('2026-10-30T18:00:00Z');
    class FixedDate extends Date {
        constructor(...args) { super(...(args.length ? args : [capturedAt.valueOf()])); }
    }
    class FollowingDay extends Date {
        constructor(...args) { super(...(args.length ? args : [capturedAt.valueOf() + 86400000])); }
    }
    const secret = 'base64:' + Buffer.alloc(32, 7).toString('base64');
    const names = ['families', 'users', 'd1_runtime_webhook_inbox', ...Array.from({length: 52}, (_, index) => `fixture_${index}`)];
    const tables = Object.fromEntries(names.map(name => [name, []]));
    tables.families = Array.from({length: 44}, (_, index) => ({id: index + 1}));
    tables.users = Array.from({length: 66}, (_, index) => ({id: index + 1}));
    const webhookId = 'e'.repeat(64), webhookPath = `private/webhook-inbox/paystack/${webhookId}.json`;
    tables.d1_runtime_webhook_inbox = [{id: `paystack:${webhookId}`, object_key: webhookPath, accepted_at: null}];
    const schema = names.map(name => ({type: 'table', name, sql: name === 'd1_runtime_webhook_inbox'
        ? 'create table d1_runtime_webhook_inbox (id text primary key, object_key text, accepted_at integer)'
        : `create table "${name}" (id integer primary key autoincrement)`}));
    schema.push({type: 'index', name: 'fixture_users_index', sql: 'create index fixture_users_index on users(id)'});
    schema.push({type: 'trigger', name: 'fixture_users_trigger', sql: 'create trigger fixture_users_trigger after insert on users begin select 1; end'});
    const sequences = [{name: 'families', seq: 100}, {name: 'users', seq: 200}];
    const backups = bucket();
    const application = bucket({'private/existing.txt': 'keep'});
    const files = [];
    for (let index = 0; index < 7; index++) {
        const bytes = new TextEncoder().encode(`synthetic file ${index}`);
        const sha256 = createHash('sha256').update(bytes).digest('hex');
        const backupKey = productionBackupPrefix(checkpoint) + `files/${sha256}.json.aes-gcm`;
        await backups.put(backupKey, JSON.stringify(await sealProductionBackup(bytes, secret, `file:${sha256}`)));
        files.push({bytes: bytes.length, sha256, backup_key: backupKey,
            ...(index === 0 ? {path: webhookPath} : {}),
            http_metadata: {contentType: 'text/plain', cacheExpiry: '2026-12-01T00:00:00.000Z'}, custom_metadata: {kind: 'synthetic'}});
    }
    const snapshot = {schema, tables, sequences, files};
    const backupKey = productionBackupPrefix(checkpoint) + `d1/${productionBackupSlot(capturedAt)}.json.aes-gcm`;
    await backups.put(backupKey, JSON.stringify(await sealProductionBackup(new TextEncoder().encode(JSON.stringify(snapshot)), secret, 'database')));
    const runId = '11111111-1111-4111-8111-111111111111';
    const f = fixture({RECOVERY_DB: target, BACKUP_FILES: backups, APPLICATION_FILES: application, BACKUP_KEY: secret}, FollowingDay,
        {subtle: webcrypto.subtle, randomUUID: () => runId});

    try {
        const original = backups.data.get(backupKey);
        backups.data.delete(backupKey);
        await assert.rejects(f.recovery.run('restore', checkpoint), /exactly one production database snapshot/i);
        backups.data.set(backupKey, original);
        const duplicateKey = productionBackupPrefix(checkpoint) + 'd1/2026-10-31.json.aes-gcm';
        backups.data.set(duplicateKey, original);
        await assert.rejects(f.recovery.run('restore', checkpoint), /exactly one production database snapshot/i);
        assert.equal(db.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*'").get().n, 0);
        backups.data.delete(duplicateKey);

        const malformed = {...snapshot, files: snapshot.files.map((file, index) => index === 0 ? {...file, custom_metadata: null} : file)};
        backups.data.set(backupKey, JSON.stringify(await sealProductionBackup(new TextEncoder().encode(JSON.stringify(malformed)), secret, 'database')));
        await assert.rejects(f.recovery.run('restore', checkpoint), /invalid production checkpoint file metadata/i);
        assert.equal(db.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*'").get().n, 0);
        backups.data.set(backupKey, original);

        const missingWebhook = {...snapshot, files: snapshot.files.map((file, index) => index === 0 ? {...file, path: 'private/other.json'} : file)};
        backups.data.set(backupKey, JSON.stringify(await sealProductionBackup(new TextEncoder().encode(JSON.stringify(missingWebhook)), secret, 'database')));
        await assert.rejects(f.recovery.run('restore', checkpoint), /pending production webhook file missing/i);
        assert.equal(db.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*'").get().n, 0);
        backups.data.set(backupKey, original);

        const conflictPath = `migration-recovery/actual-20261027/${runId}/${files[0].sha256}`;
        application.data.set(conflictPath, 'existing recovery evidence');
        await assert.rejects(f.recovery.run('restore', checkpoint), /recovery file already exists/i);
        assert.equal(application.data.get(conflictPath), 'existing recovery evidence');
        assert.equal(db.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*'").get().n, 0);
        application.data.delete(conflictPath);

        const result = await f.recovery.run('restore', checkpoint);
        assert.equal(result.status, 'passed');
        assert.equal(result.tables.length, 55);
        assert.equal(result.files.length, 7);
        assert.equal(result.metadata_verified, true);
        assert.equal(result.pending_webhooks, 1);
        assert.equal(result.integrity, 'ok');
        assert.equal(result.tamper_rejected, true);
        assert.deepEqual(db.prepare('select name,seq from sqlite_sequence order by name').all().map(row => ({...row})), sequences);
        assert.equal(db.prepare('select count(*) n from families').get().n, 44);
        assert.equal(db.prepare('select count(*) n from users').get().n, 66);
        assert.equal(db.prepare("select count(*) n from sqlite_master where name in ('fixture_users_index','fixture_users_trigger')").get().n, 2);
        assert.deepEqual([...application.data.keys()], ['private/existing.txt']);
        await assert.rejects(f.recovery.run('restore', checkpoint), /must be empty/);

        const legacyDb = new DatabaseSync(':memory:');
        try {
            const legacy = {...snapshot, files: snapshot.files.map(file => ({bytes: file.bytes, sha256: file.sha256, backup_key: file.backup_key,
                ...(file.path ? {path: file.path} : {})}))};
            backups.data.set(backupKey, JSON.stringify(await sealProductionBackup(new TextEncoder().encode(JSON.stringify(legacy)), secret, 'database')));
            const legacyRecovery = fixture({RECOVERY_DB: makeTarget(legacyDb), BACKUP_FILES: backups, APPLICATION_FILES: bucket(), BACKUP_KEY: secret}, FixedDate,
                {subtle: webcrypto.subtle, randomUUID: () => runId});
            const legacyResult = await legacyRecovery.recovery.run('restore', checkpoint);
            assert.equal(legacyResult.status, 'partial');
            assert.equal(legacyResult.metadata_verified, false);
            backups.data.set(backupKey, original);
        } finally {
            backups.data.set(backupKey, original);
            legacyDb.close();
        }

        const noInboxDb = new DatabaseSync(':memory:');
        try {
            const tablesWithoutInbox = {...snapshot.tables};
            delete tablesWithoutInbox.d1_runtime_webhook_inbox;
            tablesWithoutInbox.legacy_fixture = [];
            const noInbox = {...snapshot, tables: tablesWithoutInbox, schema: snapshot.schema.map(item => item.name === 'd1_runtime_webhook_inbox'
                ? {type: 'table', name: 'legacy_fixture', sql: 'create table legacy_fixture(id integer primary key autoincrement)'} : item)};
            backups.data.set(backupKey, JSON.stringify(await sealProductionBackup(new TextEncoder().encode(JSON.stringify(noInbox)), secret, 'database')));
            const recovery = fixture({RECOVERY_DB: makeTarget(noInboxDb), BACKUP_FILES: backups, APPLICATION_FILES: bucket(), BACKUP_KEY: secret}, FixedDate,
                {subtle: webcrypto.subtle, randomUUID: () => runId});
            const result = await recovery.recovery.run('restore', checkpoint);
            assert.equal(result.status, 'partial');
            assert.equal(result.metadata_verified, true);
            assert.equal(result.pending_webhooks, null);
        } finally {
            backups.data.set(backupKey, original);
            noInboxDb.close();
        }

        const racingDb = new DatabaseSync(':memory:');
        try {
            const racingBucket = bucket();
            const put = racingBucket.put;
            racingBucket.put = async (key, value, options) => {
                racingBucket.data.set(key, 'competing recovery evidence');
                return put(key, value, options);
            };
            const racing = fixture({RECOVERY_DB: makeTarget(racingDb), BACKUP_FILES: backups, APPLICATION_FILES: racingBucket, BACKUP_KEY: secret}, FixedDate,
                {subtle: webcrypto.subtle, randomUUID: () => runId});
            await assert.rejects(racing.recovery.run('restore', checkpoint), /recovery file already exists/i);
            assert.equal(racingBucket.data.get(conflictPath), 'competing recovery evidence');
        } finally {
            racingDb.close();
        }

        const metadataDb = new DatabaseSync(':memory:');
        try {
            const changedBucket = bucket();
            const get = changedBucket.get;
            changedBucket.get = async key => {
                const object = await get(key);
                return object ? {...object, customMetadata: {kind: 'changed'}} : null;
            };
            const changed = fixture({RECOVERY_DB: makeTarget(metadataDb), BACKUP_FILES: backups, APPLICATION_FILES: changedBucket, BACKUP_KEY: secret}, FixedDate,
                {subtle: webcrypto.subtle, randomUUID: () => runId});
            await assert.rejects(changed.recovery.run('restore', checkpoint), /file metadata differs/i);
            assert.equal(changedBucket.data.size, 0);
        } finally {
            metadataDb.close();
        }

        const failedDb = new DatabaseSync(':memory:');
        try {
            const failedBucket = bucket();
            failedBucket.get = async () => null;
            const failed = fixture({RECOVERY_DB: makeTarget(failedDb), BACKUP_FILES: backups, APPLICATION_FILES: failedBucket, BACKUP_KEY: secret}, FixedDate,
                {subtle: webcrypto.subtle, randomUUID: () => runId});
            await assert.rejects(failed.recovery.run('restore', checkpoint), /missing or excessive bounded recovery object/i);
            assert.equal(failedBucket.data.size, 0);
        } finally {
            failedDb.close();
        }
    } finally {
        db.close();
    }
});

test('synthetic archive preserves actual referenced files and webhook evidence, verifies copies and is repeatable', async () => {
    const f = fixture();
    const first = await f.recovery.run('archive-synthetic');
    assert.equal(first.archived.length, 2);
    assert.equal(first.verified, true);
    assert.equal(f.env.APPLICATION_FILES.data.get('private/reports/actual.pdf'), 'actual');
    assert.equal(f.env.APPLICATION_FILES.data.get('private/webhook-inbox/old.json'), 'retain');
    assert.equal(f.env.APPLICATION_FILES.data.has('private/native/synthetic.pdf'), false);
    assert.equal(f.env.BACKUP_FILES.data.has(BACKUP_PREFIX + 'd1/2026-10-27.json.aes-gcm'), false);
    assert.equal((await f.recovery.run('archive-synthetic')).already_archived, true);
});

test('unknown recovery operations and populated targets never restore or overwrite data', async () => {
    const f = fixture();
    await assert.rejects(f.recovery.run('arbitrary'));
    await assert.rejects(f.recovery.run('restore', 'cutover-20261027t140000z'), /must be empty/);
    await assert.rejects(f.recovery.run('backup', 'cutover-20261027t140000z'), /Archive synthetic/);
});

test('fresh checkpoint requests use the explicit operation identity instead of a cached environment value', async () => {
    const f = fixture({CUTOVER_CHECKPOINT: 'cutover-20261027t010000z'});
    f.env.BACKUP_FILES.data.set('migration-archive/synthetic-20261027/completed.json', '{}');
    const result = await f.recovery.run('backup', 'cutover-20261027t150000z');
    assert.ok(result.key.includes('/cutover-20261027t150000z/'));
    for (const value of [null, '../path', 'old-checkpoint']) await assert.rejects(f.recovery.run('backup', value));
    assert.equal((await f.recovery.run('status')).release, 'production-recovery-v17-explicit-checkpoint');
});

test('offsite verification requires and forwards the same explicit checkpoint as the frozen backup', async () => {
    let received;
    const f = fixture({OFFSITE_CONTAINER: {getByName: () => ({copy: async task => {received = task; return {complete: true};}})}});
    for (const checkpoint of [null, '../path', 'old-checkpoint']) await assert.rejects(f.recovery.run('offsite', checkpoint));
    await f.recovery.run('offsite', 'cutover-20261028t150836z');
    assert.equal(received.checkpoint, 'cutover-20261028t150836z');
    assert.equal(received.kind, 'backup-sync');
});
