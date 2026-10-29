import assert from 'node:assert/strict';
import {timingSafeEqual} from 'node:crypto';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {runInNewContext} from 'node:vm';
import {getProductionContainer, PRODUCTION_CONTAINER_NAME} from './production-container-target.mjs';

function fixture() {
    const source = readFileSync(new URL('./production-candidate-proxy.mjs', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '').replace('export class ', 'class ').replace('export default ', 'const handler = ');
    const handler = runInNewContext(source + '\nhandler', {timingSafeEqual, FamilyFundsWebStagingContainer: class {}, Request, Response, URL, TextEncoder, TextDecoder, getProductionContainer});
    const calls = [];
    const target = {productionCommand: async (...args) => { calls.push(args); return {exit_code: 0}; }, inspectExistingOAuth: async token => {calls.push(['oauth', token]);return {authenticated: true};}, runtime: async () => ({synthetic: true}), restart: async () => true};
    const env = {NATIVE_AUTH: 'Basic ' + 'b'.repeat(40), NATIVE_ADMIN: 'a'.repeat(40), PREVIEW_TOKEN: 'p'.repeat(40),
        PRODUCTION_CONTAINER: {getByName: (name, options) => { assert.equal(name, PRODUCTION_CONTAINER_NAME); assert.equal(options.locationHint, 'weur'); return target; }},
        RUNTIME: {fetch: async request => { calls.push(request); return new Response('application'); }}};
    return {handler, env, calls};
}

test('the temporary proxy requires authentication and a separate admin secret for fixed maintenance operations', async () => {
    const f = fixture();
    const request = headers => new Request('https://capacity/__native/install', {method: 'POST', headers});
    assert.equal((await f.handler.fetch(request({}), f.env)).status, 401);
    assert.equal((await f.handler.fetch(request({Authorization: f.env.NATIVE_AUTH}), f.env)).status, 403);
    assert.equal(f.calls.length, 0);
    const response = await f.handler.fetch(request({Authorization: f.env.NATIVE_AUTH, 'X-Native-Admin': f.env.NATIVE_ADMIN}), f.env);
    assert.equal(response.status, 200); assert.deepEqual(f.calls[0], ['install']);
    assert.equal((await f.handler.fetch(new Request('https://capacity/__native/arbitrary-shell', {headers: {Authorization: f.env.NATIVE_AUTH, 'X-Native-Admin': f.env.NATIVE_ADMIN}}), f.env)).status, 404);
});

test('the temporary proxy preserves bearer authentication and streams the guarded application response', async () => {
    const f = fixture();
    const response = await f.handler.fetch(new Request('https://capacity/oauth/authorize', {headers: {'X-FamilyFunds-Preview': f.env.PREVIEW_TOKEN, Authorization: 'Bearer synthetic-access-token'}}), f.env);
    assert.equal(await response.text(), 'application');
    assert.equal(f.calls[0].headers.get('Authorization'), 'Bearer synthetic-access-token');
    assert.equal(response.headers.get('X-Robots-Tag'), 'noindex, nofollow, noarchive');
});

test('the authenticated migration proxy tests production-host routing while public routing remains disabled', async () => {
    const f = fixture(); f.env.MIGRATION_ONLY = '1';
    assert.equal((await f.handler.fetch(new Request('https://capacity/login'), f.env)).status, 401);
    const response = await f.handler.fetch(new Request('https://capacity/login?probe=1', {headers: {Authorization: f.env.NATIVE_AUTH}}), f.env);
    assert.equal(response.status, 200);
    assert.equal(f.calls[0].url, 'https://familyfunds.app/login?probe=1');
    assert.equal(f.calls[0].method, 'GET');
});

test('unknown methods and oversized task bodies never reach a native command', async () => {
    const f = fixture();
    const headers = {Authorization: f.env.NATIVE_AUTH, 'X-Native-Admin': f.env.NATIVE_ADMIN};
    assert.equal((await f.handler.fetch(new Request('https://capacity/__native/install', {headers}), f.env)).status, 404);
    assert.equal((await f.handler.fetch(new Request('https://capacity/__native/task', {method: 'POST', headers, body: 'x'.repeat(8193)}), f.env)).status, 413);
    assert.equal(f.calls.length, 0);
});

test('the real-data mirror exposes only fixed read-only inspection and lifecycle diagnostics', async () => {
    const f = fixture();
    f.env.MIGRATION_ONLY = '1';
    const headers = {Authorization: f.env.NATIVE_AUTH, 'X-Native-Admin': f.env.NATIVE_ADMIN};
    for (const path of ['install', 'task', 'proof/backup', 'proof/schedule', 'proof/restore']) {
        assert.equal((await f.handler.fetch(new Request('https://capacity/__native/' + path, {method: 'POST', headers}), f.env)).status, 404);
    }
    assert.equal(f.calls.length, 0);
    assert.equal((await f.handler.fetch(new Request('https://capacity/__native/inspect-migration', {method: 'POST', headers}), f.env)).status, 200);
    assert.deepEqual(f.calls[0], ['inspect-migration']);
});

test('private OAuth inspection bounds and validates its input before invoking the fixed guard', async () => {
    const f = fixture(); f.env.MIGRATION_ONLY = '1';
    const headers = {Authorization: f.env.NATIVE_AUTH, 'X-Native-Admin': f.env.NATIVE_ADMIN};
    const send = (body, h = headers) => f.handler.fetch(new Request('https://capacity/__native/inspect-oauth', {method: 'POST', headers: h, body}), f.env);
    assert.equal((await send('{"token":"synthetic.token.value"}', {Authorization: f.env.NATIVE_AUTH})).status, 403);
    assert.equal((await send('x'.repeat(10001))).status, 413);
    for (const body of ['invalid', '[]', '{"token":false}', '{"token":"fixture","command":"arbitrary"}']) assert.equal((await send(body)).status, 400);
    assert.equal(f.calls.length, 0);
    assert.equal((await send('{"token":"synthetic.token.value"}')).status, 200);
    assert.deepEqual(f.calls[0], ['oauth', 'synthetic.token.value']);
});

test('checkpoint operations require and forward an exact checkpoint identity', async () => {
    const f = fixture(); f.env.MIGRATION_ONLY = '1';
    f.env.MIGRATION_RECOVERY = {run: async (...args) => {f.calls.push(args);return {checkpoint: args[1]};}};
    const headers = {Authorization: f.env.NATIVE_AUTH, 'X-Native-Admin': f.env.NATIVE_ADMIN};
    const send = checkpoint => f.handler.fetch(new Request('https://capacity/__native/migration/backup', {method: 'POST', headers: checkpoint === null ? headers : {...headers, 'X-FamilyFunds-Checkpoint': checkpoint}}), f.env);
    for (const value of [null, '../path', 'old-id']) assert.equal((await send(value)).status, 400);
    assert.equal(f.calls.length, 0);
    const checkpoint = 'cutover-20261027t150000z';
    assert.equal((await send(checkpoint)).status, 200);
    assert.deepEqual(f.calls[0], ['backup', checkpoint]);
});
