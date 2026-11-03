import assert from "node:assert/strict";
import {createHash, createHmac, randomUUID, timingSafeEqual, webcrypto} from "node:crypto";
import {mkdtempSync, readFileSync, rmSync, writeFileSync} from "node:fs";
import {tmpdir} from "node:os";
import {join} from "node:path";
import {spawnSync} from "node:child_process";
import {test} from "node:test";
import {runInNewContext} from "node:vm";
import {STAGING_BOOTSTRAP_ASSETS as packagedAssets} from "./container-bootstrap-assets.mjs";
import {containerEnvironment} from "./container-env.mjs";

const source = readFileSync(new URL("./container-worker.mjs", import.meta.url), "utf8")
    .replace('import { DurableObject } from "cloudflare:workers";', "class DurableObject { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }")
    .replace('import { STAGING_BOOTSTRAP_ASSETS } from "./container-bootstrap-assets.mjs";', "")
    .replace('import { containerEnvironment } from "./container-env.mjs";', "")
    .replace("export class ", "class ").replace("export default ", "const handler = ");

function fixture(assets = [{path: "/var/www/html/deployment/d1-proof/container-nginx-staging.conf", content: "events {}", sha256: "a".repeat(64)}, {path: "/var/www/html/deployment/d1-proof/run-staging.php", content: "<?php echo 1;", sha256: "b".repeat(64)}, ...packagedAssets.filter(asset => asset.path.endsWith('/staging-fpm-status.php'))], gatewayFetch = async () => { throw new Error("Unexpected gateway request"); }) {
    const {handler, Container} = runInNewContext(source + "\n({handler, Container: FamilyFundsWebStagingContainer})", {
        Request, Response, Headers, URL, ReadableStream, AbortSignal, AbortController, TextEncoder, TextDecoder,
        performance, setTimeout, clearTimeout, console,
        STAGING_BOOTSTRAP_ASSETS: assets,
        containerEnvironment,
        crypto: {randomUUID, subtle: {timingSafeEqual, importKey: webcrypto.subtle.importKey.bind(webcrypto.subtle), verify: webcrypto.subtle.verify.bind(webcrypto.subtle)}},
    });
    const commands = [];
    const stored = new Map();
    const ctx = {
        blockConcurrencyWhile: callback => callback(),
        storage: {get: async key => stored.get(key), put: async (key, value) => { stored.set(key, value); },
            delete: async key => stored.delete(key), list: async ({prefix, limit}) => new Map([...stored].filter(([key]) => key.startsWith(prefix)).slice(0, limit))},
        container: {
            running: true, setInactivityTimeout: async () => {},
            inspect: async () => ({image: "registry.example/staging@sha256:fixture", labels: {}}),
            exec: async command => {
                commands.push(command);
                return {output: async () => ({exitCode: 0, stdout: new TextEncoder().encode('{"memory_max":"3221225472"}')})};
            },
            getTcpPort: () => ({fetch: async () => new Response("application body")}),
        },
    };
    const env = {BASIC_AUTHORIZATION: "Basic fixture", ADMIN_TOKEN: "fixture-admin", APP_CONCURRENCY: "16"};
    const container = new Container(ctx, env);
    container.ready = true;
    env.STAGING_CONTAINER = {getByName: () => container};
    env.STAGING_SQL_GATEWAY = {fetch: gatewayFetch};
    const queued = [];
    const webhook = {enabled: true, database: "familyfunds-d1-web-staging", family_id: 43,
        paystack_test_key: "sk_test_fixture", meta_app_secret: "fixture-secret", meta_verify_token: "fixture-verify",
        meta_waba: "fixture-waba", meta_phone: "fixture-phone", recipient: "97400000000"};
    env.STAGING_WEBHOOK_SECRETS = JSON.stringify(webhook);
    env.STAGING_WEBHOOK_QUEUE = {send: async record => queued.push(record)};
    return {handler, container, env, commands, queued, webhook, stored};
}

function signedRecord(provider, payload, config) {
    const raw = JSON.stringify(payload);
    const meta = provider === "whatsapp";
    const signature = (meta ? "sha256=" : "") + createHmac(meta ? "sha256" : "sha512", meta ? config.meta_app_secret : config.paystack_test_key).update(raw).digest("hex");
    return {provider, raw, signature};
}

test('a bounded 20-slot pool queues overflow until a response releases its slot', async () => {
    const f = fixture();
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, {...f.env, APP_CONCURRENCY: '20'});
    assert.deepEqual(await Promise.all(Array.from({length: 20}, () => container.acquireRequest())), Array(20).fill(true));
    const overflow = container.acquireRequest();
    assert.equal(container.inFlight, 20);
    assert.equal(container.waiters.length, 1);
    container.releaseRequest();
    assert.equal(await overflow, true);
    for (let index = 0; index < 20; index++) container.releaseRequest();
    assert.equal(container.inFlight, 0);
    assert.throws(() => new Container(f.container.ctx, {...f.env, APP_CONCURRENCY: '21'}), /concurrency limit/);
});

function webhookRequest(record) {
    return new Request(`https://staging.example/webhooks/${record.provider}`, {method: "POST", body: record.raw,
        headers: {[record.provider === "paystack" ? "X-Paystack-Signature" : "X-Hub-Signature-256"]: record.signature}});
}

