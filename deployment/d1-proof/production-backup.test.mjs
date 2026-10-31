import assert from "node:assert/strict";
import test from "node:test";
import {BACKUP_PREFIX, cleanProductionBackups, createProductionBackup, listProductionObjects, openProductionBackup, productionBackupSlot, productionRetention, sealProductionBackup} from "./production-backup.mjs";

const secret = "base64:" + Buffer.alloc(32, 7).toString("base64");
const at = new Date("2026-10-26T05:00:00Z");

test("production database and file backups authenticate identity, content and the independent key", async () => {
    const bytes = new TextEncoder().encode("private fixture financial data");
    const first = await sealProductionBackup(bytes, secret, "database");
    const second = await sealProductionBackup(bytes, secret, "database");
    assert.notEqual(first.iv, second.iv);
    assert.ok(!JSON.stringify(first).includes("private fixture"));
    assert.deepEqual(await openProductionBackup(first, secret), bytes);
    await assert.rejects(() => openProductionBackup(first, "base64:" + Buffer.alloc(32, 8).toString("base64")));
    await assert.rejects(() => openProductionBackup({...first, database: "familyfunds-d1-web-staging"}, secret));
    await assert.rejects(() => openProductionBackup({...first, kind: "file:" + "a".repeat(64)}, secret));
    const ciphertext = Buffer.from(first.ciphertext, "base64"); ciphertext[0] ^= 1;
    await assert.rejects(() => openProductionBackup({...first, ciphertext: ciphertext.toString("base64")}, secret));
});

test("production backup slots follow the existing 02:30 Qatar schedule", () => {
    assert.equal(productionBackupSlot(new Date("2026-10-25T23:29:59Z")), "2026-10-25");
    assert.equal(productionBackupSlot(new Date("2026-10-25T23:30:00Z")), "2026-10-26");
});

test('production backup rejects a truncated object listing without a continuation cursor', async () => {
    await assert.rejects(listProductionObjects({list: async () => ({objects: [], truncated: true})}, 'private/'), /incomplete.*listing/i);
    await assert.rejects(listProductionObjects({list: async () => ({objects: [], truncated: true, cursor: 'same'})}, 'private/'), /incomplete.*listing/i);
});

test("retention keeps daily history then eight weeks then six additional months and always the latest", () => {
    const objects = Array.from({length: 400}, (_, day) => ({key: BACKUP_PREFIX + "d1/" + new Date(at.getTime() - day * 86400000).toISOString().slice(0, 10) + ".json.aes-gcm", size: 1000}));
    const selected = productionRetention(objects, at);
    assert.ok(selected.keep.includes(objects[0].key));
    for (let day = 0; day < 14; day++) assert.ok(selected.keep.includes(objects[day].key));
    assert.ok(selected.keep.length > 20 && selected.keep.length < 40);
    assert.ok(selected.remove.includes(objects[399].key));
    assert.throws(() => productionRetention([{key: "unrelated/private-file", size: 1}], at));
    const oversized = objects.slice(0, 3).map(object => ({...object, size: 60_000 * 1024 * 1024}));
    assert.deepEqual(productionRetention(oversized, at).keep, [objects[0].key]);
});

