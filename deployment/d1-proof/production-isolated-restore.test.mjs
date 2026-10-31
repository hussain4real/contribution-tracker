import assert from 'node:assert/strict';
import {createHash, createHmac} from 'node:crypto';
import {DatabaseSync} from 'node:sqlite';
import test from 'node:test';
import {pendingProductionWebhookReferences, productionBackupPrefix, sealProductionBackup} from './production-backup.mjs';
import {restoreProductionCheckpoint} from './production-isolated-restore.mjs';
import {relayProductionWebhooks} from './production-webhooks.mjs';

function bucket() {
    const data = new Map(), metadata = new Map();
    return {data, async list({prefix = ''}) { return {objects: [...data.keys()].filter(key => key.startsWith(prefix)).map(key => ({key})), truncated: false}; },
        async get(key) { const value = data.get(key); if (value === undefined) return null; const bytes = typeof value === 'string' ? new TextEncoder().encode(value) : value;
            return {size: bytes.length, ...metadata.get(key), text: async () => new TextDecoder().decode(bytes), arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength)}; },
        async put(key, value, options = {}) { if (options.onlyIf?.etagDoesNotMatch === '*' && data.has(key)) return null;
            data.set(key, value); metadata.set(key, {httpMetadata: options.httpMetadata, customMetadata: options.customMetadata}); return {key}; },
        async delete(key) { data.delete(key); metadata.delete(key); }};
}

function target(db) {
    return {prepare(sql) { return {sql, values: [], bind(...values) { this.values = values; return this; },
        async first() { return db.prepare(sql).get(...this.values); }, async all() { return {results: db.prepare(sql).all(...this.values)}; },
        async run() { return db.prepare(sql).run(...this.values); }}; },
    async batch(statements) { db.exec('begin'); try { const result = []; for (const statement of statements) result.push(await statement.all()); db.exec('commit'); return result;
    } catch (error) { db.exec('rollback'); throw error; } }};
}

async function fixture() {
    const db = new DatabaseSync(':memory:'), backups = bucket(), files = bucket();
    const checkpoint = 'cutover-20261031t120000z', prefix = productionBackupPrefix(checkpoint);
    const secret = 'base64:' + Buffer.alloc(32, 7).toString('base64');
    const names = ['families', 'users', 'report_artifacts', 'reconciliation_imports', 'd1_runtime_webhook_inbox', ...Array.from({length: 35}, (_, index) => `fixture_${index}`)];
    const schema = names.map(name => ({type: 'table', name, tbl_name: name, sql: name === 'd1_runtime_webhook_inbox'
        ? 'CREATE TABLE d1_runtime_webhook_inbox (id text primary key, object_key text, accepted_at integer)'
        : name === 'report_artifacts' || name === 'reconciliation_imports' ? `CREATE TABLE "${name}" (id integer primary key autoincrement, disk text, path text)`
        : `CREATE TABLE "${name}" (id integer primary key autoincrement)`}));
    schema.push({type: 'index', name: 'users_id_index', tbl_name: 'users', sql: 'CREATE INDEX users_id_index on users(id)'});
    const tables = Object.fromEntries(names.map(name => [name, []]));
    tables.families = [{id: 7}]; tables.users = [{id: 8}];
    const raw = JSON.stringify({event: 'charge.success', data: {domain: 'live', reference: 'synthetic-recovery'}});
    const webhookSha = createHash('sha256').update(raw).digest('hex');
    const signature = createHmac('sha512', 'sk_live_synthetic_fixture').update(raw).digest('hex');
    const bytes = new TextEncoder().encode(JSON.stringify({provider: 'paystack', raw, signature}));
    const sha = createHash('sha256').update(bytes).digest('hex');
    const path = `private/webhook-inbox/paystack/${webhookSha}.json`, fileKey = prefix + `files/${sha}.json.aes-gcm`;
    tables.d1_runtime_webhook_inbox = [{id: `paystack:${webhookSha}`, object_key: path, accepted_at: null}];
    const snapshot = {version: 1, database: 'familyfunds-production', schema, tables, sequences: [{name: 'families', seq: 7}, {name: 'users', seq: 8}],
        files: [{path, bytes: bytes.length, sha256: sha, backup_key: fileKey, http_metadata: {contentType: 'application/json'}, custom_metadata: {kind: 'test'}}]};
    const databaseKey = prefix + 'd1/2026-10-31.json.aes-gcm';
    await backups.put(fileKey, JSON.stringify(await sealProductionBackup(bytes, secret, `file:${sha}`)));
    const save = async value => backups.put(databaseKey, JSON.stringify(await sealProductionBackup(new TextEncoder().encode(JSON.stringify(value)), secret, 'database')));
    await save(snapshot);
    const env = {PRODUCTION_ISOLATED_RESTORE_ENABLED: 'true', RECOVERY_DATABASE_NAME: 'familyfunds-production-isolated-recovery',
        RECOVERY_BUCKET_NAME: 'familyfunds-production-isolated-recovery-files', RECOVERY_DB: target(db), RECOVERY_FILES: files, BACKUP_FILES: backups, BACKUP_KEY: secret};
    return {db, env, snapshot, save, checkpoint, path, backups, databaseKey};
}