test('staging gateway health authenticates, uses only the pinned target and returns no credentials', async () => {
    const calls = [];
    const f = fixture(undefined, async (url, init) => {
        calls.push({url, init});
        return Response.json({database: 'familyfunds-d1-web-staging', result: {results: [{ok: 1}]}});
    });
    const token = 'fixture-token-' + 'a'.repeat(32);
    f.env.CONTAINER_ENV = JSON.stringify({D1_STAGING_ENABLED: 'true', D1_STAGING_DATABASE: 'familyfunds-d1-web-staging',
        D1_STAGING_URL: 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', D1_STAGING_TOKEN: token});
    const url = 'https://staging.example/__staging_health/gateway';
    const unauthorized = await f.handler.fetch(new Request(url), f.env);
    assert.equal(unauthorized.status, 401);
    assert.equal(calls.length, 0);
    const response = await f.handler.fetch(new Request(url, {headers: {Authorization: f.env.BASIC_AUTHORIZATION}}), f.env);
    assert.equal(response.status, 200);
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    assert.deepEqual(await response.json(), {ok: true});
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, 'https://staging-gateway.internal/query');
    assert.equal(calls[0].init.headers.Authorization, 'Bearer ' + token);
    assert.equal(calls[0].init.redirect, 'manual');
    assert.deepEqual(JSON.parse(calls[0].init.body), {sql: 'select 1 as ok', params: []});
    const invalidMethod = await f.handler.fetch(new Request(url, {method: 'POST', headers: {Authorization: f.env.BASIC_AUTHORIZATION}}), f.env);
    assert.equal(invalidMethod.status, 404);
    f.env.RUNTIME_PROFILE = 'production';
    assert.equal((await f.handler.fetch(new Request(url, {headers: {Authorization: f.env.BASIC_AUTHORIZATION}}), f.env)).status, 404);
    assert.equal(calls.length, 1);
});

test('staging gateway health uses the staging image defaults without overriding explicit values', async () => {
    const dockerfile = readFileSync(new URL('./Dockerfile.slim', import.meta.url), 'utf8');
    const imageEnv = dockerfile.split('\n').find(line => line.startsWith('ENV APP_ENV=d1-staging '));
    assert.ok(imageEnv);
    for (const value of ['D1_STAGING_ENABLED=true', 'D1_STAGING_DATABASE=familyfunds-d1-web-staging',
        'D1_STAGING_URL=https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev']) {
        assert.ok(imageEnv.split(' ').includes(value));
    }
    let calls = 0;
    const f = fixture(undefined, async () => {
        calls++;
        return Response.json({database: 'familyfunds-d1-web-staging', result: {results: [{ok: 1}]}});
    });
    const request = () => new Request('https://staging.example/__staging_health/gateway', {headers: {Authorization: f.env.BASIC_AUTHORIZATION}});
    const base = {D1_STAGING_TOKEN: 'a'.repeat(32)};
    f.env.CONTAINER_ENV = JSON.stringify(base);
    assert.deepEqual(await (await f.handler.fetch(request(), f.env)).json(), {ok: true});
    f.env.CONTAINER_ENV = JSON.stringify({...base, D1_STAGING_ENABLED: 'false'});
    const result = await f.handler.fetch(request(), f.env);
    assert.equal(result.status, 503);
    assert.deepEqual(await result.json(), {ok: false, stage: 'environment_flags'});
    assert.equal(calls, 1);
});

test('staging gateway health fails closed for wrong configuration and invalid responses', async () => {
    let output = Response.json({database: 'familyfunds-d1-web-staging', result: {results: [{ok: 1}]}});
    let calls = 0;
    const f = fixture(undefined, async () => { calls++; return output; });
    const base = {D1_STAGING_ENABLED: 'true', D1_STAGING_DATABASE: 'familyfunds-d1-web-staging',
        D1_STAGING_URL: 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', D1_STAGING_TOKEN: 'a'.repeat(32)};
    const url = 'https://staging.example/__staging_health/gateway';
    const request = () => new Request(url, {headers: {Authorization: f.env.BASIC_AUTHORIZATION}});
    for (const [change, stage] of [[{D1_PRODUCTION_ENABLED: 'true'}, 'environment_flags'], [{D1_STAGING_DATABASE: 'production'}, 'database_identity'], [{D1_STAGING_URL: 'https://attacker.example'}, 'gateway_url'], [{D1_STAGING_TOKEN: 'short'}, 'gateway_token']]) {
        f.env.CONTAINER_ENV = JSON.stringify({...base, ...change});
        const result = await f.handler.fetch(request(), f.env);
        assert.equal(result.status, 503);
        assert.deepEqual(await result.json(), {ok: false, stage});
    }
    assert.equal(calls, 0);
    f.env.CONTAINER_ENV = JSON.stringify(base);
    delete f.env.STAGING_SQL_GATEWAY;
    assert.equal((await f.handler.fetch(request(), f.env)).status, 503);
    f.env.STAGING_SQL_GATEWAY = {fetch: async () => { calls++; return output; }};
    for (const invalid of [Response.json({database: 'other', result: {results: [{ok: 1}]}}), new Response('x'.repeat(4097)), new Response('invalid'), new Response(null, {status: 302, headers: {Location: 'https://attacker.example'}}), new Response('', {status: 503})]) {
        output = invalid;
        const result = await f.handler.fetch(request(), f.env);
        assert.equal(result.status, 503);
        const body = await result.json();
        assert.equal(body.ok, false);
        assert.ok(['gateway_http', 'gateway_response'].includes(body.stage));
        assert.equal(JSON.stringify(body).includes(base.D1_STAGING_TOKEN), false);
    }
    assert.equal(calls, 5);
    f.env.STAGING_SQL_GATEWAY = {fetch: async () => new Response('', {status: 401})};
    assert.deepEqual(await (await f.handler.fetch(request(), f.env)).json(), {ok: false, stage: 'gateway_authentication'});
    f.env.STAGING_SQL_GATEWAY = {fetch: async () => { throw new Error('sensitive fixture'); }};
    assert.deepEqual(await (await f.handler.fetch(request(), f.env)).json(), {ok: false, stage: 'service_request'});
});

test('gateway control is opt-in, independently authenticated and only forwards fixed admission operations', async () => {
    const calls = [];
    let answer = {paused: false, unresolved: 0};
    const f = fixture(undefined, async (url, init) => { calls.push({url, init}); return Response.json(answer); });
    const token = 'a'.repeat(40);
    const proof = 'b'.repeat(40);
    f.env.CONTAINER_ENV = JSON.stringify({D1_STAGING_TOKEN: proof});
    f.env.STAGING_GATEWAY_ADMIN_TOKEN = token;
    const base = 'https://staging.example/__staging_admin/gateway-admission';
    const request = (url = base, options = {}) => new Request(url, {headers: {Authorization: f.env.BASIC_AUTHORIZATION, 'X-Staging-Gateway-Admin': token}, ...options});
    assert.equal((await f.handler.fetch(request(), f.env)).status, 404);
    f.env.STAGING_GATEWAY_CONTROL = '1';
    assert.equal((await f.handler.fetch(new Request(base), f.env)).status, 401);
    assert.equal((await f.handler.fetch(request(base, {headers: {Authorization: f.env.BASIC_AUTHORIZATION, 'X-Staging-Gateway-Admin': 'wrong'}}), f.env)).status, 403);
    assert.equal((await f.handler.fetch(request(base + '?details=1'), f.env)).status, 404);
    assert.equal((await f.handler.fetch(request(base + '?paused=true&paused=false', {method: 'POST'}), f.env)).status, 404);
    assert.equal((await f.handler.fetch(request(base + '?paused=true&extra=1', {method: 'POST'}), f.env)).status, 404);
    assert.equal((await f.handler.fetch(request(base, {method: 'DELETE'}), f.env)).status, 404);
    const status = await f.handler.fetch(request(), f.env);
    assert.deepEqual(await status.json(), {paused: false, unresolved: 0});
    assert.equal(status.headers.get('Cache-Control'), 'no-store');
    answer = {paused: true, unresolved: 1};
    const pause = await f.handler.fetch(request(base + '?paused=true', {method: 'POST'}), f.env);
    const pauseBody = await pause.json();
    assert.deepEqual(pauseBody, {paused: true, unresolved: 1});
    assert.equal(calls.length, 2);
    assert.equal(calls[0].url, 'https://staging-gateway.internal/__staging_admin/admission');
    assert.equal(calls[1].url, 'https://staging-gateway.internal/__staging_admin/admission?paused=true');
    assert.equal(calls[1].init.headers.Authorization, 'Bearer ' + proof);
    assert.equal(calls[1].init.headers['X-Staging-Gateway-Admin'], token);
    assert.equal(calls[1].init.redirect, 'manual');
    assert.equal(JSON.stringify(pauseBody).includes(token), false);
    answer = {paused: false, unresolved: 0};
    assert.equal((await f.handler.fetch(request(base + '?paused=true', {method: 'POST'}), f.env)).status, 503);
    answer = {paused: true, unresolved: 0, secret: token};
    assert.equal((await f.handler.fetch(request(), f.env)).status, 503);
    f.env.RUNTIME_PROFILE = 'production';
    assert.equal((await f.handler.fetch(request(), f.env)).status, 404);
});

test('gateway control refuses conflicting targets and uncertain gateway responses', async () => {
    let response = new Response('', {status: 503});
    const f = fixture(undefined, async () => response);
    f.env.STAGING_GATEWAY_CONTROL = '1';
    f.env.STAGING_GATEWAY_ADMIN_TOKEN = 'a'.repeat(40);
    f.env.CONTAINER_ENV = JSON.stringify({D1_STAGING_TOKEN: 'b'.repeat(40)});
    const request = () => new Request('https://staging.example/__staging_admin/gateway-admission', {headers: {Authorization: f.env.BASIC_AUTHORIZATION, 'X-Staging-Gateway-Admin': f.env.STAGING_GATEWAY_ADMIN_TOKEN}});
    for (const value of [response, new Response('x'.repeat(4097)), new Response('invalid'), Response.json({paused: false, unresolved: -1}), Response.json({paused: false, unresolved: 1002}), new Response(null, {status: 302})]) {
        response = value;
        assert.equal((await f.handler.fetch(request(), f.env)).status, 503);
    }
    f.env.CONTAINER_ENV = JSON.stringify({D1_STAGING_TOKEN: 'b'.repeat(40), D1_STAGING_DATABASE: 'other'});
    assert.equal((await f.handler.fetch(request(), f.env)).status, 503);
});

test("the deployable bootstrap contains the exact reviewed source bytes", () => {
    assert.equal(packagedAssets.length, 15);
    assert.ok(packagedAssets.some(asset => asset.path.endsWith('/D1StagingStorageAdmission.php')));
    for (const asset of packagedAssets) {
        const relative = asset.path.replace("/var/www/html/", "");
        assert.equal(asset.content, readFileSync(new URL("../../" + relative, import.meta.url), "utf8"));
        assert.equal(asset.sha256, createHash("sha256").update(asset.content).digest("hex"));
    }
});

test('only staging images configure the private FPM status listener and block its web path', () => {
    const sourceFile = name => readFileSync(new URL(`./${name}`, import.meta.url), 'utf8');
    for (const image of ['Dockerfile.slim', 'Dockerfile.lean']) {
        const source = sourceFile(image);
        assert.match(source, /container-nginx-staging\.conf/);
        assert.match(source, /pm\.status_listen = \/run\/php\/familyfunds-staging-status\.sock/);
        assert.match(source, /listen\.mode = 0600/);
    }
    assert.doesNotMatch(sourceFile('Dockerfile.production'), /pm\.status_listen|container-nginx-staging\.conf/);
    assert.match(sourceFile('container-nginx-staging.conf'), /location \^~ \/__staging_fpm_status \{ return 404; \}/);
    assert.ok(packagedAssets.some(asset => asset.path.endsWith('/container-nginx-staging.conf')));
    assert.ok(packagedAssets.some(asset => asset.path.endsWith('/staging-fpm-status.php')));
});

test('staging queue startup can be disabled without affecting the production default', () => {
    const entrypoint = readFileSync(new URL('./container-slim-entrypoint.sh', import.meta.url), 'utf8')
        .replace('set -eu', 'set -eu\nphp() { :; }')
        .replace('mkdir -p /run/nginx /run/php', ':')
        .replace('exec /usr/bin/supervisord -c /etc/supervisord.conf', 'printf "%s" "$STAGING_QUEUE_WORKER_ENABLED"');
    const run = env => spawnSync('/bin/sh', ['-c', entrypoint], {encoding: 'utf8', env: {PATH: process.env.PATH, ...env}});
    const staging = {APP_ENV: 'd1-staging', D1_STAGING_ENABLED: 'true'};
    assert.equal(run(staging).stdout, 'true');
    assert.equal(run({...staging, STAGING_QUEUE_WORKER_ENABLED: 'false'}).stdout, 'false');
    assert.equal(run({...staging, STAGING_QUEUE_WORKER_ENABLED: 'invalid'}).status, 64);
    assert.equal(run({APP_ENV: 'production', STAGING_QUEUE_WORKER_ENABLED: 'false'}).stdout, 'true');
});

test("runtime diagnostics require both existing staging credentials", async () => {
    const {handler, env, commands} = fixture();
    for (const headers of [{}, {Authorization: "Basic fixture"}, {Authorization: "Basic fixture", "X-Staging-Admin": "wrong"}]) {
        const response = await handler.fetch(new Request("https://staging.example/__staging_admin/runtime", {headers}), env);
        assert.equal(response.status, headers.Authorization ? 403 : 401);
    }
    assert.equal(commands.length, 0);
});

test("candidate routing requires the admin credential as well as Basic Auth", async () => {
    const {handler, env} = fixture();
    let calls = 0;
    env.STAGING_CANDIDATE = {getByName: () => ({fetch: async () => { calls++; return new Response("candidate"); }})};
    for (const headers of [
        {"X-D1-Staging-Candidate": "lean"},
        {Authorization: "Basic fixture", "X-D1-Staging-Candidate": "lean"},
        {Authorization: "Basic fixture", "X-D1-Staging-Candidate": "other", "X-Staging-Admin": "fixture-admin"},
    ]) {
        assert.ok([401, 403].includes((await handler.fetch(new Request("https://staging.example/dashboard", {headers}), env)).status));
    }
    assert.equal(calls, 0);
    const response = await handler.fetch(new Request("https://staging.example/dashboard", {
        headers: {Authorization: "Basic fixture", "X-D1-Staging-Candidate": "lean", "X-Staging-Admin": "fixture-admin"},
    }), env);
    assert.equal(await response.text(), "candidate");
    assert.equal(calls, 1);
});

test("inspection returns the native running image without executing a command", async () => {
    const {handler, env, commands} = fixture();
    const response = await handler.fetch(new Request("https://staging.example/__staging_admin/inspect", {
        headers: {Authorization: "Basic fixture", "X-Staging-Admin": "fixture-admin"},
    }), env);
    assert.deepEqual(await response.json(), {running: true, image: "registry.example/staging@sha256:fixture",
        activity: {request_slots: 0, waiting_requests: 0, starting: false, maintenance_rpcs: 0}});
    assert.equal(response.headers.get("Cache-Control"), "no-store");
    assert.equal(commands.length, 0);
});

test('staging inspection reports active and waiting request slots without claiming the container is drained', async () => {
    const f = fixture();
    f.container.requestLimit = 1;
    const first = await f.container.fetch(new Request('https://staging.example/payments'));
    const secondPromise = f.container.fetch(new Request('https://staging.example/payments'));
    await Promise.resolve();
    const during = await f.container.inspect();
    assert.equal(during.activity.request_slots, 1);
    assert.equal(during.activity.waiting_requests, 1);
    await first.text();
    const second = await secondPromise;
    await second.text();
    const after = await f.container.inspect();
    assert.equal(after.activity.request_slots, 0);
    assert.equal(after.activity.waiting_requests, 0);
    f.env.RUNTIME_PROFILE = 'production';
    assert.equal((await f.container.inspect()).activity, undefined);
});

test('staging inspection reports an in-progress container startup', async () => {
    const f = fixture();
    f.container.ready = false;
    f.container.ctx.container.running = false;
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    f.container.waitForReady = async () => { await pending; f.container.ready = true; f.container.ctx.container.running = true; };
    const request = f.container.fetch(new Request('https://staging.example/payments'));
    assert.equal((await f.container.inspect()).activity.starting, true);
    finish();
    await (await request).text();
    assert.equal((await f.container.inspect()).activity.starting, false);
});

test('staging inspection reports a running maintenance command and clears it after success or failure', async () => {
    const f = fixture();
    f.env.STAGING_SCHEDULE_FAMILIES = JSON.stringify({family: 50, purge: 51});
    f.env.CONTAINER_ENV = JSON.stringify({APP_KEY: 'fixture'});
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    f.container.ctx.container.exec = async () => ({output: async () => pending});
    const command = f.container.scheduledProof('generate', 50, '2026-01-01T00:00:00.000Z');
    assert.equal((await f.container.inspect()).activity.maintenance_rpcs, 1);
    finish({exitCode: 0, stdout: new TextEncoder().encode('{"exit_code":0}')});
    await command;
    assert.equal((await f.container.inspect()).activity.maintenance_rpcs, 0);
    f.container.ctx.container.exec = async () => { throw new Error('Synthetic command failure'); };
    await assert.rejects(f.container.scheduledProof('generate', 50, '2026-01-01T00:00:00.000Z'));
    assert.equal((await f.container.inspect()).activity.maintenance_rpcs, 0);
});

test('staging admission pause requires both credentials and persists across object reconstruction', async () => {
    const f = fixture();
    f.env.STAGING_ADMISSION_CONTROL = '1';
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, f.env);
    container.ready = true;
    f.env.STAGING_CONTAINER = {getByName: () => container};
    const request = headers => new Request('https://staging.example/__staging_admin/pause-admission', {method: 'POST', headers});
    assert.equal((await f.handler.fetch(request({}), f.env)).status, 401);
    assert.equal((await f.handler.fetch(request({Authorization: 'Basic fixture'}), f.env)).status, 403);
    const headers = {Authorization: 'Basic fixture', 'X-Staging-Admin': 'fixture-admin'};
    const paused = await f.handler.fetch(request(headers), f.env);
    assert.deepEqual(await paused.json(), {admission_paused: true});
    assert.equal((await container.fetch(new Request('https://staging.example/payments'))).status, 503);
    await assert.rejects(container.scheduledProof('generate', 50, '2026-01-01T00:00:00.000Z'), /admission is paused/);
    await assert.rejects(container.restart(), /admission is paused/);

    const restored = new Container(f.container.ctx, f.env);
    restored.ready = true;
    assert.equal((await restored.inspect()).admission_paused, true);
    f.env.STAGING_CONTAINER = {getByName: () => restored};
    const resumed = await f.handler.fetch(new Request('https://staging.example/__staging_admin/resume-admission', {method: 'POST', headers}), f.env);
    assert.deepEqual(await resumed.json(), {admission_paused: false});
    assert.equal(await (await restored.fetch(new Request('https://staging.example/payments'))).text(), 'application body');
});

test('staging admission pause prevents queued and starting requests from reaching the container', async () => {
    const f = fixture();
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, {...f.env, STAGING_ADMISSION_CONTROL: '1'});
    container.ready = true;
    container.requestLimit = 1;
    const first = await container.fetch(new Request('https://staging.example/payments'));
    const waiting = container.fetch(new Request('https://staging.example/payments'));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal((await container.inspect()).activity.waiting_requests, 1);
    await container.setAdmissionPaused(true);
    await first.text();
    assert.equal((await waiting).status, 503);
    assert.equal((await container.inspect()).activity.request_slots, 0);

    await container.setAdmissionPaused(false);
    container.ready = false;
    let finish;
    container.waitForReady = async () => { await new Promise(resolve => { finish = resolve; }); container.ready = true; };
    const starting = container.fetch(new Request('https://staging.example/payments'));
    await new Promise(resolve => setImmediate(resolve));
    await container.setAdmissionPaused(true);
    finish();
    assert.equal((await starting).status, 503);
});

