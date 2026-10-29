import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {runInNewContext} from 'node:vm';
import {listProductionObjects, productionBackupPrefix, BACKUP_PREFIX} from './production-backup.mjs';

function bucket(initial = {}) {
    const data = new Map(Object.entries(initial));
    return {data, async head(key) { return data.has(key) ? {key} : null; },
        async list({prefix}) { return {objects: [...data.keys()].filter(key => key.startsWith(prefix)).map(key => ({key})), truncated: false}; },
        async get(key) { const value = data.get(key); if (value === undefined) return null; const bytes = typeof value === 'string' ? new TextEncoder().encode(value) : value;
            return {size: bytes.length, arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength), text: async () => new TextDecoder().decode(bytes)}; },
        async put(key, value) { data.set(key, value); }, async delete(key) { data.delete(key); }};
}

function fixture(overrides = {}) {
    const source = readFileSync(new URL('./production-migration-recovery.mjs', import.meta.url), 'utf8')
        .replace(/^import .*;\n/gm, '').replace('export async function ', 'async function ').replace('export class ', 'class ');
    const env = {MIGRATION_RECOVERY_ENABLED: 'true', DATABASE_NAME: 'familyfunds-production', ENABLED: 'false', BACKUPS_ENABLED: 'false', OFFSITE_ENABLED: 'false',
        RECOVERY_NAME: 'familyfunds-production-migration-recovery',
        DB: {prepare: sql => ({first: async () => ({families: 44, users: 66, jobs: 0}), all: async () => ({results: sql.includes('report_artifacts') ? [{disk: 'local', path: 'reports/actual.pdf'}] : []})})},
        APPLICATION_FILES: bucket({'private/reports/actual.pdf': 'actual', 'private/native/synthetic.pdf': 'synthetic', 'private/webhook-inbox/old.json': 'retain'}),
        BACKUP_FILES: bucket({[BACKUP_PREFIX + 'd1/2026-10-27.json.aes-gcm']: 'old encrypted synthetic'}),
        RECOVERY_DB: {prepare: () => ({first: async () => ({n: 1})})}, ...overrides};
    const Recovery = runInNewContext(source + '\nProductionMigrationRecovery', {WorkerEntrypoint: class {constructor(env) {this.env = env;}},
        crypto: webcrypto, TextEncoder, TextDecoder, Date, Set, BACKUP_PREFIX, listProductionObjects, productionBackupPrefix,
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
