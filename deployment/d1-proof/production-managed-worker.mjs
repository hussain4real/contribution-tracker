import handler, {FamilyFundsProductionContainer} from './production-worker.mjs';
export {ProductionDatabaseOutbound} from './production-worker.mjs';

const MANAGED_IMAGE = 'registry.cloudflare.com/40344948b73fa9da3008958fdc3faf12/familyfunds-production@sha256:a3659e49a842c84310eb370f846c5ff8a3a839ec75dff4d2f5962635be200ac7';
const MANAGED_RELEASE = 'production-v28-read-only';
const MANAGED_STAGE = 'read-only';
const MANAGED_ACTIVATION = 'private';

function assertRelease(env) {
    if (env.RUNTIME_REVISION !== MANAGED_RELEASE || env.PRODUCTION_CUTOVER_STAGE !== MANAGED_STAGE) {
        throw new Error('Managed configuration release mismatch');
    }
    if (!['private', 'read-only', 'active'].includes(MANAGED_ACTIVATION)
        || env.TRAFFIC_ENABLED !== String(MANAGED_ACTIVATION !== 'private')
        || env.BUSINESS_WRITES_ENABLED !== String(MANAGED_ACTIVATION === 'active')
        || env.WEBHOOKS_ENABLED !== String(MANAGED_ACTIVATION === 'active')) {
        throw new Error('Managed activation configuration mismatch');
    }
}

function matchesConfiguration(result) {
    const config = result.production;
    const live = MANAGED_STAGE === 'live';
    return result.running === true && result.image === MANAGED_IMAGE && config?.app_url === 'https://familyfunds.app'
        && config.passkey_rp_id === 'familyfunds.app' && JSON.stringify(config.passkey_origins) === '["https://familyfunds.app"]'
        && config.production_enabled === true && config.database_driver === 'd1'
        && config.migration_checks === !live && config.delivery_enabled === live && config.webhooks_enabled === live
        && config.nightwatch_enabled === live && config.mail_transport === (live ? 'cloudflare' : 'array')
        && config.queue_worker_enabled === false && config.queue_worker_processes === 0
        && config.offsite_file_present === false && config.drive_sync_enabled === false;
}

export class FamilyFundsManagedProductionContainer extends FamilyFundsProductionContainer {
    constructor(ctx, env) {
        assertRelease(env);
        super(ctx, env);
        this.configurationAttested = false;
    }

    async waitForReady() {
        const previous = await this.ctx.storage.get('managed-runtime-generation');
        if (previous !== MANAGED_RELEASE) {
            if (this.ctx.container.running) await this.ctx.container.destroy();
            this.ready = false;
            await this.ctx.storage.put('managed-runtime-generation', MANAGED_RELEASE);
        }
        await this.configureOutbound();
        await super.waitForReady();
        if (!this.ready) return;
        this.ready = false;
        const actual = await super.runtime();
        if (!matchesConfiguration(actual)) {
            this.configurationAttested = false;
            await this.ctx.container.destroy();
            throw new Error('Managed runtime configuration attestation failed');
        }
        this.configurationAttested = true;
        this.ready = true;
    }

    async fetch(request) {
        if (!this.configurationAttested) this.ready = false;
        return super.fetch(request);
    }

    async runtime() {
        const actual = await super.runtime();
        return {...actual, managed: {release: MANAGED_RELEASE, stage: MANAGED_STAGE,
            stored_generation: await this.ctx.storage.get('managed-runtime-generation') ?? null,
            configuration_matches: actual.running ? matchesConfiguration(actual) : null}};
    }

    startupOptions(env) {
        const image = this.ctx.container.images?.base;
        assertRelease(this.env);
        if (typeof image !== 'string' || image !== MANAGED_IMAGE || image !== this.env.CONTAINER_IMAGE
            || !/^registry\.cloudflare\.com\/40344948b73fa9da3008958fdc3faf12\/familyfunds-production@sha256:[a-f0-9]{64}$/.test(image)) throw new Error('Managed candidate image mismatch');
        return {env, enableInternet: true, image, instance: {vcpu: 2, memoryMib: 6144, diskMb: 8000}};
    }
}

export default {
    ...handler,
    async fetch(request, env, ctx) {
        assertRelease(env);
        const response = await handler.fetch(request, env, ctx);
        const result = new Response(response.body, response);
        result.headers.set('X-FamilyFunds-Runtime-Revision', MANAGED_RELEASE);
        result.headers.set('X-FamilyFunds-Activation-Phase', MANAGED_ACTIVATION);
        return result;
    },
};