test('staging admission pause prevents a startup retry from starting the container', async () => {
    const f = fixture();
    const Container = f.container.constructor;
    f.container.ctx.container.running = false;
    let starts = 0;
    let finishTimeout;
    const timeoutPending = new Promise(resolve => { finishTimeout = resolve; });
    f.container.ctx.container.start = () => { starts++; };
    f.container.ctx.container.setInactivityTimeout = () => timeoutPending;
    f.container.ctx.container.getTcpPort = () => ({fetch: async () => new Response('not ready', {status: 503})});
    const container = new Container(f.container.ctx, {...f.env, CONTAINER_ENV: '{}', STAGING_ADMISSION_CONTROL: '1'});
    const pending = container.fetch(new Request('https://staging.example/payments'));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(starts, 1);
    await container.setAdmissionPaused(true);
    finishTimeout();
    assert.equal((await pending).status, 503);
    assert.equal(starts, 1);
});

test('staging admission control stays closed on a failed pause write and is unavailable in production', async () => {
    const f = fixture();
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, {...f.env, STAGING_ADMISSION_CONTROL: '1'});
    container.ready = true;
    await container.admissionReady;
    f.container.ctx.storage.put = async () => { throw new Error('Synthetic storage failure'); };
    await assert.rejects(container.setAdmissionPaused(true), /storage failure/);
    assert.equal((await container.fetch(new Request('https://staging.example/payments'))).status, 503);
    const production = new Container(f.container.ctx, {...f.env, RUNTIME_PROFILE: 'production', STAGING_ADMISSION_CONTROL: '1'});
    await assert.rejects(production.setAdmissionPaused(true), /control is disabled/);
});

