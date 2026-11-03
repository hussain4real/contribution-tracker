import assert from 'node:assert/strict';
import {createHmac, randomUUID, webcrypto} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import {runInNewContext} from 'node:vm';

const source = readFileSync(new URL('./staging-replacement-web.mjs', import.meta.url), 'utf8')
    .replace(/^import .*?;$/gm, '').replaceAll('export class ', 'class ').replace('export default ', 'const handler = ');
const stagingSource = readFileSync(new URL('./container-worker.mjs', import.meta.url), 'utf8')
    .replace(/^import .*?;$/gm, '').replace('export class ', 'class ').replace('export default ', 'const stagingHandler = ');
const shared = runInNewContext(stagingSource + '\n({stagingHandler, Container: FamilyFundsWebStagingContainer})', {
    DurableObject: class { constructor(ctx, env) { this.ctx = ctx; this.env = env; } },
    TextEncoder, TextDecoder, Request, Response, URL, console, crypto: {randomUUID, subtle: webcrypto.subtle},
});
const database = 'familyfunds-d1-staging-clone-20261103t092540z';
const image = 'registry.cloudflare.com/40344948b73fa9da3008958fdc3faf12/familyfunds-staging-replacement@sha256:' + 'a'.repeat(64);

function fixture() {
    const calls = [];
    class Base {
        constructor(ctx, env) { this.ctx = ctx; this.env = env; }
        async waitForReady() { calls.push('start'); }
    }
    Base.webhookConfig = shared.Container.webhookConfig;
    const {Container, handler, Maintenance} = runInNewContext(source + '\n({Container: FamilyFundsReplacementStagingContainer, handler, Maintenance: ReplacementMaintenance})',
        {...shared, FamilyFundsWebStagingContainer: Base, WorkerEntrypoint: Base, URL, Request, Response});
    const files = {bucket: database + '-files', accessKeyId: 'a'.repeat(32), secretAccessKey: 'b'.repeat(64)};
    const env = {CONTAINER_IMAGE: image, CONTAINER_ENV: '{}', RUNTIME_PROFILE: 'replacement-preflight', STAGING_ADMISSION_CONTROL: '1',
        REPLACEMENT_SQL: {fetch() {}}, REPLACEMENT_FILES_CREDENTIAL: {get: async () => JSON.stringify(files)},
        REPLACEMENT_APP_KEY: 'base64:' + 'a'.repeat(43) + '=', REPLACEMENT_SQL_TOKEN: 'c'.repeat(64)};
    const ctx = {container: {images: {base: image}, running: false, interceptOutboundHttps: async (...args) => calls.push(args)}};
    return {Container, handler, Maintenance, env, ctx, files, calls};
}

test('preflight starts only after private HTTPS interception and uses the reserved destinations', async () => {
    const {Container, env, ctx, calls, files} = fixture();
    const candidate = new Container(ctx, env);
    await candidate.waitForReady();
    assert.deepEqual(calls, [['familyfunds-d1-replacement-sql.familyfunds-lab.workers.dev', env.REPLACEMENT_SQL], 'start']);
    const options = await candidate.startupOptions();
    assert.equal(options.image, image);
    assert.equal(options.env.D1_STAGING_DATABASE, database);
    assert.equal(options.env.D1_STAGING_URL, 'https://' + calls[0][0]);
    assert.equal(options.env.D1_STAGING_R2_ADMISSION, '1');
    assert.equal(options.env.D1_STAGING_R2_BUCKET, files.bucket);
    assert.equal(options.env.D1_STAGING_R2_ACCESS_KEY_ID, files.accessKeyId);
    assert.equal(options.env.D1_STAGING_TOKEN, env.REPLACEMENT_SQL_TOKEN);
    for (const key of ['STAGING_QUEUE_WORKER_ENABLED','QUEUE_WORKER_ENABLED','D1_STAGING_WEBHOOKS_ENABLED','D1_STAGING_DELIVERY_ENABLED']) assert.equal(options.env[key], 'false');
    await assert.rejects(candidate.scheduledProof(), /no maintenance/);
});

test('wrong image, credentials, staging profile and bootstrap overrides stop preflight', async () => {
    const {Container, env, ctx, files} = fixture();
    for (const invalid of [{CONTAINER_ENV: '{"DB_PASSWORD":"old"}'}, {CONTAINER_ENV_PARTS:'1'}, {OPTIMIZED_BOOTSTRAP:'v1'},
        {RUNTIME_PROFILE:'production'}, {STAGING_ADMISSION_CONTROL:'0'}, {REPLACEMENT_SQL:undefined}]) {
        assert.throws(() => new Container(ctx, {...env, ...invalid}), /configuration/);
    }
    const candidate = new Container(ctx, env);
    files.bucket = 'familyfunds-d1-staging-private';
    await assert.rejects(candidate.startupOptions(), /file credentials/);
    ctx.container.images.base = 'other';
    await assert.rejects(candidate.startupOptions(), /image or credentials/);
    ctx.container.running = true;
    ctx.container.inspect = async () => ({image:'other'});
    await assert.rejects(candidate.waitForReady(), /running replacement image/);
});

