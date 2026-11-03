import assert from 'node:assert/strict';
import {randomUUID, timingSafeEqual} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import {test} from 'node:test';
import {runInNewContext} from 'node:vm';

const source = readFileSync(new URL('./staging-worker.mjs', import.meta.url), 'utf8')
    .replace('import { DurableObject } from "cloudflare:workers";', 'class DurableObject { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }')
    .replace('export class ', 'class ').replace('export default ', 'const handler = ');
const {handler, Gate} = runInNewContext(source + '\n({handler, Gate: StagingGatewayAdmission})', {
    Response, Request, URL, TextEncoder, TextDecoder, crypto: {randomUUID, subtle: {timingSafeEqual}},
});

function fixture() {
    const data = new Map();
    const storage = {
        get: async key => data.get(key),
        put: async (key, value) => { data.set(key, value); },
        delete: async key => data.delete(key),
        list: async ({prefix, limit}) => new Map([...data].filter(([key]) => key.startsWith(prefix)).slice(0, limit)),
    };
    const gate = new Gate({storage, blockConcurrencyWhile: callback => callback()}, {});
    const env = {PROOF_TOKEN: 'fixture', DATABASE_NAME: 'familyfunds-d1-web-staging', STAGING_GATEWAY_ADMISSION: '1',
        STAGING_GATEWAY_ADMIN_TOKEN: 'a'.repeat(32), STAGING_GATEWAY_ADMISSION_OBJECT: {getByName: () => gate},
        DB: {prepare: () => ({bind: () => ({all: async () => ({results: []})})})}};
    const query = () => new Request('https://staging.test/query', {method: 'POST', headers: {Authorization: 'Bearer fixture'}, body: JSON.stringify({sql: 'select 1', params: []})});
    const admin = (method, paused, token = 'a'.repeat(32)) => new Request(`https://staging.test/__staging_admin/admission${paused === undefined ? '' : `?paused=${paused}`}`, {method, headers: {Authorization: 'Bearer fixture', 'X-Staging-Gateway-Admin': token}});
    return {gate, env, query, admin, storage};
}

test('staging gateway blocks new operations after pause and tracks an in-flight operation', async () => {
    const f = fixture();
    let finish;
    let started;
    const running = new Promise(resolve => { started = resolve; });
    f.env.DB.prepare = () => ({bind: () => ({all: async () => { started(); return new Promise(resolve => { finish = resolve; }); }})});
    const first = handler.fetch(f.query(), f.env);
    await running;
    assert.equal((await handler.fetch(f.admin('POST', true, 'wrong'), f.env)).status, 403);
    const paused = await handler.fetch(f.admin('POST', true), f.env);
    assert.deepEqual(await paused.json(), {paused: true, unresolved: 1});
    assert.equal((await handler.fetch(f.query(), f.env)).status, 503);
    finish({results: []});
    assert.equal((await first).status, 200);
    assert.deepEqual(await (await handler.fetch(f.admin('GET'), f.env)).json(), {paused: true, unresolved: 0});
    assert.equal((await handler.fetch(f.admin('POST', false), f.env)).status, 200);
});

test('a batch committed before a lost acknowledgement keeps the paused gateway unresolved', async t => {
    const f = fixture();
    const db = new DatabaseSync(':memory:');
    t.after(() => db.close());
    db.exec('create table entries (id integer primary key)');
    const committed = Promise.withResolvers();
    const acknowledgement = Promise.withResolvers();
    f.env.DB.prepare = sql => ({bind: (...params) => () => db.prepare(sql).run(...params)});
    f.env.DB.batch = async statements => {
        db.exec('begin');
        for (const execute of statements) execute();
        db.exec('commit');
        committed.resolve();
        await acknowledgement.promise;
    };
    const batch = () => new Request('https://staging.test/batch', {method: 'POST',
        headers: {Authorization: 'Bearer fixture'}, body: JSON.stringify({batch:
            [1, 2].map(id => ({sql: 'insert into entries values (?)', params: [id]}))})});

    const first = handler.fetch(batch(), f.env);
    await committed.promise;
    const paused = await handler.fetch(f.admin('POST', true), f.env);
    assert.deepEqual(await paused.json(), {paused: true, unresolved: 1});
    assert.equal((await handler.fetch(batch(), f.env)).status, 503);
    acknowledgement.reject(new Error('Lost D1 response'));
    const uncertain = await first;
    assert.equal(uncertain.status, 422);
    assert.match(uncertain.headers.get('X-Staging-Gateway-Operation'), /^[a-f0-9-]{36}$/);
    assert.equal(db.prepare('select count(*) as n from entries').get().n, 2);
    assert.deepEqual(await (await handler.fetch(f.admin('GET'), f.env)).json(), {paused: true, unresolved: 1});
});