test('staging inspection reports a restart already in progress after admission is paused', async () => {
    const f = fixture();
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, {...f.env, STAGING_ADMISSION_CONTROL: '1'});
    let finish;
    container.ctx.container.destroy = () => new Promise(resolve => { finish = resolve; });
    const restart = container.restart();
    await new Promise(resolve => setImmediate(resolve));
    await container.setAdmissionPaused(true);
    assert.equal((await container.inspect()).restart_operations, 1);
    finish();
    await restart;
    assert.equal((await container.inspect()).restart_operations, 0);
});

test('staging FPM status requires both credentials, an enabled control and paused admission', async () => {
    const f = fixture();
    Object.assign(f.env, {STAGING_ADMISSION_CONTROL: '1', STAGING_FPM_STATUS_ENABLED: '1'});
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, f.env);
    f.env.STAGING_CONTAINER = {getByName: () => container};
    const request = headers => new Request('https://staging.example/__staging_admin/fpm-status', {headers});
    assert.equal((await f.handler.fetch(request({}), f.env)).status, 401);
    assert.equal((await f.handler.fetch(request({Authorization: 'Basic fixture'}), f.env)).status, 403);
    const headers = {Authorization: 'Basic fixture', 'X-Staging-Admin': 'fixture-admin'};
    f.env.STAGING_FPM_STATUS_ENABLED = '0';
    assert.equal((await f.handler.fetch(request(headers), f.env)).status, 404);
    f.env.STAGING_FPM_STATUS_ENABLED = '1';
    await assert.rejects(f.handler.fetch(request(headers), f.env), /unavailable/);
    await container.setAdmissionPaused(true);
    let command;
    container.ctx.container.exec = async args => {
        command = args;
        return {output: async () => ({exitCode: 0, stdout: new TextEncoder().encode(JSON.stringify({active_processes: 1, listen_queue: 0, listen_queue_capacity: 64, total_processes: 4, start_time: 123}))})};
    };
    const response = await f.handler.fetch(request(headers), f.env);
    assert.deepEqual(await response.json(), {active_processes: 1, listen_queue: 0, listen_queue_capacity: 64, total_processes: 4, start_time: 123});
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    const asset = packagedAssets.find(asset => asset.path.endsWith('/staging-fpm-status.php'));
    assert.equal(command[0], 'php');
    assert.equal(command[1], '-r');
    assert.match(command[2], /hash_file\("sha256"/);
    assert.ok(command[2].includes(asset.sha256));
    assert.ok(command[2].includes(asset.path));
    f.env.RUNTIME_PROFILE = 'production';
    assert.equal((await f.handler.fetch(request(headers), f.env)).status, 404);
});

