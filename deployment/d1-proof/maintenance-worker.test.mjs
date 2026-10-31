import assert from "node:assert/strict";
import {webcrypto} from "node:crypto";
import {readFileSync} from "node:fs";
import {DatabaseSync} from "node:sqlite";
import {test} from "node:test";
import {runInNewContext} from "node:vm";

const source = readFileSync(new URL("./maintenance-worker.mjs", import.meta.url), "utf8").replace(/^import .*;\n/gm, "").replace("export default ", "const handler = ").replaceAll("export class ", "class ");
const {handler, StagingBackupOnly, StagingRecoveryOnly, calendarTasks, sealBackup, openBackup, backupKey, createBackup, backupSlot} = runInNewContext(source + "\n({handler, StagingBackupOnly, StagingRecoveryOnly, calendarTasks, sealBackup, openBackup, backupKey, createBackup, backupSlot})", {
    WorkerEntrypoint: class {constructor(env) { this.env = env; }}, crypto: webcrypto, TextEncoder, TextDecoder, Response, btoa, atob, console,
});
const secret = "base64:" + Buffer.alloc(32, 7).toString("base64");

function backupFixture() {
    const objects = new Map();
    const checkpointObjects = new Map();
    const recoveryObjects = new Map();
    const recoveryMetadata = new Map();
    const schema = Array.from({length: 40}, (_, i) => ({type: "table", name: "table_" + i, tbl_name: "table_" + i, sql: "create table table_" + i + "(id integer)"}));
    let badIntegrity = false;
    let reads = 0;
    const env = {
        DATABASE_NAME: "familyfunds-d1-web-staging", ENABLED: "true", MAINTENANCE_FAMILY_ID: "50", PURGE_FAMILY_ID: "51", BACKUP_APP_KEY: secret,
        RECOVERY_FILES: {list: async () => ({objects: [...recoveryObjects.keys()].map(key => ({key})), truncated: false}), put: async (key, bytes, options) => { if (options.onlyIf?.etagDoesNotMatch === '*' && recoveryObjects.has(key)) return null; recoveryObjects.set(key, bytes); recoveryMetadata.set(key, options); return {key}; }, get: async key => { const bytes = recoveryObjects.get(key); const metadata = recoveryMetadata.get(key); return bytes === undefined ? null : {size: bytes.length, httpMetadata: {contentLanguage: undefined, ...metadata?.httpMetadata}, customMetadata: metadata?.customMetadata, arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength)}; }},
        STAGING_CHECKPOINT_BUCKET_NAME: 'familyfunds-d1-staging-checkpoints',
        STAGING_CHECKPOINT_FILES: {head: async key => checkpointObjects.has(key), get: async key => { const value = checkpointObjects.get(key); if (value === undefined) return null; const bytes = new TextEncoder().encode(value); return {size: bytes.length, arrayBuffer: async () => bytes.buffer}; }, put: async (key, body, options) => { if (options.onlyIf?.etagDoesNotMatch === '*' && checkpointObjects.has(key)) return null; checkpointObjects.set(key, body); return {key}; }},
        STAGING_FILES: {head: async key => objects.has(key), list: async ({prefix}) => ({objects: [...objects].filter(([key]) => key.startsWith(prefix)).map(([key, value]) => ({key, size: new TextEncoder().encode(value).length, etag: 'fixture-etag', version: 'fixture-version'})), truncated: false}), get: async key => { const value = objects.get(key); if (value === undefined) return null; const bytes = new TextEncoder().encode(value); return {size: bytes.length, etag: 'fixture-etag', version: 'fixture-version', body: true, httpMetadata: key.startsWith('private/reports/') ? {contentType: 'text/csv'} : {}, customMetadata: key.startsWith('private/reports/') ? {kind: 'synthetic'} : {}, arrayBuffer: async () => bytes.buffer}; }, put: async (key, body, options) => { if (options.onlyIf?.etagDoesNotMatch === '*' && objects.has(key)) return null; objects.set(key, body); return {key}; }},
        DB: {
            prepare: sql => ({sql, all: async () => { reads++; return {results: schema}; }}),
            batch: async statements => statements.map(statement => ({results: statement.sql.startsWith("select *") ? [{id: 1, body: "private fixture"}]
                : statement.sql.includes("sqlite_sequence") ? [] : statement.sql === "pragma foreign_key_check" ? badIntegrity ? [{table: "broken"}] : []
                    : statement.sql === "pragma quick_check" ? [{quick_check: "ok"}] : schema})),
        },
    };
    return {env, objects, checkpointObjects, recoveryObjects, recoveryMetadata, reads: () => reads, corrupt: () => { badIntegrity = true; }};
}