test('restores a synthetic production checkpoint into empty isolated targets', async () => {
    const f = await fixture();
    try {
        const result = await restoreProductionCheckpoint(f.env, f.checkpoint);
        assert.deepEqual(result, {status: 'passed', tables: 40, files: 1, sequences: 2, pending_webhooks: 1, integrity: 'ok'});
        assert.equal(f.db.prepare('select count(*) n from families').get().n, 1);
        assert.equal(f.db.prepare("select count(*) n from sqlite_master where name='users_id_index'").get().n, 1);
        assert.ok(f.env.RECOVERY_FILES.data.has(f.path));
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /targets must be empty/i);
    } finally { f.db.close(); }
});

test('rejects disabled, missing, corrupt and incomplete checkpoints before writing', async () => {
    const f = await fixture();
    try {
        await assert.rejects(restoreProductionCheckpoint({...f.env, PRODUCTION_ISOLATED_RESTORE_ENABLED: 'false'}, f.checkpoint), /disabled/i);
        const original = f.backups.data.get(f.databaseKey);
        f.backups.data.delete(f.databaseKey);
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /exactly one/i);
        f.backups.data.set(f.databaseKey, original);
        await f.save({...f.snapshot, files: []});
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /missing pending webhook/i);
        await f.save({...f.snapshot, files: [{...f.snapshot.files[0], http_metadata: null}]});
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /metadata/i);
        const badWebhook = {...f.snapshot.tables.d1_runtime_webhook_inbox[0], id: `paystack:${'e'.repeat(64)}`, object_key: `private/webhook-inbox/paystack/${'e'.repeat(64)}.json`};
        await f.save({...f.snapshot, tables: {...f.snapshot.tables, d1_runtime_webhook_inbox: [badWebhook]}, files: [{...f.snapshot.files[0], path: badWebhook.object_key}]});
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /invalid pending webhook payload/i);
        await f.save({...f.snapshot, tables: {...f.snapshot.tables, report_artifacts: [{id: 1, disk: 'local', path: 'reports/missing.csv'}]}});
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /missing checkpoint referenced file/i);
        await f.save({...f.snapshot, tables: {...f.snapshot.tables, families: [{id: 1}, {id: 2, unexpected: 3}]}});
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /unsupported checkpoint row/i);
        f.backups.data.set(f.databaseKey, original);
        f.backups.data.set(f.snapshot.files[0].backup_key, '{}');
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /identity/i);
        assert.equal(f.db.prepare("select count(*) n from sqlite_master where type='table'").get().n, 0);
        assert.equal(f.env.RECOVERY_FILES.data.size, 0);
    } finally { f.db.close(); }
});

test('preserves competing recovery files when a conditional write loses a race', async () => {
    const f = await fixture();
    try {
        const originalPut = f.env.RECOVERY_FILES.put;
        f.env.RECOVERY_FILES.put = async (key, value, options) => {
            f.env.RECOVERY_FILES.data.set(key, 'competing evidence');
            return originalPut(key, value, options);
        };
        await assert.rejects(restoreProductionCheckpoint(f.env, f.checkpoint), /recovery file already exists/i);
        assert.equal(f.env.RECOVERY_FILES.data.get(f.path), 'competing evidence');
        assert.equal(f.db.prepare('select count(*) n from families').get().n, 1);
    } finally { f.db.close(); }
});

test('relays a restored signed webhook once when its reference is reconstructed twice', async () => {
    const f = await fixture();
    try {
        await restoreProductionCheckpoint(f.env, f.checkpoint);
        const rows = (await f.env.RECOVERY_DB.prepare('select id,object_key,accepted_at from d1_runtime_webhook_inbox').all()).results;
        const [reference] = pendingProductionWebhookReferences(rows);
        let forwarded = 0, acknowledged = 0, retried = 0;
        const env = {DB: f.env.RECOVERY_DB, FILES: f.env.RECOVERY_FILES, WEBHOOKS_ENABLED: 'true', DATABASE_NAME: 'familyfunds-production',
            WEBHOOK_SECRETS: JSON.stringify({paystack_secret: 'sk_live_synthetic_fixture', meta_app_id: '26405719292393073', meta_app_secret: 'synthetic', meta_waba: 'synthetic', meta_phone: 'synthetic', meta_verify_token: 'synthetic'}),
            RELAY_TOKEN: 'r'.repeat(40), PRODUCTION_CONTAINER: {getByName: () => ({fetch: async () => { forwarded++; return new Response('accepted', {status: 202}); }})}};
        const message = {id: 'synthetic-recovery', body: reference, ack: () => acknowledged++, retry: () => retried++};
        await relayProductionWebhooks({messages: [message]}, env);
        await relayProductionWebhooks({messages: [message]}, env);
        assert.equal(forwarded, 1);
        assert.equal(acknowledged, 2);
        assert.equal(retried, 0);
        assert.equal(f.env.RECOVERY_FILES.data.size, 0);
        assert.deepEqual(pendingProductionWebhookReferences((await env.DB.prepare('select id,object_key,accepted_at from d1_runtime_webhook_inbox').all()).results), []);
    } finally { f.db.close(); }
});