function fixture() {
    const objects = new Map();
    let reads = 0, integrity = true;
    const schema = [...Array.from({length: 40}, (_, i) => ({type: "table", name: "table_" + i, sql: "create table table_" + i + "(id integer)"})),
        {type: "table", name: "report_artifacts", sql: "create table report_artifacts(id integer,disk text,path text)"}];
    const bytes = Buffer.from("private application PDF fixture");
    objects.set("private/reports/fixture.pdf", {bytes, etag: "source-etag", version: "source-version", uploaded: at,
        httpMetadata: {contentType: 'application/pdf', cacheExpiry: new Date('2026-12-01T00:00:00.000Z')}, customMetadata: {kind: 'synthetic'}});
    const describe = (key, object) => ({key, size: object.bytes.length, etag: object.etag, version: object.version, uploaded: object.uploaded,
        httpMetadata: object.httpMetadata, customMetadata: object.customMetadata});
    const appObjects = new Map(objects);
    objects.clear();
    const env = {
        DATABASE_NAME: "familyfunds-production", BACKUPS_ENABLED: "true", BACKUP_KEY: secret,
        DB: {
            prepare: sql => ({sql, all: async () => { reads++; return {results: schema}; }}),
            batch: async statements => statements.map(({sql}) => {
                if (sql.startsWith('select * from "report_artifacts"')) return {results: [{id: 1, disk: "local", path: "reports/fixture.pdf"}]};
                if (sql.startsWith("select *")) return {results: [{id: 1}]};
                if (sql.includes("sqlite_sequence")) return {results: [{name: "report_artifacts", seq: 1}]};
                if (sql === "pragma foreign_key_check") return {results: integrity ? [] : [{table: "broken"}]};
                if (sql === "pragma quick_check") return {results: [{quick_check: "ok"}]};
                return {results: schema};
            }),
        },
        BACKUP_FILES: {
            list: async ({prefix}) => ({truncated: false, objects: [...objects].filter(([key]) => key.startsWith(prefix)).map(([key, object]) => describe(key, object))}),
            head: async key => objects.has(key) ? describe(key, objects.get(key)) : null,
            get: async key => {
                const object = objects.get(key);
                return object ? {...describe(key, object), body: new Response(object.bytes).body, text: async () => object.bytes.toString(), arrayBuffer: async () => object.bytes.buffer.slice(object.bytes.byteOffset, object.bytes.byteOffset + object.bytes.byteLength)} : null;
            },
            put: async (key, value) => {
                if (objects.has(key)) return null;
                objects.set(key, {bytes: Buffer.from(value), etag: "etag-" + objects.size, uploaded: at});
                return {};
            },
            delete: async key => objects.delete(key),
        },
    };
    env.APPLICATION_FILES = {
        list: async ({prefix}) => ({truncated: false, objects: [...appObjects].filter(([key]) => key.startsWith(prefix)).map(([key, object]) => describe(key, object))}),
        get: async key => {
            const object = appObjects.get(key);
            return object ? {...describe(key, object), body: new Response(object.bytes).body, arrayBuffer: async () => object.bytes.buffer.slice(object.bytes.byteOffset, object.bytes.byteOffset + object.bytes.byteLength)} : null;
        },
    };
    return {env, objects, appObjects, bytes, reads: () => reads, corrupt: () => { integrity = false; }};
}

test("a production snapshot recovers its private PDF through an authenticated file manifest", async () => {
    const f = fixture();
    const result = await createProductionBackup(f.env, at);
    assert.equal(result.status, "created"); assert.equal(result.files, 1); assert.equal(result.tables, 41);
    const database = JSON.parse(new TextDecoder().decode(await openProductionBackup(JSON.parse(f.objects.get(result.key).bytes.toString()), secret)));
    assert.equal(database.tables.report_artifacts[0].id, 1);
    assert.equal(database.files[0].path, "private/reports/fixture.pdf");
    assert.equal(database.files[0].http_metadata, undefined);
    const file = database.files[0];
    const recovered = await openProductionBackup(JSON.parse(f.objects.get(file.backup_key).bytes.toString()), secret);
    assert.deepEqual(Buffer.from(recovered), f.bytes);
    for (const [key, value] of f.objects) if (key.startsWith(BACKUP_PREFIX)) assert.ok(!value.bytes.toString().includes("private application PDF"));
    const reads = f.reads();
    assert.equal((await createProductionBackup(f.env, at)).status, "already_present");
    assert.equal(f.reads(), reads);
});