test('staging FPM status rejects malformed data or admission changed during inspection', async () => {
    const f = fixture();
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, {...f.env, STAGING_ADMISSION_CONTROL: '1', STAGING_FPM_STATUS_ENABLED: '1'});
    await container.setAdmissionPaused(true);
    container.ctx.container.exec = async () => ({output: async () => ({exitCode: 0, stdout: new TextEncoder().encode('{"active_processes":0}')})});
    await assert.rejects(container.stagingFpmStatus(), /Invalid staging FPM status/);
    container.ctx.container.exec = async () => ({output: async () => ({exitCode: 1, stdout: new Uint8Array()})});
    await assert.rejects(container.stagingFpmStatus(), /unavailable/);
    container.ctx.container.exec = async () => ({output: async () => ({exitCode: 0, stdout: new TextEncoder().encode(JSON.stringify({active_processes: 0, listen_queue: 8842250, listen_queue_capacity: 64, total_processes: 2, start_time: 123}))})});
    await assert.rejects(container.stagingFpmStatus(), /Invalid staging FPM status/);
    let finish;
    container.ctx.container.exec = async () => ({output: () => new Promise(resolve => { finish = resolve; })});
    const pending = container.stagingFpmStatus();
    await new Promise(resolve => setImmediate(resolve));
    await container.setAdmissionPaused(false);
    await container.setAdmissionPaused(true);
    finish({exitCode: 0, stdout: new TextEncoder().encode(JSON.stringify({active_processes: 0, listen_queue: 0, listen_queue_capacity: 64, total_processes: 4, start_time: 123}))});
    await assert.rejects(pending, /unavailable/);
});

test('the FPM launcher refuses to execute stale probe bytes', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'ff-probe-'));
    try {
        const path = join(directory, 'probe.php');
        const good = '<?php echo "verified";';
        const expected = createHash('sha256').update(good).digest('hex');
        const asset = packagedAssets.find(item => item.path.endsWith('/staging-fpm-status.php'));
        const f = fixture();
        const Container = f.container.constructor;
        const container = new Container(f.container.ctx, {...f.env, STAGING_ADMISSION_CONTROL: '1', STAGING_FPM_STATUS_ENABLED: '1'});
        await container.setAdmissionPaused(true);
        let command;
        container.ctx.container.exec = async args => {
            command = Array.from(args);
            return {output: async () => ({exitCode: 1, stdout: new Uint8Array()})};
        };
        await assert.rejects(container.stagingFpmStatus(), /unavailable/);
        const launcher = command[2].replace(JSON.stringify(asset.path), JSON.stringify(path)).replace(asset.sha256, expected);
        writeFileSync(path, good);
        const verified = spawnSync('php', ['-r', launcher], {encoding: 'utf8'});
        assert.equal(verified.status, 0);
        assert.equal(verified.stdout, 'verified');
        writeFileSync(path, '<?php echo "stale";');
        const stale = spawnSync('php', ['-r', launcher], {encoding: 'utf8'});
        assert.equal(stale.status, 1);
        assert.equal(stale.stdout, '');
    } finally {
        rmSync(directory, {recursive: true, force: true});
    }
});

test("runtime diagnostics use fixed read-only programs", async () => {
    const {handler, env, commands} = fixture();
    const response = await handler.fetch(new Request("https://staging.example/__staging_admin/runtime?command=untrusted", {
        headers: {Authorization: "Basic fixture", "X-Staging-Admin": "fixture-admin"},
    }), env);
    assert.equal((await response.json()).runtime.memory_max, "3221225472");
    assert.equal(commands.length, 1);
    assert.equal(commands[0][0], "php");
    assert.equal(commands[0][1], "-r");
    assert.ok(!commands[0][2].includes("untrusted"));
});

test("normal responses stream and report startup, queue and upstream timing", async () => {
    const {container} = fixture();
    const response = await container.fetch(new Request("https://staging.example/dashboard"));
    assert.match(response.headers.get("Server-Timing"), /container_start;dur=[\d.]+, gateway_queue;dur=[\d.]+, container_headers;dur=[\d.]+/);
    assert.equal(response.headers.get("Cache-Control"), "no-store");
    assert.equal(container.inFlight, 1);
    assert.equal(await response.text(), "application body");
    assert.equal(container.inFlight, 0);
});

