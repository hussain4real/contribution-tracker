import {FamilyFundsWebStagingContainer} from './container-worker.mjs';

const database = 'familyfunds-d1-staging-clone-20261103t092540z';
const gateway = 'familyfunds-d1-replacement-sql.familyfunds-lab.workers.dev';
const imagePattern = /^registry\.cloudflare\.com\/40344948b73fa9da3008958fdc3faf12\/familyfunds-staging-replacement@sha256:[0-9a-f]{64}$/;

export class FamilyFundsReplacementStagingContainer extends FamilyFundsWebStagingContainer {
    constructor(ctx, env) {
        if (env.CONTAINER_ENV !== '{}' || env.CONTAINER_ENV_PARTS !== undefined || env.OPTIMIZED_BOOTSTRAP !== undefined
            || env.RUNTIME_PROFILE !== 'replacement-preflight' || env.STAGING_ADMISSION_CONTROL !== '1'
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

        return {image, instance: 'standard-1', enableInternet: true, env: {
            APP_ENV: 'd1-staging', APP_KEY: appKey, APP_DEBUG: 'false',
            APP_URL: 'https://replacement-staging.internal', D1_PRODUCTION_ENABLED: 'false',
            D1_STAGING_ENABLED: 'true', D1_STAGING_DATABASE: database, D1_STAGING_URL: 'https://' + gateway,
            D1_STAGING_TOKEN: token, D1_STAGING_R2_ADMISSION: '1', D1_STAGING_R2_BUCKET: files.bucket,
            D1_STAGING_R2_ACCESS_KEY_ID: files.accessKeyId, D1_STAGING_R2_SECRET_ACCESS_KEY: files.secretAccessKey,
            QUEUE_WORKER_ENABLED: 'false', STAGING_QUEUE_WORKER_ENABLED: 'false', D1_STAGING_DELIVERY_ENABLED: 'false',
            D1_STAGING_WEBHOOKS_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false', MAIL_MAILER: 'array',
        }};
    }

    async checkConnections() {
        const health = await this.fetch(new Request('https://replacement-staging.internal/up'));
        await health.body?.cancel();
        if (!health.ok) return false;
        const php = String.raw`require 'deployment/d1-proof/autoload.php';
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $db = Illuminate\Support\Facades\DB::connection('d1-staging');
            if ((int) $db->selectOne('select 1 as connected')->connected !== 1) exit(1);
            $files = Illuminate\Support\Facades\Storage::disk('local')->getClient();
            $result = $files->listObjectsV2(['Bucket' => config('d1-staging.r2.bucket'), 'MaxKeys' => 0]);
            exit(($result['@metadata']['statusCode'] ?? null) === 200 ? 0 : 1);`;
        const check = await this.containerOutput(['php', '-r', php], {cwd: '/var/www/html', stdout: 'ignore', stderr: 'ignore'}, 30000);
        return check.exitCode === 0;
    }

    async scheduledProof() { throw new Error('Replacement preflight has no maintenance tasks'); }
}

export default {
    async fetch(request, env) {
        const url = new URL(request.url);
        if (request.method !== 'GET' || !['/up', '/connections'].includes(url.pathname) || url.search) return new Response('Not found', {status: 404});
        const container = env.REPLACEMENT_CONTAINER.getByName('replacement-staging');
        if (url.pathname === '/connections') {
            const ready = await container.checkConnections();
            return new Response(ready ? 'connected' : 'unavailable', {status: ready ? 200 : 503, headers: {'Cache-Control': 'no-store'}});
        }
        return container.fetch(new Request('https://replacement-staging.internal/up'));
    },
};