test('cutover checkpoints capture fresh data without replacing an immutable daily backup', async () => {
    const f = fixture();
    const daily = await createProductionBackup(f.env, at);
    const old = f.objects.get(daily.key).bytes.toString();
    const checkpoint = await createProductionBackup(f.env, at, 'cutover-20261027t140000z');
    assert.equal(checkpoint.status, 'created');
    assert.notEqual(checkpoint.key, daily.key);
    assert.equal(f.objects.get(daily.key).bytes.toString(), old);
    const snapshot = JSON.parse(new TextDecoder().decode(await openProductionBackup(JSON.parse(f.objects.get(checkpoint.key).bytes), secret)));
    assert.ok(snapshot.files[0].backup_key.includes('/checkpoints/cutover-20261027t140000z/files/'));
    assert.deepEqual(snapshot.files[0].http_metadata, {contentType: 'application/pdf', cacheExpiry: '2026-12-01T00:00:00.000Z'});
    assert.deepEqual(snapshot.files[0].custom_metadata, {kind: 'synthetic'});
    await assert.rejects(createProductionBackup(f.env, at, 'cutover-20261027t140000z'), /checkpoint already exists/i);
    await assert.rejects(createProductionBackup(f.env, new Date('2026-10-27T05:00:00Z'), 'cutover-20261027t140000z'), /checkpoint already exists/i);
    await assert.rejects(() => createProductionBackup(f.env, at, '../escape'));
});