test("only successful versioned Livewire JavaScript is cached privately", async () => {
    for (const [path, method, status, type, cacheable] of [
        ['/livewire-deadbeef/livewire.min.js?id=1234abcd', 'GET', 200, 'application/javascript', true],
        ['/livewire-deadbeef/livewire.min.js?id=1234abcd', 'HEAD', 200, 'text/javascript', true],
        ['/livewire-deadbeef/livewire.min.js', 'GET', 200, 'application/javascript', false],
        ['/livewire-deadbeef/livewire.min.js?id=unversioned', 'GET', 200, 'application/javascript', false],
        ['/livewire-deadbeef/livewire.min.js?id=1234abcd', 'GET', 503, 'application/javascript', false],
        ['/livewire-deadbeef/livewire.min.js?id=1234abcd', 'GET', 200, 'text/html', false],
        ['/livewire-deadbeef/livewire.min.js?id=1234abcd', 'POST', 200, 'application/javascript', false],
        ['/platform?id=1234abcd', 'GET', 200, 'text/html', false],
    ]) {
        const {container} = fixture();
        container.ctx.container.getTcpPort = () => ({fetch: async () => new Response(method === 'HEAD' ? null : 'body', {status, headers: {'Content-Type': type}})});
        const response = await container.fetch(new Request('https://staging.example' + path, {method}));
        assert.equal(response.headers.get('Cache-Control'), cacheable ? 'private, max-age=31536000, immutable' : 'no-store', `${method} ${path} ${status} ${type}`);
        await response.text();
        assert.equal(container.inFlight, 0);
    }
});

test("the authenticated gateway reports RPC latency without buffering the response", async () => {
    const {handler, env, container} = fixture();
    const response = await handler.fetch(new Request("https://staging.example/dashboard", {headers: {Authorization: "Basic fixture"}}), env);
    assert.match(response.headers.get("Server-Timing"), /edge_rpc;dur=[\d.]+/);
    assert.equal(container.inFlight, 1);
    assert.equal(await response.text(), "application body");
    assert.equal(container.inFlight, 0);
});

test("the optimized startup installs only packaged files before the fixed entrypoint", async () => {
    const {container, env} = fixture();
    env.OPTIMIZED_BOOTSTRAP = "v1";
    env.CONTAINER_ENV = JSON.stringify({APP_KEY: "fixture"});
    container.ctx.container.running = false;
    let started;
    container.ctx.container.start = options => { started = options; container.ctx.container.running = true; };
    container.ctx.container.getTcpPort = () => ({fetch: async () => new Response("up")});
    await container.waitForReady();
    assert.equal(started.env.FF_STAGING_ASSET_0, "events {}");
    assert.ok(!JSON.stringify(started.env).includes("<?php echo 1;"));
    assert.match(started.entrypoint[2], /staging-fpm-status\.php/);
    assert.equal(started.entrypoint[0], "/bin/sh");
    assert.equal(started.env.FF_STARTUP_PROFILE, undefined);
    assert.equal(started.env.FF_STAGING_START_REQUEST_MS, undefined);
    assert.match(started.entrypoint[2], /hash_equals/);
    assert.match(started.entrypoint[2], /\/etc\/nginx\/nginx.conf/);
    assert.match(started.entrypoint[2], /exec \/var\/www\/html\/deployment\/d1-proof\/container-slim-entrypoint.sh$/);
});

test("startup profiling is opt-in and preserves application timing", async () => {
    const {container, env} = fixture();
    env.OPTIMIZED_BOOTSTRAP = "v1";
    env.STARTUP_PROFILE = "1";
    env.CONTAINER_ENV = JSON.stringify({APP_KEY: "fixture"});
    container.ready = false;
    container.ctx.container.running = false;
    let started;
    container.ctx.container.start = options => { started = options; container.ctx.container.running = true; };
    container.ctx.container.getTcpPort = () => ({fetch: async () => new Response("up", {
        headers: {"Server-Timing": "d1_transport;dur=123.4, d1_calls;desc=\"3\""},
    })});
    const response = await container.fetch(new Request("https://staging.example/dashboard"));
    assert.equal(started.env.FF_STARTUP_PROFILE, "1");
    assert.ok(Number(started.env.FF_STAGING_START_REQUEST_MS) > 0);
    assert.match(response.headers.get("Server-Timing"), /d1_transport;dur=123.4/);
    assert.match(response.headers.get("Server-Timing"), /d1_calls;desc="3"/);
    await response.text();
});

test("large Unicode bootstrap files fit the platform limit without changing bytes", async () => {
    const content = "😀".repeat(9000);
    const {container, env} = fixture([{path: "/var/www/html/deployment/d1-proof/D1StagingRuntime.php", content, sha256: createHash("sha256").update(content).digest("hex")}]);
    env.OPTIMIZED_BOOTSTRAP = "v1";
    env.CONTAINER_ENV = "{}";
    container.ctx.container.running = false;
    let started;
    container.ctx.container.start = options => { started = options; container.ctx.container.running = true; };
    container.ctx.container.getTcpPort = () => ({fetch: async () => new Response("up")});
    await container.waitForReady();
    const parts = Object.entries(started.env).filter(([name]) => name.startsWith("FF_STAGING_ASSET_"));
    assert.equal(parts.map(([, value]) => value).join(""), content);
    assert.ok(parts.every(([, value]) => Buffer.byteLength(value) < 16000));
});

test("the opt-in lean PHP startup keeps the required precompiled extensions", async () => {
    const {container, env} = fixture();
    env.OPTIMIZED_BOOTSTRAP = "v1";
    env.LEAN_EXTENSIONS = "v1";
    env.CONTAINER_ENV = "{}";
    container.ctx.container.running = false;
    let started;
    container.ctx.container.start = options => { started = options; container.ctx.container.running = true; };
    container.ctx.container.getTcpPort = () => ({fetch: async () => new Response("up")});
    await container.waitForReady();
    assert.match(started.entrypoint[2], /^for file in \/usr\/local\/etc\/php\/conf.d\/docker-php-ext-\*\.ini;/);
    for (const extension of ["bcmath", "gd", "intl", "pcntl", "sodium", "zip"]) {
        assert.ok(started.entrypoint[2].includes(`docker-php-ext-${extension}.ini`));
    }
});

test("unknown admin operations cannot reach the application", async () => {
    const {handler, env, commands} = fixture();
    const response = await handler.fetch(new Request("https://staging.example/__staging_admin/execute", {
        headers: {Authorization: "Basic fixture", "X-Staging-Admin": "fixture-admin"},
    }), env);
    assert.equal(response.status, 404);
    assert.equal(commands.length, 0);
});

test("maintenance RPC refuses unknown commands, other families and future timestamps", async () => {
    const {container, env, commands} = fixture();
    env.STAGING_SCHEDULE_FAMILIES = JSON.stringify({family: 50, purge: 51});
    for (const [kind, family, at] of [["shell", 50, "2026-01-01T00:00:00.000Z"], ["generate", 43, "2026-01-01T00:00:00.000Z"], ["purge", 50, "2026-01-01T00:00:00.000Z"], ["generate", 50, "2099-01-01T00:00:00.000Z"]]) {
        await assert.rejects(() => container.scheduledProof(kind, family, at), /Invalid isolated/);
    }
    assert.equal(commands.length, 0);
});

