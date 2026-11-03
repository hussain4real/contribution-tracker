import {WorkerEntrypoint} from 'cloudflare:workers';
import stagingHandler, {FamilyFundsWebStagingContainer} from './container-worker.mjs';

const database = 'familyfunds-d1-staging-clone-20261103t092540z';
const gateway = 'familyfunds-d1-replacement-sql.familyfunds-lab.workers.dev';
const stagingOrigin = 'https://d1-staging.familyfunds.app';
const acceptanceCookie = 'familyfunds_replacement_acceptance_session';
const imagePattern = /^registry\.cloudflare\.com\/40344948b73fa9da3008958fdc3faf12\/familyfunds-staging-replacement@sha256:[0-9a-f]{64}$/;

export class FamilyFundsReplacementStagingContainer extends FamilyFundsWebStagingContainer {
    constructor(ctx, env) {
        if (env.CONTAINER_ENV !== '{}' || env.CONTAINER_ENV_PARTS !== undefined || env.OPTIMIZED_BOOTSTRAP !== undefined
            || !['replacement-preflight', 'replacement-rehearsal', 'replacement-webhook-rehearsal', 'replacement-acceptance', 'replacement-live'].includes(env.RUNTIME_PROFILE) || env.STAGING_ADMISSION_CONTROL !== '1'
            || !imagePattern.test(env.CONTAINER_IMAGE) || !env.REPLACEMENT_SQL?.fetch) {
            throw new Error('Invalid replacement staging configuration');
        }
        super(ctx, env);
    }

    async waitForReady() {
        if (this.ctx.container.running && (await this.ctx.container.inspect()).image !== this.env.CONTAINER_IMAGE) {
            throw new Error('Unexpected running replacement image');
        }
        await this.ctx.container.interceptOutboundHttps(gateway, this.env.REPLACEMENT_SQL);
        return super.waitForReady();
    }

    async startupOptions() {
        const {CONTAINER_IMAGE: image, REPLACEMENT_SQL_TOKEN: token, REPLACEMENT_APP_KEY: appKey} = this.env;
        if (this.ctx.container.images?.base !== image || typeof token !== 'string' || token.length < 32
            || typeof appKey !== 'string' || !/^base64:[a-zA-Z0-9+/]{43}=$/.test(appKey)) {
            throw new Error('Missing replacement image or credentials');
        }
        let files;
        try {
            files = JSON.parse(await this.env.REPLACEMENT_FILES_CREDENTIAL.get());
        } catch {
            throw new Error('Replacement file credentials unavailable');
        }
        if (files?.bucket !== database + '-files' || !/^[a-f0-9]{32}$/.test(files.accessKeyId)
            || !/^[a-f0-9]{64}$/.test(files.secretAccessKey)) throw new Error('Invalid replacement file credentials');

        const webhook = FamilyFundsWebStagingContainer.webhookConfig(this.env);
        if (this.env.RUNTIME_PROFILE === 'replacement-webhook-rehearsal' && !webhook) throw new Error('Missing private rehearsal webhook configuration');
        return {image, instance: 'standard-1', enableInternet: true, env: {
            APP_ENV: 'd1-staging', APP_KEY: appKey, APP_DEBUG: 'false',
            APP_URL: this.env.RUNTIME_PROFILE === 'replacement-live' ? stagingOrigin : 'https://replacement-staging.internal', D1_PRODUCTION_ENABLED: 'false',
            ...(this.env.RUNTIME_PROFILE === 'replacement-acceptance' && {
                SESSION_COOKIE: acceptanceCookie, SESSION_SECURE_COOKIE: 'true',
            }),
            D1_STAGING_ENABLED: 'true', D1_STAGING_DATABASE: database, D1_STAGING_URL: 'https://' + gateway,
            D1_STAGING_TOKEN: token, D1_STAGING_R2_ADMISSION: '1', D1_STAGING_R2_BUCKET: files.bucket,
            D1_STAGING_R2_ACCESS_KEY_ID: files.accessKeyId, D1_STAGING_R2_SECRET_ACCESS_KEY: files.secretAccessKey,
            QUEUE_WORKER_ENABLED: 'false', STAGING_QUEUE_WORKER_ENABLED: 'false', D1_STAGING_DELIVERY_ENABLED: 'false',
            D1_STAGING_WEBHOOKS_ENABLED: webhook ? 'true' : 'false',
            ...(webhook && {D1_STAGING_PAYSTACK_TEST_KEY: webhook.paystack_test_key, D1_STAGING_META_APP_SECRET: webhook.meta_app_secret,
                D1_STAGING_RELAY_TOKEN: webhook.relay_token}), NIGHTWATCH_ENABLED: 'false', MAIL_MAILER: 'array',
        }};
    }

    async checkConnections() {
        const health = await this.fetch(new Request('https://replacement-staging.internal/up'));
        await health.body?.cancel();
        if (!health.ok) return false;
        const {env} = await this.startupOptions();
        const check = await this.containerOutput(['php', '/var/www/html/deployment/d1-proof/run-staging.php',
            'verify-maintenance-target', '/nonexistent-staging-secret-file', 'replacement'],
            {cwd: '/var/www/html', env, stdout: 'pipe', stderr: 'ignore'}, 30000);
        if (check.exitCode !== 0 || check.stdout.byteLength > 512) return false;
        try {
            const result = JSON.parse(new TextDecoder().decode(check.stdout));
            return result.database === database && result.files === true;
        } catch {
            return false;
        }
    }

    async scheduledProof(kind, familyId, at) {
        if (this.env.RUNTIME_PROFILE !== 'replacement-rehearsal') throw new Error('Replacement preflight has no maintenance tasks');
        return super.scheduledProof(kind, familyId, at);
    }
}