test('uncertain database outcomes remain unresolved after coordinator reconstruction', async () => {
    const f = fixture();
    f.env.DB.prepare = () => ({bind: () => ({all: async () => { throw new Error('Synthetic unknown outcome'); }})});
    const failure = await handler.fetch(f.query(), f.env);
    assert.equal(failure.status, 422);
    const id = failure.headers.get('X-Staging-Gateway-Operation');
    assert.match(id, /^[a-f0-9-]{36}$/);
    const restored = new Gate({storage: f.storage, blockConcurrencyWhile: callback => callback()}, {});
    assert.equal((await restored.setPaused(true)).unresolved, 1);
    assert.equal(await restored.begin(), null);
    f.env.STAGING_GATEWAY_ADMISSION_OBJECT = {getByName: () => restored};
    const details = token => new Request('https://staging.test/__staging_admin/admission?details=1', {headers: {Authorization: 'Bearer fixture', 'X-Staging-Gateway-Admin': token}});
    assert.equal((await handler.fetch(details('wrong'), f.env)).status, 403);
    const inspection = await handler.fetch(details('a'.repeat(32)), f.env);
    assert.equal(inspection.headers.get('Cache-Control'), 'no-store');
    const report = await inspection.json();
    assert.equal(report.paused, true);
    assert.equal(report.unresolved, 1);
    assert.equal(report.operations[0].id, id);
    assert.equal(typeof report.operations[0].created_at_ms, 'number');
    assert.deepEqual(Object.keys(report.operations[0]).sort(), ['created_at_ms', 'id']);
    assert.equal(report.truncated, false);
});

test('gateway admission is opt-in and refuses a different database target', async () => {
    const f = fixture();
    f.env.STAGING_GATEWAY_ADMISSION = '0';
    assert.equal((await handler.fetch(f.query(), f.env)).status, 200);
    assert.equal((await handler.fetch(f.admin('POST', true), f.env)).status, 404);
    f.env.STAGING_GATEWAY_ADMISSION = '1';
    f.env.DATABASE_NAME = 'familyfunds-production';
    assert.equal((await handler.fetch(f.admin('POST', true), f.env)).status, 404);
    assert.equal((await handler.fetch(f.query(), f.env)).status, 422);
});

test('staging gateway rejects missing credentials before D1 access', async () => {
    for (const token of [undefined, null, '', '   ']) {
        const f = fixture();
        f.env.PROOF_TOKEN = token;
        const request = f.query();
        request.headers.set('Authorization', 'Bearer ' + token);
        let calls = 0;
        f.env.DB.prepare = () => { calls++; throw new Error('Unexpected D1 access'); };
        assert.equal((await handler.fetch(request, f.env)).status, 401);
        assert.equal(calls, 0);
    }
});

test('staging gateway refuses writes when admission configuration is ambiguous', async () => {
    for (const mode of ['true', '', '01', undefined, '1']) {
        const f = fixture();
        f.env.STAGING_GATEWAY_ADMISSION = mode;
        if (mode === '1') delete f.env.STAGING_GATEWAY_ADMISSION_OBJECT;
        let calls = 0;
        f.env.DB.prepare = () => { calls++; throw new Error('Unexpected D1 access'); };
        const response = await handler.fetch(f.query(), f.env);
        assert.equal(response.status, 503);
        assert.equal(calls, 0);
    }
    const legacy = fixture();
    delete legacy.env.STAGING_GATEWAY_ADMISSION;
    delete legacy.env.STAGING_GATEWAY_ADMISSION_OBJECT;
    assert.equal((await handler.fetch(legacy.query(), legacy.env)).status, 200);
});