test("maintenance RPC executes only the fixed isolated CLI with an explicit environment", async () => {
    const {container, env} = fixture();
    env.STAGING_SCHEDULE_FAMILIES = JSON.stringify({family: 50, purge: 51});
    env.CONTAINER_ENV = JSON.stringify({APP_KEY: "fixture", D1_STAGING_TOKEN: "fixture"});
    let captured;
    container.ctx.container.exec = async (command, options) => {
        captured = {command, options};
        return {output: async () => ({exitCode: 0, stdout: new TextEncoder().encode('{"exit_code":0}')})};
    };
    const result = await container.scheduledProof("generate", 50, "2026-01-01T00:00:00.000Z");
    assert.equal(result.exit_code, 0);
    assert.equal(captured.command[2], "scheduled-maintenance-proof");
    assert.equal(captured.options.env.D1_STAGING_DATABASE, "familyfunds-d1-web-staging");
    assert.equal(captured.options.env.APP_ENV, "d1-staging");
});

test("maintenance CLI installation also uses bounded verified parts", async () => {
    const content = "😀".repeat(9000);
    const {container, env} = fixture([{path: "/var/www/html/deployment/d1-proof/run-staging.php", content, sha256: createHash("sha256").update(content).digest("hex")}]);
    env.OPTIMIZED_BOOTSTRAP = "v1";
    env.STAGING_SCHEDULE_FAMILIES = JSON.stringify({family: 50, purge: 51});
    env.CONTAINER_ENV = "{}";
    let installation;
    container.ctx.container.exec = async (command, options) => {
        if (command[1] === "-r") installation = {command, options};
        return {output: async () => ({exitCode: 0, stdout: new TextEncoder().encode('{"exit_code":0}')})};
    };
    assert.equal((await container.scheduledProof("generate", 50, "2026-01-01T00:00:00.000Z")).exit_code, 0);
    const parts = Object.entries(installation.options.env).filter(([name]) => name.startsWith("FF_STAGING_CLI"));
    assert.equal(parts.map(([, value]) => value).join(""), content);
    assert.ok(parts.every(([, value]) => Buffer.byteLength(value) < 16000));
    assert.match(installation.command[2], /hash_equals/);
});

test("a signed test payment is durably queued without waking a cold container", async () => {
    const {handler, env, webhook, queued} = fixture();
    env.STAGING_CONTAINER.getByName = () => { throw new Error("Producer must not wake the container"); };
    const record = signedRecord("paystack", {event: "charge.success", data: {domain: "test", reference: "D1-WEBHOOK-UAT-fixture", metadata: {family_id: 43}}}, webhook);
    const response = await handler.fetch(webhookRequest(record), env);
    assert.equal(response.status, 202);
    assert.equal(queued.length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(queued[0])), record);
});

test('staging admission pause rejects new webhooks while an earlier queue send remains visible', async () => {
    const f = fixture();
    f.env.STAGING_ADMISSION_CONTROL = '1';
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, f.env);
    f.env.STAGING_CONTAINER = {getByName: () => container};
    let finishSend;
    let notifySend;
    const sendStarted = new Promise(resolve => { notifySend = resolve; });
    f.env.STAGING_WEBHOOK_QUEUE.send = async record => {
        notifySend();
        await new Promise(resolve => { finishSend = resolve; });
        f.queued.push(record);
    };
    const record = signedRecord('paystack', {event: 'charge.success', data: {domain: 'test', reference: 'D1-WEBHOOK-UAT-fixture', metadata: {family_id: 43}}}, f.webhook);
    const first = f.handler.fetch(webhookRequest(record), f.env);
    await sendStarted;
    await container.setAdmissionPaused(true);
    assert.equal((await container.inspect()).webhook_sends, 1);
    assert.equal((await container.inspect()).unresolved_webhook_sends, 1);
    const rejected = await f.handler.fetch(webhookRequest(record), f.env);
    assert.equal(rejected.status, 503);
    assert.equal(f.queued.length, 0);
    finishSend();
    assert.equal((await first).status, 202);
    assert.equal(f.queued.length, 1);
    assert.equal((await container.inspect()).webhook_sends, 0);
    assert.equal((await container.inspect()).unresolved_webhook_sends, 0);
    await container.setAdmissionPaused(false);
    f.env.STAGING_WEBHOOK_QUEUE.send = async value => { f.queued.push(value); };
    assert.equal((await f.handler.fetch(webhookRequest(record), f.env)).status, 202);
    assert.equal(f.queued.length, 2);
});

test('webhook drain inspection cannot combine an old count with a newer pause', async () => {
    const f = fixture();
    let tail = Promise.resolve();
    f.container.ctx.blockConcurrencyWhile = callback => {
        const next = tail.then(callback);
        tail = next.catch(() => {});
        return next;
    };
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, {...f.env, STAGING_ADMISSION_CONTROL: '1'});
    await container.admissionReady;
    const originalList = container.ctx.storage.list;
    let releaseList;
    let notifyList;
    const started = new Promise(resolve => { notifyList = resolve; });
    container.ctx.storage.list = async options => {
        container.ctx.storage.list = originalList;
        const snapshot = await originalList(options);
        notifyList();
        await new Promise(resolve => { releaseList = resolve; });
        return snapshot;
    };
    const inspection = container.inspect();
    await started;
    const pause = container.setAdmissionPaused(true);
    await new Promise(resolve => setImmediate(resolve));
    releaseList();
    const status = await inspection;
    assert.equal(status.admission_paused, false);
    assert.equal(status.unresolved_webhook_sends, 0);
    await pause;
    assert.equal((await container.inspect()).admission_paused, true);
});

test('an uncertain staging webhook send remains unresolved after object reconstruction', async () => {
    const f = fixture();
    f.env.STAGING_ADMISSION_CONTROL = '1';
    const Container = f.container.constructor;
    const container = new Container(f.container.ctx, f.env);
    f.env.STAGING_CONTAINER = {getByName: () => container};
    f.env.STAGING_WEBHOOK_QUEUE.send = async () => { throw new Error('Synthetic uncertain queue outcome'); };
    const record = signedRecord('paystack', {event: 'charge.success', data: {domain: 'test', reference: 'D1-WEBHOOK-UAT-fixture', metadata: {family_id: 43}}}, f.webhook);
    await assert.rejects(f.handler.fetch(webhookRequest(record), f.env), /uncertain queue outcome/);
    await container.setAdmissionPaused(true);
    const restored = new Container(container.ctx, f.env);
    const status = await restored.inspect();
    assert.equal(status.admission_paused, true);
    assert.equal(status.webhook_sends, 0);
    assert.equal(status.unresolved_webhook_sends, 1);
    f.env.STAGING_CONTAINER = {getByName: () => restored};
    assert.equal((await f.handler.fetch(webhookRequest(record), f.env)).status, 503);
});