// Only a private service binding can select this entrypoint. Laravel still authenticates each session.
export class ReplacementAcceptance extends WorkerEntrypoint {
    async fetch(request) {
        const url = new URL(request.url);
        if (this.env.RUNTIME_PROFILE !== 'replacement-acceptance' || this.env.STAGING_ADMISSION_CONTROL !== '1'
            || url.origin !== 'https://replacement-staging.internal') return new Response('Not found', {status: 404});

        const [, family, ...segments] = url.pathname.split('/');
        const familyPage = ['hussain-family', 'd1-uat-family'].includes(family) ? segments.join('/') : '';
        const page = request.method === 'GET' && (
            ['/', '/login', '/two-factor-challenge', '/dashboard'].includes(url.pathname)
            || /^\/build\/assets\/[A-Za-z0-9_.-]+\.(?:css|js|woff2?|svg|png)$/.test(url.pathname)
            || /^(?:dashboard|notifications|payments|pay|family\/settings|contributions(?:\/my(?:\/statement)?|\/[0-9]+)?|payment-batches\/[0-9]+\/receipt|members\/[0-9]+\/payments\/create)$/.test(familyPage)
        );
        const form = request.method === 'POST' && (
            ['/login', '/logout', '/two-factor-challenge'].includes(url.pathname)
            || familyPage === 'payments/review'
        );
        if (!page && !form) return new Response('Not found', {status: 404});
        if (form && request.headers.get('Origin') !== url.origin) return new Response('Forbidden', {status: 403});

        const headers = new Headers();
        for (const [name, value] of request.headers) {
            if (['accept', 'accept-language', 'content-type', 'origin', 'user-agent', 'x-xsrf-token',
                'x-csrf-token', 'x-requested-with', 'x-inertia'].includes(name) || name.startsWith('x-inertia-')) headers.set(name, value);
        }
        const cookies = (request.headers.get('Cookie') ?? '').split(';').map(part => part.trim())
            .filter(part => part.startsWith(acceptanceCookie + '=') || part.startsWith('XSRF-TOKEN='));
        if (cookies.length) headers.set('Cookie', cookies.join('; '));
        return this.env.REPLACEMENT_CONTAINER.getByName('replacement-staging').fetch(new Request(request, {headers, redirect: 'manual'}));
    }
}

export class ReplacementMaintenance extends WorkerEntrypoint {
    async scheduledProof(kind, familyId, at) {
        if (this.env.RUNTIME_PROFILE !== 'replacement-rehearsal') throw new Error('Replacement maintenance is disabled');
        return this.env.REPLACEMENT_CONTAINER.getByName('replacement-staging').scheduledProof(kind, familyId, at);
    }
}

export default {
    async queue(batch, env) {
        if (env.RUNTIME_PROFILE !== 'replacement-webhook-rehearsal') throw new Error('Replacement webhook rehearsal is disabled');
        return stagingHandler.queue(batch, env);
    },
    async fetch(request, env) {
        const url = new URL(request.url);
        if (env.RUNTIME_PROFILE === 'replacement-live') {
            if (url.origin !== stagingOrigin) return new Response('Not found', {status: 404});
            if (env.STAGING_ADMISSION_CONTROL !== '1' || !/^github-[0-9a-f]{12}$/.test(env.RUNTIME_REVISION)
                || typeof env.BASIC_AUTHORIZATION !== 'string' || !/^Basic [A-Za-z0-9+/]+=*$/.test(env.BASIC_AUTHORIZATION)) {
                return new Response('Staging configuration unavailable', {status: 503});
            }
            const actual = new TextEncoder().encode(request.headers.get('Authorization') ?? '');
            const expected = new TextEncoder().encode(env.BASIC_AUTHORIZATION);
            if (actual.length !== expected.length || !crypto.subtle.timingSafeEqual(actual, expected)) {
                return new Response('Authentication required', {status: 401, headers: {'WWW-Authenticate': 'Basic realm="FamilyFunds staging"'}});
            }
            if (url.pathname.startsWith('/__') || url.pathname.startsWith('/webhooks/') || url.pathname === '/connections') {
                return new Response('Not found', {status: 404});
            }
            const headers = new Headers(request.headers);
            for (const name of ['Authorization', 'X-D1-Staging-Relay', 'X-Staging-Admin', 'X-D1-Staging-Candidate']) headers.delete(name);
            const container = env.REPLACEMENT_CONTAINER.getByName('replacement-staging');
            const upstream = await container.fetch(new Request(request, {headers, redirect: 'manual'}));
            const response = new Response(upstream.body, upstream);
            response.headers.set('X-FamilyFunds-Staging-Destination', 'replacement');
            response.headers.set('X-FamilyFunds-Runtime-Revision', env.RUNTIME_REVISION);
            response.headers.set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            return response;
        }
        if (env.RUNTIME_PROFILE === 'replacement-webhook-rehearsal' && ['/webhooks/paystack', '/webhooks/whatsapp'].includes(url.pathname)) {
            return stagingHandler.fetch(request, env);
        }
        if (request.method !== 'GET' || !['/up', '/connections'].includes(url.pathname) || url.search) return new Response('Not found', {status: 404});
        const container = env.REPLACEMENT_CONTAINER.getByName('replacement-staging');
        if (url.pathname === '/connections') {
            const ready = await container.checkConnections();
            return new Response(ready ? 'connected' : 'unavailable', {status: ready ? 200 : 503, headers: {'Cache-Control': 'no-store'}});
        }
        return container.fetch(new Request('https://replacement-staging.internal/up'));
    },
};