test('gateway investigation metadata is bounded and reports truncation', async () => {
    const f = fixture();
    for (let index = 0; index < 1001; index++) {
        const id = `00000000-0000-4000-8000-${String(index).padStart(12, '0')}`;
        await f.storage.put(`operation:${id}`, 123);
    }
    const inspection = await f.gate.inspectOperations();
    assert.equal(inspection.unresolved, 1001);
    assert.equal(inspection.operations.length, 1000);
    assert.equal(inspection.truncated, true);
});

test('gateway status cannot combine a stale count with a newer paused state', async () => {
    const f = fixture();
    const list = f.storage.list;
    let releaseList;
    let notifyList;
    const listStarted = new Promise(resolve => { notifyList = resolve; });
    f.storage.list = async options => {
        f.storage.list = list;
        const snapshot = await list(options);
        notifyList();
        await new Promise(resolve => { releaseList = resolve; });
        return snapshot;
    };
    let tail = Promise.resolve();
    const gate = new Gate({storage: f.storage, blockConcurrencyWhile: callback => {
        const next = tail.then(callback);
        tail = next.catch(() => {});
        return next;
    }}, {});
    const status = gate.status();
    await listStarted;
    const operation = gate.begin();
    const pause = gate.setPaused(true);
    await new Promise(resolve => setImmediate(resolve));
    releaseList();
    assert.deepEqual(JSON.parse(JSON.stringify(await status)), {paused: false, unresolved: 0});
    const id = await operation;
    assert.deepEqual(JSON.parse(JSON.stringify(await pause)), {paused: true, unresolved: 1});
    await gate.complete(id);
});

test('storage writes share the pause and require their own acknowledgement after restart', async () => {
    const f = fixture();
    const request = (path, headers = {}) => handler.fetch(new Request('https://fixture/storage-write/' + path,
        {method: 'POST', headers: {Authorization: 'Bearer fixture', ...headers}}), f.env);
    const begin = () => request('begin');
    const complete = (id, receipt) => request('complete', {'X-Storage-Operation': id, 'X-Storage-Receipt': receipt});
    assert.equal((await request('begin', {Authorization: 'Bearer wrong'})).status, 401);
    const sqlId = await f.gate.begin();
    const response = await begin();
    assert.equal(response.status, 200);
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    const first = await response.json();
    const lost = await (await begin()).json();
    assert.deepEqual(await (await handler.fetch(f.admin('POST', true), f.env)).json(), {paused: true, unresolved: 3});
    const restored = new Gate({storage: f.storage, blockConcurrencyWhile: callback => callback()}, {});
    f.env.STAGING_GATEWAY_ADMISSION_OBJECT = {getByName: () => restored};
    assert.equal((await begin()).status, 503);
    assert.equal((await complete(sqlId, first.receipt)).status, 409);
    assert.equal((await complete(lost.id, first.receipt)).status, 409);
    assert.equal((await complete(first.id, first.receipt)).status, 204);
    assert.equal((await complete(first.id, first.receipt)).status, 409);
    const inspected = await restored.inspectOperations();
    assert.equal(inspected.unresolved, 2);
    assert.ok(inspected.operations.some(operation => operation.id === lost.id));
    assert.ok(!JSON.stringify(inspected).includes(lost.receipt));
    f.env.STAGING_GATEWAY_ADMISSION = '0';
    assert.equal((await begin()).status, 404);
    f.env.STAGING_GATEWAY_ADMISSION = '1';
    f.env.DATABASE_NAME = 'familyfunds-production';
    assert.equal((await begin()).status, 404);
});

test('replacement gateway cannot serve SQL with admission disabled', async () => {
    const f = fixture();
    f.env.DATABASE_NAME = 'familyfunds-d1-staging-clone-20261103t092540z';
    f.env.STAGING_GATEWAY_ADMISSION = '0';
    assert.equal((await handler.fetch(f.query(), f.env)).status, 503);
    f.env.STAGING_GATEWAY_ADMISSION = '1';
    assert.equal((await handler.fetch(f.query(), f.env)).status, 200);
    assert.equal((await handler.fetch(f.admin('POST', true), f.env)).status, 200);
    assert.equal((await handler.fetch(f.query(), f.env)).status, 503);
});