test("calendar tasks catch up missed monthly work and respect reminder boundaries", () => {
    const f = {family: 50, purge: 51};
    const kinds = at => Array.from(calendarTasks(new Date(at), f), task => task.kind);
    assert.deepEqual(kinds("2026-09-25T07:59:59Z"), ["generate", "purge"]);
    assert.deepEqual(kinds("2026-09-25T08:00:00Z"), ["generate", "remind25", "purge"]);
    assert.deepEqual(kinds("2026-09-28T08:00:00Z"), ["generate", "remind25", "remind28", "purge"]);
    assert.equal(calendarTasks(new Date("2026-10-01T00:05:00Z"), f)[0].id, "generate:2026-10");
    assert.equal(calendarTasks(new Date("2026-10-01T00:05:00Z"), f).at(-1).at, "2026-09-30T01:30:00.000Z");
});

test('staging calendar uses the configured reminder days and UTC time', () => {
    const families = {family: 50, purge: 51};
    const settings = JSON.stringify({reminder_day: 10, follow_up_day: 20, time: '14:35', effective_from: '2026-10'});
    const before = Array.from(calendarTasks(new Date('2026-10-10T14:34:59Z'), families, settings), task => task.kind);
    const at = Array.from(calendarTasks(new Date('2026-10-10T14:35:00Z'), families, settings), task => task.kind);
    assert.deepEqual(before, ['generate', 'purge']);
    assert.deepEqual(at, ['generate', 'remind25', 'purge']);
});

test("daily backups catch up the latest 02:30 Asia Qatar slot", () => {
    assert.equal(backupSlot(new Date("2026-10-25T23:29:59Z")).toISOString().slice(0, 10), "2026-10-25");
    assert.equal(backupSlot(new Date("2026-10-25T23:30:00Z")).toISOString().slice(0, 10), "2026-10-26");
});

test("snapshots use authenticated encryption with unique nonces", async () => {
    const data = {database: "familyfunds-d1-web-staging", body: "private fixture"};
    const first = await sealBackup(data, secret);
    const second = await sealBackup(data, secret);
    assert.notEqual(first.envelope.iv, second.envelope.iv);
    assert.ok(!JSON.stringify(first.envelope).includes("private fixture"));
    const options = {name: "AES-GCM", iv: Buffer.from(first.envelope.iv, "base64"), additionalData: new TextEncoder().encode("FamilyFunds staging backup v1:familyfunds-d1-web-staging")};
    const bytes = Buffer.from(first.envelope.ciphertext, "base64");
    assert.deepEqual(JSON.parse(new TextDecoder().decode(await webcrypto.subtle.decrypt(options, await backupKey(secret), bytes))), data);
    bytes[0] ^= 1;
    const correctKey = await backupKey(secret);
    await assert.rejects(() => webcrypto.subtle.decrypt(options, correctKey, bytes));
    const wrong = await backupKey("base64:" + Buffer.alloc(32, 8).toString("base64"));
    await assert.rejects(() => webcrypto.subtle.decrypt(options, wrong, Buffer.from(first.envelope.ciphertext, "base64")));
});

test("a durable daily snapshot is reused without recapturing or waking PHP", async () => {
    const {env, objects, reads} = backupFixture();
    const at = new Date("2026-10-26T06:00:00Z");
    const first = await createBackup(env, at);
    const second = await createBackup(env, at);
    assert.equal(first.status, "created");
    assert.equal(second.status, "already_present");
    assert.equal(objects.size, 1);
    assert.equal(reads(), 1);
    assert.equal(first.tables, 40);
});

