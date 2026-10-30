import assert from "node:assert/strict";
import {webcrypto} from "node:crypto";
import {readFileSync} from "node:fs";
import {DatabaseSync} from "node:sqlite";
import {test} from "node:test";
import {runInNewContext} from "node:vm";

const source = readFileSync(new URL("./maintenance-worker.mjs", import.meta.url), "utf8").replace(/^import .*;\n/gm, "").replace("export default ", "const handler = ").replaceAll("export class ", "class ");
const {handler, StagingBackupOnly, StagingRecoveryOnly, calendarTasks, sealBackup, backupKey, createBackup, backupSlot} = runInNewContext(source + "\n({handler, StagingBackupOnly, StagingRecoveryOnly, calendarTasks, sealBackup, backupKey, createBackup, backupSlot})", {
    WorkerEntrypoint: class {constructor(env) { this.env = env; }}, crypto: webcrypto, TextEncoder, TextDecoder, Response, btoa, atob, console,
});
const secret = "base64:" + Buffer.alloc(32, 7).toString("base64");

function backupFixture() {
    const objects = new Map();
    const schema = Array.from({length: 40}, (_, i) => ({type: "table", name: "table_" + i, tbl_name: "table_" + i, sql: "create table table_" + i + "(id integer)"}));
    let badIntegrity = false;
    let reads = 0;
    const env = {
        DATABASE_NAME: "familyfunds-d1-web-staging", ENABLED: "true", MAINTENANCE_FAMILY_ID: "50", PURGE_FAMILY_ID: "51", BACKUP_APP_KEY: secret,
        STAGING_FILES: {head: async key => objects.has(key), get: async key => { const value = objects.get(key); if (value === undefined) return null; const bytes = new TextEncoder().encode(value); return {size: bytes.length, arrayBuffer: async () => bytes.buffer}; }, put: async (key, body, options) => { if (options.onlyIf?.etagDoesNotMatch === '*' && objects.has(key)) return null; objects.set(key, body); return {key}; }},
        DB: {
            prepare: sql => ({sql, all: async () => { reads++; return {results: schema}; }}),
            batch: async statements => statements.map(statement => ({results: statement.sql.startsWith("select *") ? [{id: 1, body: "private fixture"}]
                : statement.sql.includes("sqlite_sequence") ? [] : statement.sql === "pragma foreign_key_check" ? badIntegrity ? [{table: "broken"}] : []
                    : statement.sql === "pragma quick_check" ? [{quick_check: "ok"}] : schema})),
        },
    };
    return {env, objects, reads: () => reads, corrupt: () => { badIntegrity = true; }};
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
    const {env, objects} = backupFixture();
    const service = new StagingBackupOnly(env);
    const checkpoint = 'cutover-20261030t194500z';
    await assert.rejects(service.create(checkpoint), /disabled/i);
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    for (const value of [null, '../other', '2026-10-30']) await assert.rejects(service.create(value), /checkpoint/i);
    env.DATABASE_NAME = 'familyfunds-production';
    await assert.rejects(service.create(checkpoint), /disabled/i);
    env.DATABASE_NAME = 'familyfunds-d1-web-staging';
    assert.equal(objects.size, 0);

    const result = await service.create(checkpoint);
    assert.equal(result.status, 'created');
    assert.equal(result.key, 'private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm');
    assert.equal(objects.size, 1);
    const envelope = JSON.parse(objects.get(result.key));
    const options = {name: 'AES-GCM', iv: Buffer.from(envelope.iv, 'base64'), additionalData: new TextEncoder().encode('FamilyFunds staging backup v1:familyfunds-d1-web-staging')};
    const snapshot = JSON.parse(new TextDecoder().decode(await webcrypto.subtle.decrypt(options, await backupKey(secret), Buffer.from(envelope.ciphertext, 'base64'))));
    assert.equal(snapshot.database, 'familyfunds-d1-web-staging');
    assert.equal(Object.keys(snapshot.tables).length, 40);
    await assert.rejects(service.create(checkpoint), /already exists/i);
    assert.equal(objects.size, 1);
});

test('a competing checkpoint is preserved when it appears after the initial check', async () => {
    const {env, objects} = backupFixture();
    env.STAGING_BACKUP_ONLY_ENABLED = 'true';
    const put = env.STAGING_FILES.put;
    env.STAGING_FILES.put = async (key, body, options) => {
        objects.set(key, 'competing checkpoint');
        return put(key, body, options);
    };

    await assert.rejects(new StagingBackupOnly(env).create('cutover-20261030t194500z'), /already exists/i);
    assert.equal([...objects.values()][0], 'competing checkpoint');
});

test('checkpoint snapshots include application-owned D1 runtime tables and revision triggers', async () => {
    const db = new DatabaseSync(':memory:');
    try {
        for (let i = 0; i < 40; i++) db.exec(`create table fixture_${i} (id integer primary key autoincrement)`);
        db.exec('create table d1_staging_sequences (name text primary key, next_id integer); insert into d1_staging_sequences values (\'payment\', 7)');
        db.exec('create trigger d1_read_revision_fixture_0_insert after insert on fixture_0 begin select 1; end');
        const {env, objects} = backupFixture();
        env.DB = {prepare: sql => ({sql, all: async () => ({results: db.prepare(sql).all()})}),
            batch: async statements => statements.map(statement => ({results: db.prepare(statement.sql).all()}))};
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';
        const result = await new StagingBackupOnly(env).create('cutover-20261030t194501z');
        const envelope = JSON.parse(objects.get(result.key));
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
        const {env, objects} = backupFixture();
        env.DB = adapter(source);
        env.RECOVERY_DB = adapter(recovered);
        env.STAGING_BACKUP_ONLY_ENABLED = 'true';
        const service = new StagingBackupOnly(env);
        const checkpoint = 'cutover-20261030t200001z';
        await service.create(checkpoint);
        const restore = new StagingRecoveryOnly(env);
        await assert.rejects(restore.restore(checkpoint), /disabled/i);
        env.STAGING_RESTORE_ONLY_ENABLED = 'true';
        env.STAGING_RECOVERY_NAME = 'familyfunds-production';
        await assert.rejects(restore.restore(checkpoint), /wrong staging recovery target/i);
        env.STAGING_RECOVERY_NAME = 'familyfunds-d1-staging-recovery';
        const key = 'private/staging-backups/d1-web/checkpoints/' + checkpoint + '.json.aes-gcm';
        const original = objects.get(key);
        const envelope = JSON.parse(original);
        envelope.ciphertext = (envelope.ciphertext[0] === 'A' ? 'B' : 'A') + envelope.ciphertext.slice(1);
        objects.set(key, JSON.stringify(envelope));
        await assert.rejects(restore.restore(checkpoint));
        assert.equal(recovered.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*'").get().n, 0);
        objects.set(key, original);

        const result = await restore.restore(checkpoint);
        assert.equal(result.status, 'passed');
        assert.equal(result.tables, 41);
        assert.equal(recovered.prepare('select name from fixture_0 where id=8').get().name, 'synthetic');
        recovered.exec("insert into fixture_0(name) values ('next')");
        assert.equal(recovered.prepare('select revision from d1_staging_read_revision where id=1').get().revision, 5);
        assert.equal(recovered.prepare("select seq from sqlite_sequence where name='fixture_0'").get().seq, 9);
        await assert.rejects(restore.restore(checkpoint), /empty/i);
    } finally {
        source.close();
        recovered.close();
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