test('a cutover checkpoint refuses database changes during file capture', async () => {
    const f = fixture();
    const batch = f.env.DB.batch;
    let calls = 0;
    f.env.DB.batch = async statements => {
        const result = await batch(statements);
        if (++calls === 2) result[0].results = [{id: 2}];
        return result;
    };
    const checkpoint = 'cutover-20261027t140001z';

    await assert.rejects(createProductionBackup(f.env, at, checkpoint), /database changed during checkpoint/i);
    assert.equal(f.objects.has(BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/d1/' + productionBackupSlot(at) + '.json.aes-gcm'), false);
});

test('a cutover checkpoint refuses file inventory changes during capture', async () => {
    const f = fixture();
    const get = f.env.APPLICATION_FILES.get;
    f.env.APPLICATION_FILES.get = async (...args) => {
        const object = await get(...args);
        f.appObjects.set('private/reports/added.pdf', {bytes: Buffer.from('new fixture'), etag: 'new-etag', uploaded: at});
        return object;
    };
    const checkpoint = 'cutover-20261027t140002z';

    await assert.rejects(createProductionBackup(f.env, at, checkpoint), /file inventory changed during checkpoint/i);
    assert.equal(f.objects.has(BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/d1/' + productionBackupSlot(at) + '.json.aes-gcm'), false);
});

test('a cutover checkpoint refuses a same-content file replacement during capture', async () => {
    for (const stage of ['before-get', 'after-get']) {
        const f = fixture();
        const get = f.env.APPLICATION_FILES.get;
        f.env.APPLICATION_FILES.get = async (...args) => {
            if (stage === 'before-get') f.appObjects.get('private/reports/fixture.pdf').version = 'replacement-version';
            const object = await get(...args);
            if (stage === 'after-get') f.appObjects.get('private/reports/fixture.pdf').version = 'replacement-version';
            return object;
        };
        const checkpoint = stage === 'before-get' ? 'cutover-20261031t050001z' : 'cutover-20261031t050002z';
        await assert.rejects(createProductionBackup(f.env, at, checkpoint), /file.*changed/i);
        assert.equal(f.objects.has(BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/d1/' + productionBackupSlot(at) + '.json.aes-gcm'), false);
    }
});

test('a cutover checkpoint refuses an inventory without file versions', async () => {
    const f = fixture();
    delete f.appObjects.get('private/reports/fixture.pdf').version;
    await assert.rejects(createProductionBackup(f.env, at, 'cutover-20261031t050003z'), /file version missing/i);
    assert.equal((await createProductionBackup(f.env, at)).status, 'created');
});

test('a cutover checkpoint refuses an unreadable encrypted file before publishing its database', async () => {
    const f = fixture();
    const put = f.env.BACKUP_FILES.put;
    f.env.BACKUP_FILES.put = async (key, value, options) => {
        const stored = await put(key, value, options);
        if (key.includes('/files/')) {
            const envelope = JSON.parse(value);
            envelope.ciphertext = (envelope.ciphertext[0] === 'A' ? 'B' : 'A') + envelope.ciphertext.slice(1);
            f.objects.get(key).bytes = Buffer.from(JSON.stringify(envelope));
        }
        return stored;
    };
    const checkpoint = 'cutover-20261027t140003z';

    await assert.rejects(createProductionBackup(f.env, at, checkpoint));
    assert.equal(f.objects.has(BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/d1/' + productionBackupSlot(at) + '.json.aes-gcm'), false);
    await assert.rejects(createProductionBackup(f.env, at, checkpoint), /checkpoint already exists/i);
});

test('a cutover checkpoint reports an unreadable published database instead of success', async () => {
    const f = fixture();
    const put = f.env.BACKUP_FILES.put;
    f.env.BACKUP_FILES.put = async (key, value, options) => {
        const stored = await put(key, value, options);
        if (key.includes('/d1/')) {
            const envelope = JSON.parse(value);
            envelope.ciphertext = (envelope.ciphertext[0] === 'A' ? 'B' : 'A') + envelope.ciphertext.slice(1);
            f.objects.get(key).bytes = Buffer.from(JSON.stringify(envelope));
        }
        return stored;
    };
    const checkpoint = 'cutover-20261027t140004z';

    await assert.rejects(createProductionBackup(f.env, at, checkpoint));
    assert.equal(f.objects.has(BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/d1/' + productionBackupSlot(at) + '.json.aes-gcm'), true);
    await assert.rejects(createProductionBackup(f.env, at, checkpoint), /checkpoint already exists/i);
});

test('a racing cutover checkpoint cannot replace or report another snapshot as new', async () => {
    const f = fixture();
    const put = f.env.BACKUP_FILES.put;
    f.env.BACKUP_FILES.put = async (key, value, options) => {
        if (key.includes('/d1/')) f.objects.set(key, {bytes: Buffer.from('competing checkpoint'), etag: 'other', uploaded: at});
        return put(key, value, options);
    };
    const checkpoint = 'cutover-20261027t140005z';
    const key = BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/d1/' + productionBackupSlot(at) + '.json.aes-gcm';

    await assert.rejects(createProductionBackup(f.env, at, checkpoint), /checkpoint already exists/i);
    assert.equal(f.objects.get(key).bytes.toString(), 'competing checkpoint');
});

test("a database-referenced missing file prevents publishing a successful backup", async () => {
    const f = fixture(); f.appObjects.delete("private/reports/fixture.pdf");
    await assert.rejects(() => createProductionBackup(f.env, at), /missing/);
    assert.ok(![...f.objects.keys()].some(key => key.startsWith(BACKUP_PREFIX + "d1/")));
});

test("a database integrity failure prevents publishing a snapshot or file blobs", async () => {
    const f = fixture(); f.corrupt();
    await assert.rejects(() => createProductionBackup(f.env, at), /integrity/);
    assert.equal(f.objects.size, 0); assert.equal(f.appObjects.size, 1);
});

test("cleanup validates retained database and file backups before deleting history", async () => {
    const f = fixture();
    const result = await createProductionBackup(f.env, at);
    assert.deepEqual(await cleanProductionBackups(f.env, at), {retained: 1, removed: 0});
    const oldKey = BACKUP_PREFIX + "d1/2025-01-01.json.aes-gcm";
    f.objects.set(oldKey, {...f.objects.get(result.key)});
    f.objects.get(result.key).bytes = Buffer.from('{"version":1,"database":"tampered"}');
    await assert.rejects(() => cleanProductionBackups(f.env, at));
    assert.ok(f.objects.has(oldKey));
});

test("backup creation refuses the staging database or disabled preparation", async () => {
    const f = fixture(); f.env.BACKUPS_ENABLED = "false";
    await assert.rejects(() => createProductionBackup(f.env, at));
    f.env.BACKUPS_ENABLED = "true"; f.env.DATABASE_NAME = "familyfunds-d1-web-staging";
    await assert.rejects(() => createProductionBackup(f.env, at));
});