test("backup-only checkpoints create fresh snapshots without scheduling tasks or overwriting a checkpoint", async () => {
    const {env, objects, checkpointObjects} = backupFixture();
    const service = new StagingBackupOnly(env);
    const checkpoint = 'cutover-20261030t194500z';
    await assert.rejects(service.create(checkpoint), /disabled/i);
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    for (const value of [null, '../other', '2026-10-30']) await assert.rejects(service.create(value), /checkpoint/i);
    env.STAGING_CHECKPOINT_BUCKET_NAME = 'familyfunds-d1-staging-private';
    await assert.rejects(service.create(checkpoint), /wrong staging checkpoint bucket/i);
    env.STAGING_CHECKPOINT_BUCKET_NAME = 'familyfunds-d1-staging-checkpoints';
    env.DATABASE_NAME = 'familyfunds-production';
    await assert.rejects(service.create(checkpoint), /disabled/i);
    env.DATABASE_NAME = 'familyfunds-d1-web-staging';
    assert.equal(objects.size, 0);

    const result = await service.create(checkpoint);
    assert.equal(result.status, 'created');
    assert.equal(result.key, 'private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm');
    assert.equal(objects.size, 0);
    assert.equal(checkpointObjects.size, 2);
    const envelope = JSON.parse(checkpointObjects.get(result.key));
    const options = {name: 'AES-GCM', iv: Buffer.from(envelope.iv, 'base64'), additionalData: new TextEncoder().encode('FamilyFunds staging backup v1:familyfunds-d1-web-staging')};
    const snapshot = JSON.parse(new TextDecoder().decode(await webcrypto.subtle.decrypt(options, await backupKey(secret), Buffer.from(envelope.ciphertext, 'base64'))));
    assert.equal(snapshot.database, 'familyfunds-d1-web-staging');
    assert.equal(Object.keys(snapshot.tables).length, 40);
    await assert.rejects(service.create(checkpoint), /already exists/i);
    assert.equal(checkpointObjects.size, 2);
});

