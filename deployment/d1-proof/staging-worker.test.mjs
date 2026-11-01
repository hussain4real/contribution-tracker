import assert from 'node:assert/strict';
import {randomUUID, timingSafeEqual} from 'node:crypto';
import {readFileSync} from 'node:fs';
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
