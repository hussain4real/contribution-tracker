import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {runInNewContext} from 'node:vm';

const privateFlags = {TRAFFIC_ENABLED: 'false', BUSINESS_WRITES_ENABLED: 'false', WEBHOOKS_ENABLED: 'false'};

test('the managed candidate selects only its pinned image and the reviewed resource size', () => {
    const source = readFileSync(new URL('./production-managed-worker.mjs', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '').replace(/^export \{.*;\n/gm, '').replace('export class ', 'class ').replace(/export default \{[\s\S]*$/, '');
    const Container = runInNewContext(source + '\nFamilyFundsManagedProductionContainer', {FamilyFundsProductionContainer: class {constructor(ctx, env) { this.ctx = ctx; this.env = env; }}});
    const image = 'registry.cloudflare.com/40344948b73fa9da3008958fdc3faf12/familyfunds-production@' + JSON.parse(readFileSync(new URL('./production-flat-image-package-result.json', import.meta.url), 'utf8')).digest;
    const revision = source.match(/const MANAGED_RELEASE = '([^']+)'/)[1];
    const instance = new Container({container: {images: {base: image}}}, {...privateFlags, CONTAINER_IMAGE: image, RUNTIME_REVISION: revision, PRODUCTION_CUTOVER_STAGE: 'read-only'});
    const env = {APP_ENV: 'production'};
    assert.deepEqual(JSON.parse(JSON.stringify(instance.startupOptions(env))), {env, enableInternet: true, image, instance: {vcpu: 2, memoryMib: 6144, diskMb: 8000}});
    instance.env.CONTAINER_IMAGE = image.replace('production@', 'staging@');
    assert.throws(() => instance.startupOptions(env));
});

function managedClass(base) {
    const source = readFileSync(new URL('./production-managed-worker.mjs', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '').replace(/^export \{.*;\n/gm, '').replace('export class ', 'class ').replace(/export default \{[\s\S]*$/, '');
    return {Container: runInNewContext(source + '\nFamilyFundsManagedProductionContainer', {FamilyFundsProductionContainer: base, Response}),
        release: source.match(/const MANAGED_RELEASE = '([^']+)'/)[1], image: source.match(/const MANAGED_IMAGE = '([^']+)'/)[1]};
}

function expectedRuntime(image) {
    return {running: true, image, production: {app_url: 'https://familyfunds.app', passkey_rp_id: 'familyfunds.app',
        passkey_origins: ['https://familyfunds.app'], production_enabled: true, database_driver: 'd1', migration_checks: true,
        delivery_enabled: false, webhooks_enabled: false, nightwatch_enabled: false, mail_transport: 'array',
        queue_worker_enabled: false, queue_worker_processes: 0, offsite_file_present: false, drive_sync_enabled: false}};
}

test('activation phases are pinned in code, reported by the edge and reject mismatched flag bindings', async () => {
    const original = readFileSync(new URL('./production-managed-worker.mjs', import.meta.url), 'utf8');
    for (const [phase, flags] of [
        ['private', privateFlags],
        ['read-only', {...privateFlags, TRAFFIC_ENABLED: 'true'}],
        ['active', {TRAFFIC_ENABLED: 'true', BUSINESS_WRITES_ENABLED: 'true', WEBHOOKS_ENABLED: 'true'}],
    ]) {
        const source = original.replace("const MANAGED_ACTIVATION = 'private';", `const MANAGED_ACTIVATION = '${phase}';`)
            .replace(/^import .*;\n/gm, '').replace(/^export \{.*;\n/gm, '').replace('export class ', 'class ').replace('export default ', 'const managedHandler = ');
        const handler = runInNewContext(source + '\nmanagedHandler', {FamilyFundsProductionContainer: class {}, Response,
            handler: {fetch: async () => new Response('application')}});
        const env = {...flags, RUNTIME_REVISION: source.match(/const MANAGED_RELEASE = '([^']+)'/)[1], PRODUCTION_CUTOVER_STAGE: 'read-only'};
        const result = await handler.fetch(new Request('https://familyfunds.app/up'), env, {});
        assert.equal(result.headers.get('X-FamilyFunds-Activation-Phase'), phase);
        await assert.rejects(handler.fetch(new Request('https://familyfunds.app/up'), {...env, BUSINESS_WRITES_ENABLED: flags.BUSINESS_WRITES_ENABLED === 'true' ? 'false' : 'true'}, {}), /activation configuration mismatch/);
    }
});

test('a configuration generation change destroys the previous VM before booting and attesting the new configuration', async () => {
    const order = [];
    const {Container, release, image} = managedClass(class {
        constructor(ctx, env) { this.ctx = ctx; this.env = env; }
        async waitForReady() { order.push('start-and-ready'); this.ready = true; }
        async runtime() { order.push('attest'); return expectedRuntime(image); }
    });
    let generation = 'previous-live-release';
    const ctx = {container: {running: true, async destroy() { order.push('destroy-old'); this.running = false; }},
        storage: {async get() { return generation; }, async put(_key, value) { generation = value; order.push('persist-generation'); }}};
    const instance = new Container(ctx, {...privateFlags, RUNTIME_REVISION: release, PRODUCTION_CUTOVER_STAGE: 'read-only'});
    instance.configureOutbound = async () => { order.push('private-https'); };
    await instance.waitForReady();
    assert.deepEqual(order, ['destroy-old', 'persist-generation', 'private-https', 'start-and-ready', 'attest']);
    assert.equal(instance.ready, true);
    assert.equal(instance.configurationAttested, true);
    assert.equal(generation, release);
});

test('an existing generation can cold boot again without destroying a valid VM', async () => {
    const {Container, release, image} = managedClass(class {
        constructor(ctx, env) { this.ctx = ctx; this.env = env; }
        async waitForReady() { this.ready = true; }
        async runtime() { return expectedRuntime(image); }
    });
    const instance = new Container({container: {running: true, destroy() { throw new Error('unexpected destruction'); }},
        storage: {async get() { return release; }}}, {...privateFlags, RUNTIME_REVISION: release, PRODUCTION_CUTOVER_STAGE: 'read-only'});
    instance.configureOutbound = async () => {};
    await instance.waitForReady();
    assert.equal(instance.ready, true);
});

test('stale cached live configuration is rejected and the VM is stopped instead of serving a read-only release', async () => {
    let destroyed = false;
    const {Container, release, image} = managedClass(class {
        constructor(ctx, env) { this.ctx = ctx; this.env = env; }
        async waitForReady() { this.ready = true; }
        async runtime() { const result = expectedRuntime(image); result.production.delivery_enabled = true; return result; }
    });
    const instance = new Container({container: {running: true, async destroy() { destroyed = true; }},
        storage: {async get() { return release; }}}, {...privateFlags, RUNTIME_REVISION: release, PRODUCTION_CUTOVER_STAGE: 'read-only'});
    instance.configureOutbound = async () => {};
    await assert.rejects(instance.waitForReady(), /configuration attestation failed/);
    assert.equal(instance.ready, false);
    assert.equal(instance.configurationAttested, false);
    assert.equal(destroyed, true);
    assert.throws(() => new Container({}, {RUNTIME_REVISION: release, PRODUCTION_CUTOVER_STAGE: 'live'}), /release mismatch/);
});