test('a competing checkpoint is preserved when it appears after the initial check', async () => {
    const {env, checkpointObjects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    const put = env.STAGING_CHECKPOINT_FILES.put;
    env.STAGING_CHECKPOINT_FILES.put = async (key, body, options) => {
        checkpointObjects.set(key, 'competing checkpoint');
        return put(key, body, options);
    };

    await assert.rejects(new StagingBackupOnly(env).create('cutover-20261030t194500z'), /already exists/i);
    assert.equal([...checkpointObjects.values()][0], 'competing checkpoint');
});

test('concurrent attempts cannot share a staging checkpoint ID', async () => {
    const {env, checkpointObjects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    const service = new StagingBackupOnly(env);
    const checkpoint = 'cutover-20261031t040000z';
    const attempts = await Promise.allSettled([service.create(checkpoint), service.create(checkpoint)]);
    assert.equal(attempts.filter(item => item.status === 'fulfilled').length, 1);
    assert.match(attempts.find(item => item.status === 'rejected').reason.message, /already exists or was attempted/i);
    assert.equal(checkpointObjects.size, 2);
});

test('checkpoint snapshots include application-owned D1 runtime tables and revision triggers', async () => {
    const db = new DatabaseSync(':memory:');
    try {
        for (let i = 0; i < 40; i++) db.exec(`create table fixture_${i} (id integer primary key autoincrement)`);
        db.exec('create table d1_staging_sequences (name text primary key, next_id integer); insert into d1_staging_sequences values (\'payment\', 7)');
        db.exec('create trigger d1_read_revision_fixture_0_insert after insert on fixture_0 begin select 1; end');
        const {env, objects, checkpointObjects} = backupFixture();
        env.DB = {prepare: sql => ({sql, all: async () => ({results: db.prepare(sql).all()})}),
            batch: async statements => statements.map(statement => ({results: db.prepare(statement.sql).all()}))};
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';
        const result = await new StagingBackupOnly(env).create('cutover-20261030t194501z');
        const envelope = JSON.parse(checkpointObjects.get(result.key));
        const options = {name: 'AES-GCM', iv: Buffer.from(envelope.iv, 'base64'), additionalData: new TextEncoder().encode('FamilyFunds staging backup v1:familyfunds-d1-web-staging')};
        const snapshot = JSON.parse(new TextDecoder().decode(await webcrypto.subtle.decrypt(options, await backupKey(secret), Buffer.from(envelope.ciphertext, 'base64'))));

        assert.equal(snapshot.tables.d1_staging_sequences[0].next_id, 7);
        assert.ok(snapshot.schema.some(item => item.name === 'd1_read_revision_fixture_0_insert'));
        const daily = await createBackup(env, new Date('2026-10-30T19:45:01Z'));
        const dailyEnvelope = JSON.parse(objects.get(daily.key));
        const dailyOptions = {...options, iv: Buffer.from(dailyEnvelope.iv, 'base64')};
        const dailySnapshot = JSON.parse(new TextDecoder().decode(await webcrypto.subtle.decrypt(dailyOptions, await backupKey(secret), Buffer.from(dailyEnvelope.ciphertext, 'base64'))));
        assert.equal(dailySnapshot.tables.d1_staging_sequences, undefined);
        assert.equal(dailySnapshot.schema.some(item => item.name === 'd1_read_revision_fixture_0_insert'), false);
    } finally {
        db.close();
    }
});

test('an isolated empty recovery database restores and verifies a checkpoint without replay', async () => {
    const source = new DatabaseSync(':memory:');
    const recovered = new DatabaseSync(':memory:');
    const adapter = db => ({prepare(sql) { return {sql, values: [], bind(...values) { this.values = values; return this; },
        async all() { return {results: db.prepare(sql).all(...this.values)}; }, async first() { return db.prepare(sql).get(...this.values); }}; },
        async batch(statements) { db.exec('BEGIN'); try { const result = []; for (const statement of statements) result.push(await statement.all()); db.exec('COMMIT'); return result; } catch (error) { db.exec('ROLLBACK'); throw error; } }});
    try {
        for (let i = 0; i < 40; i++) source.exec(`create table fixture_${i} (id integer primary key autoincrement, name text)`);
        source.exec("insert into fixture_0 (id,name) values (8,'synthetic')");
        source.exec('create table d1_staging_read_revision (id integer primary key, revision integer); insert into d1_staging_read_revision values (1,4)');
        source.exec('create trigger d1_read_revision_fixture_0_insert after insert on fixture_0 begin update d1_staging_read_revision set revision=revision+1 where id=1; end');
        const {env, objects, checkpointObjects, recoveryObjects, recoveryMetadata} = backupFixture();
        let keyReads = 0;
        env.BACKUP_APP_KEY = {get: async () => { keyReads++; return secret; }};
        env.DB = adapter(source);
        env.RECOVERY_DB = adapter(recovered);
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';
        objects.set('private/reports/synthetic.csv', 'synthetic report contents');
        const service = new StagingBackupOnly(env);
        const checkpoint = 'cutover-20261030t200001z';
        await service.create(checkpoint);
        const restore = new StagingRecoveryOnly(env);
        await assert.rejects(restore.restore(checkpoint), /disabled/i);
        env.STAGING_RESTORE_ONLY_ENABLED = 'true';
        env.STAGING_RECOVERY_NAME = 'familyfunds-production';
        await assert.rejects(restore.restore(checkpoint), /wrong staging recovery target/i);
        env.STAGING_RECOVERY_NAME = 'familyfunds-d1-staging-recovery';
        env.STAGING_RECOVERY_BUCKET_NAME = 'familyfunds-d1-staging-recovery-files';
        recoveryObjects.set('private/existing', new TextEncoder().encode('keep'));
        await assert.rejects(restore.restore(checkpoint), /recovery bucket must be empty/i);
        assert.equal(new TextDecoder().decode(recoveryObjects.get('private/existing')), 'keep');
        recoveryObjects.clear();
        const key = 'private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm';
        const original = checkpointObjects.get(key);
        const envelope = JSON.parse(original);
        envelope.ciphertext = (envelope.ciphertext[0] === 'A' ? 'B' : 'A') + envelope.ciphertext.slice(1);
        checkpointObjects.set(key, JSON.stringify(envelope));
        await assert.rejects(restore.restore(checkpoint));
        assert.equal(recovered.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*'").get().n, 0);
        checkpointObjects.set(key, original);
        const fileKey = [...checkpointObjects.keys()].find(item => item.includes('/files/'));
        const originalFile = checkpointObjects.get(fileKey);
        const fileEnvelope = JSON.parse(originalFile);
        fileEnvelope.ciphertext = (fileEnvelope.ciphertext[0] === 'A' ? 'B' : 'A') + fileEnvelope.ciphertext.slice(1);
        checkpointObjects.set(fileKey, JSON.stringify(fileEnvelope));
        await assert.rejects(restore.restore(checkpoint));
        assert.equal(recovered.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*'").get().n, 0);
        checkpointObjects.set(fileKey, originalFile);

        const keyReadsBeforeRestore = keyReads;
        const result = await restore.restore(checkpoint);
        assert.equal(keyReads, keyReadsBeforeRestore + 1);
        assert.equal(result.status, 'passed');
        assert.equal(result.tables, 41);
        assert.equal(result.files, 1);
        assert.equal(new TextDecoder().decode(recoveryObjects.get('private/reports/synthetic.csv')), 'synthetic report contents');
        assert.equal(recoveryMetadata.get('private/reports/synthetic.csv').httpMetadata.contentType, 'text/csv');
        assert.equal(recoveryMetadata.get('private/reports/synthetic.csv').customMetadata.kind, 'synthetic');
        assert.equal(objects.get('private/reports/synthetic.csv'), 'synthetic report contents');
        assert.equal(recovered.prepare('select name from fixture_0 where id=8').get().name, 'synthetic');
        recovered.exec("insert into fixture_0(name) values ('next')");
        assert.equal(recovered.prepare('select revision from d1_staging_read_revision where id=1').get().revision, 5);
        assert.equal(recovered.prepare("select seq from sqlite_sequence where name='fixture_0'").get().seq, 9);
        await assert.rejects(restore.restore(checkpoint), /empty/i);

        const racing = new DatabaseSync(':memory:');
        try {
            recoveryObjects.clear();
            env.RECOVERY_DB = adapter(racing);
            const put = env.RECOVERY_FILES.put;
            env.RECOVERY_FILES.put = async (path, bytes, options) => {
                recoveryObjects.set(path, new TextEncoder().encode('competing object'));
                return put(path, bytes, options);
            };
            await assert.rejects(restore.restore(checkpoint), /recovery file already exists/i);
            assert.equal(new TextDecoder().decode(recoveryObjects.get('private/reports/synthetic.csv')), 'competing object');
        } finally {
            racing.close();
        }
    } finally {
        source.close();
        recovered.close();
    }
});

test('checkpoint refuses a missing referenced application file before publishing a snapshot', async () => {
    const db = new DatabaseSync(':memory:');
    try {
        for (let i = 0; i < 39; i++) db.exec(`create table fixture_${i} (id integer primary key autoincrement)`);
        db.exec("create table report_artifacts (id integer primary key autoincrement, disk text, path text); insert into report_artifacts (disk,path) values ('local','reports/missing.csv')");
        const {env, objects, checkpointObjects} = backupFixture();
        env.DB = {prepare: sql => ({sql, all: async () => ({results: db.prepare(sql).all()})}),
            batch: async statements => statements.map(statement => ({results: db.prepare(statement.sql).all()}))};
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';

        await assert.rejects(new StagingBackupOnly(env).create('cutover-20261030t200003z'), /referenced file.*missing/i);
        assert.equal(objects.size, 0);
        assert.equal(checkpointObjects.size, 1);
        await assert.rejects(new StagingBackupOnly(env).create('cutover-20261030t200003z'), /already exists or was attempted/i);
    } finally {
        db.close();
    }
});

test('checkpoint records only the approved missing statements and restores their records', async () => {
    const source = new DatabaseSync(':memory:');
    const recovered = new DatabaseSync(':memory:');
    const adapter = db => ({prepare(sql) { return {sql, values: [], bind(...values) { this.values = values; return this; },
        async all() { return {results: db.prepare(sql).all(...this.values)}; }, async first() { return db.prepare(sql).get(...this.values); }}; },
        async batch(statements) { db.exec('BEGIN'); try { const result = []; for (const statement of statements) result.push(await statement.all()); db.exec('COMMIT'); return result; } catch (error) { db.exec('ROLLBACK'); throw error; } }});
    try {
        for (let i = 0; i < 39; i++) source.exec(`create table fixture_${i} (id integer primary key autoincrement)`);
        source.exec("create table report_artifacts (id integer primary key autoincrement, disk text, path text); insert into report_artifacts values (11,'local','reports/11.csv'),(13,'local','reports/13.csv')");
        const {env, checkpointObjects} = backupFixture();
        env.DB = adapter(source);
        env.RECOVERY_DB = adapter(recovered);
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';
        env.STAGING_RESTORE_ONLY_ENABLED = 'true';
        env.STAGING_RECOVERY_NAME = 'familyfunds-d1-staging-recovery';
        env.STAGING_RECOVERY_BUCKET_NAME = 'familyfunds-d1-staging-recovery-files';
        const backup = new StagingBackupOnly(env);
        const checkpoint = 'cutover-20261030t200006z';
        const exceptions = [{id: 11, path: 'reports/11.csv'}, {id: 13, path: 'reports/13.csv'}];
        await assert.rejects(backup.create('cutover-20261030t200007z', exceptions.slice(0, 1)), /referenced file.*missing/i);
        await assert.rejects(backup.create('cutover-20261030t200008z', [{id: 11, path: 'reports/wrong.csv'}, exceptions[1]]), /referenced file.*missing/i);
        await assert.rejects(backup.create(checkpoint, [{id: 99, path: 'reports/99.csv'}]), /invalid.*exceptions/i);
        assert.equal(checkpointObjects.size, 2);
        const result = await backup.create(checkpoint, exceptions);
        assert.deepEqual(Array.from(result.missing_file_ids), [11, 13]);
        const snapshot = await openBackup(JSON.parse(checkpointObjects.get(result.key)), secret);
        assert.deepEqual(JSON.parse(JSON.stringify(snapshot.missing_files)), [
            {table: 'report_artifacts', id: 11, disk: 'local', path: 'reports/11.csv'},
            {table: 'report_artifacts', id: 13, disk: 'local', path: 'reports/13.csv'},
        ]);
        const original = checkpointObjects.get(result.key);
        snapshot.missing_files.pop();
        checkpointObjects.set(result.key, JSON.stringify((await sealBackup(snapshot, secret)).envelope));
        await assert.rejects(new StagingRecoveryOnly(env).restore(checkpoint), /undocumented missing/i);
        assert.equal(recovered.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*'").get().n, 0);
        checkpointObjects.set(result.key, original);
        const restored = await new StagingRecoveryOnly(env).restore(checkpoint);
        assert.deepEqual(Array.from(restored.missing_file_ids), [11, 13]);
        assert.deepEqual(recovered.prepare('select id, path from report_artifacts order by id').all().map(row => ({...row})), [{id: 11, path: 'reports/11.csv'}, {id: 13, path: 'reports/13.csv'}]);
    } finally {
        source.close();
        recovered.close();
    }
});

test('checkpoint encrypts application files', async () => {
    const {env, objects, checkpointObjects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    objects.set('private/reports/synthetic.csv', 'synthetic report contents');

    const result = await new StagingBackupOnly(env).create('cutover-20261030t200004z');
    assert.equal(result.files, 1);
    const encrypted = [...checkpointObjects.entries()].filter(([key]) => key.includes('/files/'));
    assert.equal(encrypted.length, 1);
    assert.doesNotMatch(encrypted[0][1], /synthetic report contents/);
});

test('checkpoint resolves a Secrets Store key once and rejects a missing key', async () => {
    const {env, objects, checkpointObjects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    objects.set('private/reports/one.csv', 'one');
    objects.set('private/reports/two.csv', 'two');
    let keyReads = 0;
    env.BACKUP_APP_KEY = {get: async () => { keyReads++; return secret; }};
    const service = new StagingBackupOnly(env);
    const result = await service.create('cutover-20261031t050000z');
    assert.equal(result.files, 2);
    assert.equal(keyReads, 1);
    env.BACKUP_APP_KEY = {get: async () => null};
    await assert.rejects(service.create('cutover-20261031t050001z'), /missing staging backup encryption key/i);
    assert.equal(checkpointObjects.has('private/staging-backups/d1-web/checkpoints/cutover-20261031t050001z/claim'), false);
});

test('checkpoint refuses success when encrypted file readback is missing or corrupt', async () => {
    for (const failure of ['missing', 'corrupt']) {
        const {env, objects, checkpointObjects} = backupFixture();
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';
        objects.set('private/reports/present.csv', 'synthetic report');
        const get = env.STAGING_CHECKPOINT_FILES.get;
        env.STAGING_CHECKPOINT_FILES.get = async key => {
            if (!key.includes('/files/')) return get(key);
            if (failure === 'missing') return null;
            const value = JSON.parse(checkpointObjects.get(key));
            value.ciphertext = (value.ciphertext[0] === 'A' ? 'B' : 'A') + value.ciphertext.slice(1);
            const bytes = new TextEncoder().encode(JSON.stringify(value));
            return {size: bytes.length, arrayBuffer: async () => bytes.buffer};
        };
        const checkpoint = 'cutover-20261031t030001z';
        await assert.rejects(new StagingBackupOnly(env).create(checkpoint));
        assert.equal(checkpointObjects.has('private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm'), false);
        assert.equal(checkpointObjects.size, 2);
        await assert.rejects(new StagingBackupOnly(env).create(checkpoint), /already exists or was attempted/i);
    }
});

test('checkpoint refuses success when database snapshot readback differs and preserves it', async () => {
    const {env, checkpointObjects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    const get = env.STAGING_CHECKPOINT_FILES.get;
    env.STAGING_CHECKPOINT_FILES.get = async key => {
        if (key.includes('/files/')) return get(key);
        const different = await sealBackup({database: 'familyfunds-d1-web-staging', altered: true}, secret);
        const bytes = new TextEncoder().encode(JSON.stringify(different.envelope));
        return {size: bytes.length, arrayBuffer: async () => bytes.buffer};
    };
    const checkpoint = 'cutover-20261031t030002z';
    const service = new StagingBackupOnly(env);
    await assert.rejects(service.create(checkpoint), /stored staging checkpoint differs/i);
    assert.equal(checkpointObjects.size, 2);
    await assert.rejects(service.create(checkpoint), /already exists/i);
});

test('checkpoint rejects a changed application file inventory before publishing the database snapshot', async () => {
    const {env, objects, checkpointObjects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    objects.set('private/reports/first.csv', 'first');
    const get = env.STAGING_FILES.get;
    env.STAGING_FILES.get = async (...args) => {
        const result = await get(...args);
        objects.set('private/reports/second.csv', 'second');
        return result;
    };

    const checkpoint = 'cutover-20261030t200005z';
    await assert.rejects(new StagingBackupOnly(env).create(checkpoint), /inventory changed/i);
    assert.equal(checkpointObjects.has('private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm'), false);
    assert.equal(objects.get('private/reports/first.csv'), 'first');
    assert.equal(objects.get('private/reports/second.csv'), 'second');
});

test('checkpoint rejects a same-content file replacement during capture', async () => {
    for (const stage of ['before-get', 'after-get']) {
        const {env, objects, checkpointObjects} = backupFixture();
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';
        objects.set('private/reports/first.csv', 'same content');
        let version = 'fixture-version';
        const list = env.STAGING_FILES.list;
        env.STAGING_FILES.list = async (...args) => {
            const page = await list(...args);
            return {...page, objects: page.objects.map(item => ({...item, version}))};
        };
        const get = env.STAGING_FILES.get;
        env.STAGING_FILES.get = async (...args) => {
            if (stage === 'before-get') version = 'replacement-version';
            const result = {...await get(...args), version};
            if (stage === 'after-get') version = 'replacement-version';
            return result;
        };
        const checkpoint = stage === 'before-get' ? 'cutover-20261031t040001z' : 'cutover-20261031t040002z';
        await assert.rejects(new StagingBackupOnly(env).create(checkpoint), /file.*changed|inventory changed/i);
        assert.equal(checkpointObjects.has('private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm'), false);
    }
});

test('checkpoint refuses a file inventory without upload versions', async () => {
    const {env, objects, checkpointObjects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    objects.set('private/reports/first.csv', 'synthetic');
    const list = env.STAGING_FILES.list;
    env.STAGING_FILES.list = async (...args) => {
        const page = await list(...args);
        return {...page, objects: page.objects.map(item => ({...item, version: undefined}))};
    };
    const checkpoint = 'cutover-20261031t040003z';
    await assert.rejects(new StagingBackupOnly(env).create(checkpoint), /missing staging file version/i);
    assert.equal(checkpointObjects.has('private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm'), false);
});

test('checkpoint rejects an incomplete or repeating file inventory cursor', async () => {
    const {env, checkpointObjects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    let attempt = 0;
    for (const page of [{objects: [], truncated: true}, {objects: [], truncated: true, cursor: 'same'}]) {
        env.STAGING_FILES.list = async () => page;
        await assert.rejects(new StagingBackupOnly(env).create(`cutover-20261030t20220${attempt++}z`), /incomplete.*file inventory/i);
    }
    assert.equal(checkpointObjects.size, 2);
});

test('checkpoint rejects a database change made while its application files are copied', async () => {
    const db = new DatabaseSync(':memory:');
    try {
        for (let i = 0; i < 40; i++) db.exec(`create table fixture_${i} (id integer primary key autoincrement)`);
        const {env, objects, checkpointObjects} = backupFixture();
        env.DB = {prepare: sql => ({sql, all: async () => ({results: db.prepare(sql).all()})}),
            batch: async statements => statements.map(statement => ({results: db.prepare(statement.sql).all()}))};
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';
        objects.set('private/reports/fixture.csv', 'synthetic');
        const get = env.STAGING_FILES.get;
        env.STAGING_FILES.get = async (...args) => {
            const result = await get(...args);
            db.exec('insert into fixture_0 (id) values (1)');
            return result;
        };

        const checkpoint = 'cutover-20261030t202100z';
        await assert.rejects(new StagingBackupOnly(env).create(checkpoint), /database changed during checkpoint/i);
        assert.equal(checkpointObjects.has('private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm'), false);
        assert.equal(db.prepare('select count(*) n from fixture_0').get().n, 1);
    } finally {
        db.close();
    }
});

test("an integrity failure prevents publishing a backup", async () => {
    const {env, objects, corrupt} = backupFixture();
    corrupt();
    await assert.rejects(() => createBackup(env, new Date("2026-10-26T06:00:00Z")), /integrity/);
    assert.equal(objects.size, 0);
});

test("a failed queue send leaves the durable outbox eligible for retry", async () => {
    const {env} = backupFixture();
    env.STAGING_FILES.head = async () => true;
    const task = {id: "generate:2026-09", kind: "generate", family_id: 50, at: "2026-09-01T00:00:00.000Z"};
    const updates = [];
    env.PROOF_AS_OF = "2026-09-28T08:00:00.000Z";
    env.DB.prepare = sql => ({sql, bind() { return this; }, first: async () => ({due: 0}), all: async () => ({results: [task]}), run: async () => { updates.push(sql); }});
    env.DB.batch = async () => [];
    env.MAINTENANCE_QUEUE = {send: async () => { throw new Error("Queue unavailable"); }};
    await assert.rejects(() => handler.scheduled({scheduledTime: Date.parse("2026-10-26T06:00:00Z")}, env), /Queue unavailable/);
    assert.equal(updates.length, 0);
});

test("the consumer retries failed native execution and skips completed duplicates", async () => {
    const task = {id: "generate:2026-09", kind: "generate", family_id: 50, at: "2026-09-01T00:00:00.000Z"};
    for (const completed of [false, true]) {
        const {env} = backupFixture();
        let wakeCount = 0;
        const result = {ack: false, retry: false};
        env.DB.prepare = () => ({bind() { return this; }, first: async () => ({kind: task.kind, family_id: 50, due_at: task.at, completed_at: completed ? 1 : null})});
        env.STAGING_CONTAINER = {getByName: () => ({scheduledProof: async () => { wakeCount++; throw new Error("Native interruption"); }})};
        await handler.queue({messages: [{id: "fixture", body: task, ack: () => { result.ack = true; }, retry: () => { result.retry = true; }}]}, env);
        assert.equal(result.ack, completed);
        assert.equal(result.retry, !completed);
        assert.equal(wakeCount, completed ? 0 : 1);
    }
});

test("maintenance has no public execution or data endpoint", async () => {
    assert.equal((await handler.fetch(new Request("https://staging.example/run"))).status, 404);
});
