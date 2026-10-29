import assert from "node:assert/strict";
import {timingSafeEqual} from "node:crypto";
import {readFileSync} from "node:fs";
import test from "node:test";
import {runInNewContext} from "node:vm";
import sqlHandler from "./production-sql-worker.mjs";
import {containerEnvironment} from "./container-env.mjs";
import {getProductionContainer, PRODUCTION_CONTAINER_NAME} from './production-container-target.mjs';

const source = readFileSync(new URL("./production-worker.mjs", import.meta.url), "utf8")
    .replace(/^import .*;\n/gm, "").replaceAll("export class ", "class ").replace("export default ", "const handler = ");
const load = () => runInNewContext(source + "\n({handler, FamilyFundsProductionContainer, ProductionDatabaseOutbound})", {
    Request, Response, Headers, URL, TextEncoder, TextDecoder, AbortSignal, timingSafeEqual, sqlHandler, containerEnvironment, getProductionContainer,
    WorkerEntrypoint: class { constructor(ctx, env) { this.ctx = ctx; this.env = env; } },
    FamilyFundsWebStagingContainer: class { constructor(ctx, env) { this.ctx = ctx; this.env = env; } },
});
const configuration = () => ({APP_ENV: "production", D1_PRODUCTION_ENABLED: "true", D1_STAGING_ENABLED: "false", D1_DATABASE: "familyfunds-production"});

