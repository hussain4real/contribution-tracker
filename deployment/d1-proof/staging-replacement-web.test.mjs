import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import {runInNewContext} from 'node:vm';

const source = readFileSync(new URL('./staging-replacement-web.mjs', import.meta.url), 'utf8')
    .replace(/^import .*?;$/m, '').replace('export class ', 'class ').replace('export default ', 'const handler = ');
const database = 'familyfunds-d1-staging-clone-20261103t092540z';
const image = 'registry.cloudflare.com/40344948b73fa9da3008958fdc3faf12/familyfunds-staging-replacement@sha256:' + 'a'.repeat(64);

function fixture() {
    const calls = [];
    class Base {
        constructor(ctx, env) { this.ctx = ctx; this.env = env; }
        async waitForReady() { calls.push('start'); }
    }
    const {Container, handler} = runInNewContext(source + '\n({Container: FamilyFundsReplacementStagingContainer, handler})',
        {FamilyFundsWebStagingContainer: Base, URL, Request, Response});
    const files = {bucket: database + '-files', accessKeyId: 'a'.repeat(32), secretAccessKey: 'b'.repeat(64)};
    const env = {CONTAINER_IMAGE: image, CONTAINER_ENV: '{}', RUNTIME_PROFILE: 'replacement-preflight', STAGING_ADMISSION_CONTROL: '1',
        REPLACEMENT_SQL: {fetch() {}}, REPLACEMENT_FILES_CREDENTIAL: {get: async () => JSON.stringify(files)},
        REPLACEMENT_APP_KEY: 'base64:' + 'a'.repeat(43) + '=', REPLACEMENT_SQL_TOKEN: 'c'.repeat(64)};
    const ctx = {container: {images: {base: image}, running: false, interceptOutboundHttps: async (...args) => calls.push(args)}};
    return {Container, handler, env, ctx, files, calls};
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