test("live payments, other families and invalid signatures never enter the queue", async () => {
    const {handler, env, webhook, queued} = fixture();
    for (const data of [{domain: "live", reference: "D1-WEBHOOK-UAT-fixture", metadata: {family_id: 43}},
        {domain: "test", reference: "production-reference", metadata: {family_id: 43}},
        {domain: "test", reference: "D1-WEBHOOK-UAT-fixture", metadata: {family_id: 1}}]) {
        assert.equal((await handler.fetch(webhookRequest(signedRecord("paystack", {event: "charge.success", data}, webhook)), env)).status, 403);
    }
    const invalid = signedRecord("paystack", {event: "charge.success", data: {domain: "test", reference: "D1-WEBHOOK-UAT-fixture", metadata: {family_id: 43}}}, webhook);
    invalid.signature = "0".repeat(128);
    assert.equal((await handler.fetch(webhookRequest(invalid), env)).status, 403);
    assert.equal(queued.length, 0);
});

test("Meta verification requires the separate staging verify token", async () => {
    const {handler, env} = fixture();
    const response = await handler.fetch(new Request("https://staging.example/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=fixture-verify&hub.challenge=12345"), env);
    assert.equal(response.status, 200);
    assert.equal(await response.text(), "12345");
    assert.equal((await handler.fetch(new Request("https://staging.example/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345"), env)).status, 403);
});

test("Meta callbacks are restricted to the staging WABA, sender and approved recipient", async () => {
    const {handler, env, webhook, queued} = fixture();
    const payload = {object: "whatsapp_business_account", entry: [{id: webhook.meta_waba, changes: [{field: "messages", value: {
        metadata: {phone_number_id: webhook.meta_phone}, statuses: [{id: "fixture-message", status: "delivered", recipient_id: webhook.recipient}],
    }}]}]};
    assert.equal((await handler.fetch(webhookRequest(signedRecord("whatsapp", payload, webhook)), env)).status, 200);
    payload.entry[0].id = "other-waba";
    assert.equal((await handler.fetch(webhookRequest(signedRecord("whatsapp", payload, webhook)), env)).status, 403);
    payload.entry[0].id = webhook.meta_waba;
    payload.entry[0].changes[0].value.statuses[0].recipient_id = "other-recipient";
    assert.equal((await handler.fetch(webhookRequest(signedRecord("whatsapp", payload, webhook)), env)).status, 403);
    assert.equal(queued.length, 1);
});

test("a valid Meta scope with a tampered signature is rejected", async () => {
    const {handler, env, webhook, queued} = fixture();
    const record = signedRecord("whatsapp", {object: "whatsapp_business_account", entry: [{id: webhook.meta_waba, changes: [{field: "messages", value: {
        metadata: {phone_number_id: webhook.meta_phone}, statuses: [{id: "fixture", status: "delivered", recipient_id: webhook.recipient}],
    }}]}]}, webhook);
    record.signature = "sha256=" + "0".repeat(64);
    assert.equal((await handler.fetch(webhookRequest(record), env)).status, 403);
    assert.equal(queued.length, 0);
});

test("a failed durable queue write never acknowledges the provider", async () => {
    const {handler, env, webhook} = fixture();
    env.STAGING_WEBHOOK_QUEUE.send = async () => { throw new Error("Queue unavailable"); };
    const record = signedRecord("paystack", {event: "charge.success", data: {domain: "test", reference: "D1-WEBHOOK-UAT-fixture", metadata: {family_id: 43}}}, webhook);
    await assert.rejects(() => handler.fetch(webhookRequest(record), env), /Queue unavailable/);
});

test("oversized callbacks are rejected before persistence", async () => {
    const {handler, env, queued} = fixture();
    const response = await handler.fetch(new Request("https://staging.example/webhooks/paystack", {method: "POST", headers: {"Content-Length": "50000"}, body: "{}"}), env);
    assert.equal(response.status, 413);
    assert.equal(queued.length, 0);
});

test("the relay retries interrupted forwarding and acknowledges only a durable application acceptance", async () => {
    const {handler, env, webhook} = fixture();
    const record = signedRecord("paystack", {event: "charge.success", data: {domain: "test", reference: "D1-WEBHOOK-UAT-fixture", metadata: {family_id: 43}}}, webhook);
    for (const status of [503, 202]) {
        const result = {acked: false, retried: false};
        env.STAGING_CONTAINER.getByName = () => ({fetch: async () => new Response("result", {status})});
        await handler.queue({messages: [{id: "fixture", body: record, ack: () => { result.acked = true; }, retry: () => { result.retried = true; }}]}, env);
        assert.equal(result.acked, status === 202);
        assert.equal(result.retried, status === 503);
    }
});

test('outbound binding setup does not hold the Durable Object concurrency lock while waiting for the platform', async () => {
    const {container} = fixture();
    let blocked = false, intercepted = false;
    container.env.RUNTIME_PROFILE = 'production';
    container.env.CONTAINER_ENV = JSON.stringify({APP_ENV: 'production'});
    container.ready = false;
    container.ctx.container.running = false;
    container.ctx.container.start = () => { container.ctx.container.running = true; };
    container.ctx.blockConcurrencyWhile = async callback => { blocked = true; try { return await callback(); } finally { blocked = false; } };
    container.configureOutbound = async () => { assert.equal(blocked, false); intercepted = true; };
    await container.waitForReady();
    assert.equal(intercepted, true); assert.equal(container.ready, true);
});

test('completed native commands cannot receive a later timeout signal', async () => {
    const f = fixture();
    let signal;
    f.container.ctx.container.exec = async (_command, options) => {
        signal = options.signal;
        return {output: async () => ({exitCode: 0})};
    };
    assert.equal((await f.container.containerOutput(['php', '-v'], {}, 10)).exitCode, 0);
    await new Promise(resolve => setTimeout(resolve, 25));
    assert.equal(signal.aborted, false);
});

test('a native command still running at its deadline is aborted once without replay', async () => {
    const f = fixture();
    let executions = 0;
    f.container.ctx.container.exec = async (_command, options) => {
        executions++;
        return {output: () => new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('Command deadline exceeded')), {once: true}))};
    };
    await assert.rejects(f.container.containerOutput(['php', '-v'], {}, 10), /Command deadline exceeded/);
    assert.equal(executions, 1);
});

test('staging startup passes the isolated test keys to the container', async () => {
    const {container} = fixture();
    const keys = {PAYSTACK_PUBLIC_KEY: 'pk_test_fixture', PAYSTACK_SECRET_KEY: 'sk_test_fixture'};
    container.env.CONTAINER_ENV = JSON.stringify({APP_ENV: 'production', APP_KEY: 'preserved'});
    container.env.STAGING_PAYSTACK_TEST_ENV = JSON.stringify(keys);
    container.ready = false;
    container.ctx.container.running = false;
    let startedWith;
    container.ctx.container.start = options => { startedWith = options.env; container.ctx.container.running = true; };
    await container.waitForReady();
    assert.equal(container.ready, true);
    assert.equal(startedWith.APP_KEY, 'preserved');
    assert.equal(startedWith.PAYSTACK_PUBLIC_KEY, keys.PAYSTACK_PUBLIC_KEY);
    assert.equal(startedWith.PAYSTACK_SECRET_KEY, keys.PAYSTACK_SECRET_KEY);
    assert.equal(startedWith.D1_STAGING_PAYSTACK_TEST_CHECKOUT_ENABLED, 'true');
});
