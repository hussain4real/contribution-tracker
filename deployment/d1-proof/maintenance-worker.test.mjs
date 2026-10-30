import assert from "node:assert/strict";
import {webcrypto} from "node:crypto";
import {readFileSync} from "node:fs";
import {test} from "node:test";
import {runInNewContext} from "node:vm";

const source = readFileSync(new URL("./maintenance-worker.mjs", import.meta.url), "utf8").replace("export default ", "const handler = ");
const {handler, calendarTasks, sealBackup, backupKey, createBackup, backupSlot} = runInNewContext(source + "\n({handler, calendarTasks, sealBackup, backupKey, createBackup, backupSlot})", {
    crypto: webcrypto, TextEncoder, TextDecoder, Response, btoa, atob, console,
});
const secret = "base64:" + Buffer.alloc(32, 7).toString("base64");

function backupFixture() {
    const objects = new Map();
    const schema = Array.from({length: 40}, (_, i) => ({type: "table", name: "table_" + i, tbl_name: "table_" + i, sql: "create table table_" + i + "(id integer)"}));
    let badIntegrity = false;
    let reads = 0;
    const env = {
        DATABASE_NAME: "familyfunds-d1-web-staging", ENABLED: "true", MAINTENANCE_FAMILY_ID: "50", PURGE_FAMILY_ID: "51", BACKUP_APP_KEY: secret,
        STAGING_FILES: {head: async key => objects.has(key), put: async (key, body) => { objects.set(key, body); return {key}; }},
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