test('private preflight only forwards its fixed health and connectivity checks', async () => {
    const {handler} = fixture();
    const seen = [];
    let connected = false;
    const env = {REPLACEMENT_CONTAINER: {getByName: name => ({checkConnections: async () => connected, fetch: req => { seen.push([name, req.url, [...req.headers]]); return new Response('ok'); }})}};
    for (const [path, method] of [['/login','GET'],['/up?task=run','GET'],['/up','POST']]) {
        assert.equal((await handler.fetch(new Request('https://caller' + path, {method}), env)).status, 404);
    }
    assert.equal((await handler.fetch(new Request('https://caller/up', {headers:{Authorization:'discard'}}), env)).status, 200);
    assert.equal((await handler.fetch(new Request('https://caller/connections'), env)).status, 503);
    connected = true;
    assert.equal((await handler.fetch(new Request('https://caller/connections'), env)).status, 200);
    assert.deepEqual(seen, [['replacement-staging', 'https://replacement-staging.internal/up', []]]);
});

test('private maintenance binding only addresses the replacement object in rehearsal mode', async () => {
    const {Maintenance, env} = fixture();
    const forwarded = [];
    env.REPLACEMENT_CONTAINER = {getByName: name => ({scheduledProof: async (...args) => forwarded.push([name, ...args])})};
    const service = new Maintenance({}, env);
    const task = ['remind25', 50, '2026-11-01T08:00:00.000Z'];
    await assert.rejects(service.scheduledProof(...task), /disabled/);
    assert.equal(forwarded.length, 0);
    env.RUNTIME_PROFILE = 'replacement-rehearsal';
    await service.scheduledProof(...task);
    assert.deepEqual(forwarded, [['replacement-staging', ...task]]);
});

test('signed test callbacks enter only the replacement queue and relay with the private token', async () => {
    const {Container, handler, env, ctx} = fixture();
    const queued = [], calls = [], stored = new Map();
    const config = {enabled: true, database, family_id: 43, paystack_test_key: 'sk_test_fixture',
        meta_app_secret: 'b'.repeat(32), meta_waba: 'test-account', meta_phone: 'test-phone', recipient: 'test-recipient', relay_token: 'a'.repeat(64)};
    Object.assign(env, {RUNTIME_PROFILE: 'replacement-webhook-rehearsal', REPLACEMENT_WEBHOOK_SECRETS: JSON.stringify(config),
        REPLACEMENT_WEBHOOK_QUEUE: {send: async record => queued.push(record)}});
    const receiver = new shared.Container({blockConcurrencyWhile: action => action(), storage: {
        get: key => stored.get(key), put: (key, value) => stored.set(key, value), delete: key => stored.delete(key),
    }}, env);
    let status = 503;
    env.REPLACEMENT_CONTAINER = {getByName(name) {
        assert.equal(name, 'replacement-staging');
        return {enqueueWebhook: record => receiver.enqueueWebhook(record), fetch: async req => {
            calls.push([req.url, req.headers.get('X-D1-Staging-Relay'), await req.text()]);
            return new Response(null, {status});
        }};
    }};
    const options = await new Container(ctx, env).startupOptions();
    assert.equal(options.env.D1_STAGING_WEBHOOKS_ENABLED, 'true');
    assert.equal(options.env.D1_STAGING_RELAY_TOKEN, config.relay_token);
    assert.equal(options.env.D1_STAGING_DELIVERY_ENABLED, 'false');
    for (const [provider, payload, secret, algorithm, header, prefix] of [
        ['paystack', {event:'charge.success', data:{domain:'test', reference:'D1-WEBHOOK-UAT-fixture', metadata:{family_id:43}}},
            config.paystack_test_key, 'sha512', 'X-Paystack-Signature', ''],
        ['whatsapp', {object:'whatsapp_business_account', entry:[{id:config.meta_waba, changes:[{field:'messages', value:{
            metadata:{phone_number_id:config.meta_phone}, statuses:[{id:'fixture', status:'delivered', recipient_id:config.recipient}]}}]}]},
            config.meta_app_secret, 'sha256', 'X-Hub-Signature-256', 'sha256='],
    ]) {
        const body = JSON.stringify(payload);
        const signature = prefix + createHmac(algorithm, secret).update(body).digest('hex');
        const request = () => new Request('https://caller/webhooks/' + provider, {method:'POST', headers:{[header]:signature}, body});
        assert.equal((await handler.fetch(request(), {...env, RUNTIME_PROFILE:'replacement-preflight'})).status, 404);
        assert.equal((await handler.fetch(request(), env)).status, provider === 'paystack' ? 202 : 200);
        const record = queued.shift();
        assert.equal(record.database, database);
        assert.equal(stored.size, 0);
        let acks = 0, retries = 0;
        const message = {id:'fixture', body:record, ack() { acks++; }, retry() { retries++; }};
        const batch = {queue:'familyfunds-replacement-webhook', messages:[message]};
        await assert.rejects(handler.queue({...batch, queue:'original'}, env), /Unexpected/);
        await assert.rejects(handler.queue(batch, {...env, RUNTIME_PROFILE:'replacement-preflight'}), /disabled/);
        const previousCalls = calls.length;
        for (const invalid of [{...record, database:undefined}, {...record, raw:body + ' '}]) {
            await handler.queue({...batch, messages:[{...message, body:invalid}]}, env);
        }
        assert.equal(calls.length, previousCalls);
        for (status of [503, 200]) await handler.queue(batch, env);
        assert.deepEqual([acks, retries], [1,3]);
        assert.deepEqual(calls.at(-1), ['https://replacement-staging.internal/webhooks/' + provider, config.relay_token, body]);
    }
});