test('OAuth migration inspection validates through a fixed Passport program without placing the bearer in command arguments or output', async () => {
    const {FamilyFundsProductionContainer: Container} = load();
    const config = {...configuration(), D1_MIGRATION_CHECKS: 'true', QUEUE_WORKER_ENABLED: 'false', D1_PRODUCTION_DELIVERY_ENABLED: 'false', D1_PRODUCTION_WEBHOOKS_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false'};
    const c = new Container({container: {running: true}}, {RUNTIME_PROFILE: 'production', CONTAINER_ENV: JSON.stringify(config), CANDIDATE_DIAGNOSTICS: '1'});
    c.ready = true;
    const bearer = 'reviewed.synthetic.bearer';
    let calls = 0;
    c.containerOutput = async (args, options) => {
        calls++;
        assert.equal(options.env.FF_MIGRATION_BEARER, bearer);
        assert.equal(options.stderr, 'ignore');
        assert.ok(!JSON.stringify(args).includes(bearer));
        assert.ok(args[2].includes('Auth::guard("api")'));
        assert.ok(args[2].includes('logging.default'));
        return {exitCode: 0, stdout: new TextEncoder().encode(JSON.stringify({authenticated: true, user_id: 1, client_id: 'synthetic-client', token_reference_sha256: 'f'.repeat(64), token: bearer}))};
    };
    const result = await c.inspectExistingOAuth(bearer);
    assert.equal(result.authenticated, true);
    assert.ok(!JSON.stringify(result).includes(bearer));
    for (const invalid of ['not-a-jwt', 'a.b.c\n', 'a'.repeat(8193)]) await assert.rejects(c.inspectExistingOAuth(invalid));
    assert.equal(calls, 1);
    c.env.CANDIDATE_DIAGNOSTICS = '0';
    await assert.rejects(c.inspectExistingOAuth(bearer), /disabled/);
    assert.equal(calls, 1);
});

test('migration storage checks use only a fixed private probe and suppress malformed command output', async () => {
    const {FamilyFundsProductionContainer: Container} = load();
    const config = {...configuration(), D1_MIGRATION_CHECKS: 'true', QUEUE_WORKER_ENABLED: 'false', D1_PRODUCTION_DELIVERY_ENABLED: 'false', D1_PRODUCTION_WEBHOOKS_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false'};
    const c = new Container({container: {running: true}}, {RUNTIME_PROFILE: 'production', CONTAINER_ENV: JSON.stringify(config), CANDIDATE_DIAGNOSTICS: '1'});
    c.ready = true;
    let command;
    c.containerOutput = async args => {command = args; return {exitCode: 0, stdout: new TextEncoder().encode('{"exit_code":0,"write_read_delete_verified":true}')};};
    assert.equal((await c.productionCommand('probe-storage')).write_read_delete_verified, true);
    assert.equal(command[1], '-r');
    assert.ok(command[2].includes('migration-probes/'));
    assert.ok(command[2].includes('finally'));
    c.containerOutput = async () => ({exitCode: 0, stdout: new TextEncoder().encode('private command output')});
    await assert.rejects(c.productionCommand('probe-storage'), /returned invalid JSON/);
    c.env.CANDIDATE_DIAGNOSTICS = '0';
    await assert.rejects(c.productionCommand('probe-storage'), /disabled/);
});

test('migration inspection uses process-local read-only configuration without transitioning the live FPM runtime', async () => {
    const {FamilyFundsProductionContainer: Container} = load();
    const config = {...configuration(), D1_MIGRATION_CHECKS: 'false', D1_PRODUCTION_DELIVERY_ENABLED: 'true'};
    const c = new Container({container: {running: true}}, {RUNTIME_PROFILE: 'production', CONTAINER_ENV: JSON.stringify(config), CANDIDATE_DIAGNOSTICS: '1', BUSINESS_WRITES_ENABLED: 'false', WEBHOOKS_ENABLED: 'false'});
    c.ready = true;
    let calls = 0;
    c.containerOutput = async args => {
        calls++;
        assert.equal(args[1], '-r');
        assert.ok(args[2].includes('"d1-production.delivery_enabled"=>false'));
        assert.ok(args[2].includes('"d1-production.provider_read_only_checks"=>true'));
        assert.ok(args[2].includes('inspectMigration()'));
        assert.ok(args[2].includes('Inspection changed persistent configuration'));
        return {exitCode: 0, stdout: new TextEncoder().encode('{"exit_code":0,"inspection_configuration_ephemeral":true}')};
    };
    assert.equal((await c.productionCommand('inspect-migration')).inspection_configuration_ephemeral, true);
    assert.equal(JSON.parse(c.env.CONTAINER_ENV).D1_MIGRATION_CHECKS, 'false');
    c.env.BUSINESS_WRITES_ENABLED = 'true';
    await assert.rejects(c.productionCommand('inspect-migration'), /disabled/);
    assert.equal(calls, 1);
});

test('only an unavailable isolated candidate binding can be explicitly reset without replaying work', async () => {
    const {FamilyFundsProductionContainer: Container} = load();
    let resets = 0;
    const ctx = {abort: () => { resets++; throw new Error('object reset'); }};
    const env = {RUNTIME_PROFILE: 'production', CONTAINER_ENV: JSON.stringify(configuration()), CANDIDATE_DIAGNOSTICS: '1'};
    await assert.rejects(() => new Container(ctx, env).resetUnavailableContainerBinding(), /object reset/);
    assert.equal(resets, 1);
    await assert.rejects(() => new Container({...ctx, container: {}}, env).resetUnavailableContainerBinding(), /Unavailable candidate/);
    await assert.rejects(() => new Container(ctx, {...env, CANDIDATE_DIAGNOSTICS: '0'}).resetUnavailableContainerBinding(), /Unavailable candidate/);
    assert.equal(resets, 1);
});

test("candidate authentication preserves OAuth bearer tokens and removes privileged routing headers", async () => {
    const {handler} = load();
    const calls = [];
    const env = {DATABASE_NAME: "familyfunds-production", BUSINESS_WRITES_ENABLED: 'true', CONTAINER_ENV: JSON.stringify(configuration()), CANDIDATE_TOKEN: "p".repeat(40), CANDIDATE_AUTHORIZATION: "Basic " + "b".repeat(40),
        PRODUCTION_CONTAINER: {getByName: name => ({fetch: async request => { calls.push({name, request}); return new Response("streamed application"); }})}};
    assert.equal((await handler.fetch(new Request("https://candidate/oauth/authorize"), env)).status, 401);
    assert.equal(calls.length, 0);
    const response = await handler.fetch(new Request("https://candidate/oauth/authorize", {headers: {
        Authorization: "Bearer existing-access-token", "X-FamilyFunds-Preview": env.CANDIDATE_TOKEN,
        "X-FamilyFunds-Relay": "spoofed", "X-Staging-Admin": "spoofed", "X-D1-Staging-Candidate": "spoofed",
    }}), env);
    assert.equal(await response.text(), "streamed application");
    assert.equal(response.headers.get("X-Robots-Tag"), "noindex, nofollow, noarchive");
    assert.equal(calls[0].name, PRODUCTION_CONTAINER_NAME);
    assert.equal(calls[0].request.headers.get("Authorization"), "Bearer existing-access-token");
    for (const key of ["X-FamilyFunds-Preview", "X-FamilyFunds-Relay", "X-Staging-Admin", "X-D1-Staging-Candidate"]) assert.equal(calls[0].request.headers.get(key), null);
    await handler.fetch(new Request("https://candidate/", {headers: {Authorization: env.CANDIDATE_AUTHORIZATION}}), env);
    assert.equal(calls[1].request.headers.get("Authorization"), null);
    env.TRAFFIC_ENABLED = "true";
    assert.equal((await handler.fetch(new Request("https://other-host/"), env)).status, 404);
    assert.equal((await handler.fetch(new Request("https://familyfunds.app/__staging_admin/runtime"), env)).status, 404);
});

test('live cached configuration keeps business requests fenced until the separate writer activation', async () => {
    const {handler} = load();
    let calls = 0;
    const env = {DATABASE_NAME: 'familyfunds-production', TRAFFIC_ENABLED: 'true', CONTAINER_ENV: JSON.stringify({...configuration(), D1_MIGRATION_CHECKS: 'false'}),
        PRODUCTION_CONTAINER: {getByName: () => ({fetch: async () => {calls++;return new Response('application');}})}};
    const payment = () => new Request('https://familyfunds.app/family/payments', {method: 'POST'});
    assert.equal((await handler.fetch(payment(), env)).status, 423);
    assert.equal((await handler.fetch(new Request('https://familyfunds.app/family/pay/callback'), env)).status, 423);
    assert.equal(calls, 0);
    assert.equal((await handler.fetch(new Request('https://familyfunds.app/login', {method: 'POST'}), env)).status, 200);
    env.BUSINESS_WRITES_ENABLED = 'true';
    const result = await handler.fetch(payment(), env);
    assert.equal(result.status, 200);
    assert.equal(result.headers.get('X-FamilyFunds-Write-Mode'), 'live');
    assert.equal(calls, 2);
});

test('the real-data edge blocks mutating GET routes and encoded variants before PHP while allowing existing files and login', async () => {
    const {handler} = load();
    let calls = 0;
    const env = {DATABASE_NAME: 'familyfunds-production', CONTAINER_ENV: JSON.stringify({...configuration(), D1_MIGRATION_CHECKS: 'true'}), CANDIDATE_TOKEN: 'p'.repeat(40),
        PRODUCTION_CONTAINER: {getByName: () => ({fetch: async () => {calls++;return new Response('allowed');}})}};
    const headers = {'X-FamilyFunds-Preview': env.CANDIDATE_TOKEN};
    for (const path of ['/family/reports/export', '/family/reports%2Fexport/', '/family/contributions/my/statement', '/family/payment-batches/1/receipt', '/family/pay/callback', '/family/subscription/callback', '/invitations/token/accept', '/oauth/authorize', '/email/verify/1/signature']) {
        assert.equal((await handler.fetch(new Request('https://mirror' + path, {headers}), env)).status, 423, path);
    }
    assert.equal(calls, 0);
    assert.equal((await handler.fetch(new Request('https://mirror/family/payments', {method: 'POST', headers}), env)).status, 423);
    assert.equal((await handler.fetch(new Request('https://mirror/family/reports/artifacts/1', {headers}), env)).status, 200);
    assert.equal((await handler.fetch(new Request('https://mirror/login', {method: 'POST', headers}), env)).status, 200);
});

test("production construction rejects mixed profiles and installs the private outbound binding", async () => {
    const {FamilyFundsProductionContainer: Container} = load();
    const intercepted = [];
    const worker = {};
    const ctx = {exports: {ProductionDatabaseOutbound: options => {
        assert.deepEqual(JSON.parse(JSON.stringify(options)), {props: {}});
        return worker;
    }}, container: {interceptOutboundHttps: async (...args) => intercepted.push(args)}};
    const env = {RUNTIME_PROFILE: "production", CONTAINER_ENV: JSON.stringify(configuration())};
    const container = new Container(ctx, env);
    await container.configureOutbound();
    assert.deepEqual(intercepted, [["familyfunds-production.d1.internal", worker]]);
    await assert.rejects(() => container.scheduledProof());
    for (const change of [{APP_ENV: "d1-staging"}, {D1_STAGING_ENABLED: "true"}, {D1_DATABASE: "familyfunds-d1-scale-lab"}]) {
        assert.throws(() => new Container(ctx, {...env, CONTAINER_ENV: JSON.stringify({...configuration(), ...change})}));
    }
    assert.throws(() => new Container(ctx, {...env, OPTIMIZED_BOOTSTRAP: "true"}));
    for (const name of ['FF_RCLONE_CONFIG', 'R2_BACKUP_ACCESS_KEY_ID', 'R2_BACKUP_SECRET_ACCESS_KEY', 'BACKUP_R2_ACCESS_KEY_ID', 'BACKUP_R2_SECRET_ACCESS_KEY', 'BACKUP_KEY', 'GOOGLE_DRIVE_TOKEN']) {
        assert.throws(() => new Container(ctx, {...env, CONTAINER_ENV: JSON.stringify({...configuration(), [name]: 'forbidden'})}), /Offsite credentials/);
    }
    assert.throws(() => new Container(ctx, {...env, CONTAINER_ENV: JSON.stringify({...configuration(), BACKUP_GOOGLE_DRIVE_ENABLED: 'true'})}), /Offsite credentials/);
    const paused = {...configuration(), D1_MIGRATION_CHECKS: 'true', QUEUE_WORKER_ENABLED: 'false', D1_PRODUCTION_DELIVERY_ENABLED: 'false', D1_PRODUCTION_WEBHOOKS_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false'};
    assert.doesNotThrow(() => new Container(ctx, {...env, CONTAINER_ENV: JSON.stringify(paused)}));
    for (const name of ['QUEUE_WORKER_ENABLED', 'D1_PRODUCTION_DELIVERY_ENABLED', 'D1_PRODUCTION_WEBHOOKS_ENABLED', 'NIGHTWATCH_ENABLED']) {
        assert.throws(() => new Container(ctx, {...env, CONTAINER_ENV: JSON.stringify({...paused, [name]: 'true'})}), /Migration mirror background/);
    }
});

test("the private SQL entrypoint accepts only its virtual host and injects a Worker-only credential", async () => {
    const {ProductionDatabaseOutbound} = load();
    const queries = [];
    const env = {DATABASE_NAME: "familyfunds-production", SQL_GATEWAY_TOKEN: "s".repeat(40), DB: {prepare: sql => ({bind: (...params) => ({all: async () => { queries.push({sql, params}); return {results: [{answer: 42}]}; }})})}};
    const entrypoint = new ProductionDatabaseOutbound({}, env);
    const response = await entrypoint.fetch(new Request("https://familyfunds-production.d1.internal/query", {method: "POST", body: JSON.stringify({sql: "select ? as answer", params: [42]}), headers: {Authorization: "untrusted PHP marker"}}));
    assert.equal(response.status, 200);
    assert.equal((await response.json()).result.results[0].answer, 42);
    assert.deepEqual(queries, [{sql: "select ? as answer", params: [42]}]);
    assert.equal((await entrypoint.fetch(new Request("https://other-host/query"))).status, 403);
});

test("SQL boundary rejects unauthenticated, malformed and oversized requests before database execution", async () => {
    const env = {DATABASE_NAME: "familyfunds-production", SQL_TOKEN: "s".repeat(40), DB: {prepare: () => { throw new Error("must not execute"); }}};
    const request = (body, auth = "Bearer " + env.SQL_TOKEN) => new Request("https://familyfunds-production.d1.internal/query", {method: "POST", body, headers: {Authorization: auth}});
    assert.equal((await sqlHandler.fetch(request("{}", "wrong"), env)).status, 401);
    for (const body of ["invalid JSON", JSON.stringify({sql: "select ?", params: [Number.MAX_SAFE_INTEGER + 1]}), JSON.stringify({sql: "select ?", params: [{}]})]) assert.equal((await sqlHandler.fetch(request(body), env)).status, 400);
    assert.equal((await sqlHandler.fetch(request(" ".repeat(8_000_001)), env)).status, 413);
    assert.equal((await sqlHandler.fetch(new Request("https://familyfunds-production.d1.internal/manifest", {headers: {Authorization: "Bearer " + env.SQL_TOKEN}}), env)).status, 404);
});

test("SQL unknown outcomes are never retried and provider details are not returned", async () => {
    let calls = 0;
    const env = {DATABASE_NAME: "familyfunds-production", SQL_TOKEN: "s".repeat(40), DB: {prepare: () => ({bind: () => ({all: async () => { calls++; throw new Error("sensitive query or parameter"); }})})}};
    const response = await sqlHandler.fetch(new Request("https://familyfunds-production.d1.internal/query", {method: "POST", body: JSON.stringify({sql: "insert into receipts values(?)", params: [1]}), headers: {Authorization: "Bearer " + env.SQL_TOKEN}}), env);
    assert.equal(response.status, 503); assert.equal(calls, 1);
    assert.deepEqual(await response.json(), {error: "D1 operation failed"});
});
