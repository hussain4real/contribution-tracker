import {timingSafeEqual} from "node:crypto";
import {WorkerEntrypoint} from "cloudflare:workers";
import {FamilyFundsWebStagingContainer} from "./container-worker.mjs";
import {receiveProductionWebhook, relayProductionWebhooks} from "./production-webhooks.mjs";
import sqlHandler from "./production-sql-worker.mjs";
import { containerEnvironment } from "./container-env.mjs";
import {inspectIntegrationMetadata} from './production-integration-check.mjs';
import {getProductionContainer} from './production-container-target.mjs';

export class ProductionDatabaseOutbound extends WorkerEntrypoint {
    async fetch(request) {
        if (new URL(request.url).hostname !== "familyfunds-production.d1.internal") return new Response("Forbidden", {status: 403});
        const headers = new Headers(request.headers);
        headers.set("Authorization", "Bearer " + this.env.SQL_GATEWAY_TOKEN);
        return sqlHandler.fetch(new Request(request, {headers}), {DB: this.env.DB, DATABASE_NAME: this.env.DATABASE_NAME, SQL_TOKEN: this.env.SQL_GATEWAY_TOKEN});
    }
}

function candidateAuthorized(request, env) {
    for (const [supplied, expected] of [[request.headers.get("X-FamilyFunds-Preview"), env.CANDIDATE_TOKEN], [request.headers.get("Authorization"), env.CANDIDATE_AUTHORIZATION]]) {
        if (typeof expected !== "string" || expected.length < 32 || typeof supplied !== "string") continue;
        const a = new TextEncoder().encode(supplied), b = new TextEncoder().encode(expected);
        if (a.length === b.length && timingSafeEqual(a, b)) return true;
    }
    return false;
}

export class FamilyFundsProductionContainer extends FamilyFundsWebStagingContainer {
    constructor(ctx, env) {
        const config = containerEnvironment(env);
        if (Object.keys(config).some(name => /^(FF_RCLONE_CONFIG|R2_BACKUP_.*|BACKUP_R2_.*|BACKUP_KEY|GOOGLE_DRIVE_.*|BACKUP_RCLONE_(?!ENABLED$).*)$/.test(name))
            || config.BACKUP_GOOGLE_DRIVE_ENABLED === "true" || config.BACKUP_RCLONE_ENABLED === "true") throw new Error("Offsite credentials are forbidden in the application container");
        if (env.RUNTIME_PROFILE !== "production" || env.OPTIMIZED_BOOTSTRAP || config.APP_ENV !== "production"
            || config.D1_PRODUCTION_ENABLED !== "true" || config.D1_STAGING_ENABLED !== "false"
            || config.D1_DATABASE !== "familyfunds-production") throw new Error("Production container profile mismatch");
        if (config.D1_MIGRATION_CHECKS === 'true' && ['QUEUE_WORKER_ENABLED', 'D1_PRODUCTION_DELIVERY_ENABLED', 'D1_PRODUCTION_WEBHOOKS_ENABLED', 'NIGHTWATCH_ENABLED'].some(name => config[name] !== 'false')) {
            throw new Error('Migration mirror background work and delivery must remain disabled');
        }
        super(ctx, env);
    }

    async scheduledProof() { throw new Error("Synthetic staging operations are unavailable in production"); }

    async startupStatus() {
        return {ready: this.ready, running: this.ctx.container?.running ?? false, container_available: this.ctx.container !== undefined,
            revision: this.env.RUNTIME_REVISION, ...this.startupState};
    }

    async resetUnavailableContainerBinding() {
        if (this.env.CANDIDATE_DIAGNOSTICS !== '1' || this.ctx.container !== undefined) throw new Error('Unavailable candidate binding required');
        this.ctx.abort('Reset unavailable isolated candidate container binding');
    }

    async runtime() {
        const result = await super.runtime();
        if (!result.running || !result.runtime) return result;
        const php = '$c=require "/var/www/html/bootstrap/cache/config.php";$blocked=false;foreach(["/run/familyfunds-fpm-error.log","/run/familyfunds-php-fpm.log","/var/www/html/storage/logs/laravel.log"] as $file){if(is_file($file)){$stream=fopen($file,"rb");if($stream!==false){fseek($stream,max(0,filesize($file)-131072));$tail=stream_get_contents($stream,131072);fclose($stream);$blocked=$blocked||(is_string($tail)&&str_contains($tail,"Attempted request to [https://api.paystack.co/bank")&&str_contains($tail,"without a matching fake"));}}}echo json_encode(["production_provider_sha256"=>hash_file("sha256","/var/www/html/deployment/d1-proof/D1ProductionServiceProvider.php"),"production_maintenance_sha256"=>hash_file("sha256","/var/www/html/deployment/d1-proof/D1ProductionMaintenance.php"),"entrypoint_sha256"=>hash_file("sha256","/var/www/html/deployment/d1-proof/container-slim-entrypoint.sh"),"integration_fingerprints"=>array_map(fn($value)=>is_string($value)?hash("sha256",$value):null,["OLLAMA_API_KEY"=>$c["ai"]["providers"]["ollama"]["key"]??null,"OPENAI_API_KEY"=>$c["ai"]["providers"]["openai"]["key"]??null,"GEMINI_API_KEY"=>$c["ai"]["providers"]["gemini"]["key"]??null,"GITHUB_RELEASES_TOKEN"=>$c["services"]["github"]["releases"]["token"]??null]),"app_url"=>$c["app"]["url"]??null,"passkey_rp_id"=>$c["fortify"]["passkeys"]["relying_party_id"]??null,"passkey_origins"=>$c["fortify"]["passkeys"]["allowed_origins"]??null,"agent_provider"=>$c["ai"]["agent"]["provider"]??null,"agent_model"=>$c["ai"]["agent"]["model"]??null,"transcription_provider"=>$c["ai"]["default_for_transcription"]??null,"webhooks_enabled"=>$c["d1-production"]["webhooks_enabled"],"nightwatch_enabled"=>$c["nightwatch"]["enabled"],"mail_transport"=>$c["mail"]["default"],"database_driver"=>$c["database"]["default"],"production_enabled"=>$c["d1-production"]["enabled"],"migration_checks"=>$c["d1-production"]["migration_checks"]??false,"queue_worker_enabled"=>$c["d1-production"]["queue_worker_enabled"]??false,"queue_worker_processes"=>count(array_filter(glob("/proc/[0-9]*/cmdline"),fn($path)=>str_contains(@file_get_contents($path)?:"","artisan".chr(0)."queue:work"))),"bank_list_outbound_blocked"=>$blocked,"delivery_enabled"=>$c["d1-production"]["delivery_enabled"],"offsite_disk_configured"=>isset($c["filesystems"]["disks"]["production-backups"]),"offsite_config_keys"=>array_values(array_filter(array_keys($c["d1-production"]),fn($key)=>preg_match("/rclone|backup_r2/",$key))),"offsite_file_present"=>is_file("/run/familyfunds-rclone.conf"),"drive_sync_enabled"=>$c["backup"]["google_drive"]["enabled"]]);';
        const output = await this.containerOutput(['/usr/local/bin/php', '-r', php], {stdout: 'pipe', stderr: 'ignore'}, 10000);
        if (output.exitCode !== 0 || output.stdout.byteLength > 4096) throw new Error('Production runtime inspection failed');
        return {...result, production: JSON.parse(new TextDecoder().decode(output.stdout))};
    }

    async configureOutbound() {
        const worker = this.ctx.exports.ProductionDatabaseOutbound({props: {}});
        await this.ctx.container.interceptOutboundHttps("familyfunds-production.d1.internal", worker);
    }

    async inspectIntegrations() {
        if (this.env.CANDIDATE_DIAGNOSTICS !== '1') throw new Error('Private integration inspection is disabled');
        return inspectIntegrationMetadata(containerEnvironment(this.env));
    }

    async inspectExistingOAuth(token) {
        if (this.env.CANDIDATE_DIAGNOSTICS !== '1' || containerEnvironment(this.env).D1_MIGRATION_CHECKS !== 'true') throw new Error('Private OAuth inspection is disabled');
        if (typeof token !== 'string' || token.length > 8192 || !/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(token)) throw new Error('Invalid OAuth inspection input');
        if (!this.ctx.container.running) this.ready = false;
        if (!this.ready) {
            this.starting ??= this.waitForReady().finally(() => { this.starting = null; });
            await this.starting;
        }
        if (!this.ready) throw new Error('OAuth inspection container is not ready');
        const php = String.raw`require_once "/var/www/html/vendor/autoload.php";require_once "/var/www/html/deployment/d1-proof/autoload.php";$app=require "/var/www/html/bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(["logging.default"=>"null"]);try{$request=Illuminate\Http\Request::create("https://familyfunds.app/mcp/family-fund","GET");$request->headers->set("Authorization","Bearer ".getenv("FF_MIGRATION_BEARER"));$guard=Illuminate\Support\Facades\Auth::guard("api");$guard->setRequest($request);$user=$guard->user();$token=$user?->token();echo json_encode(["authenticated"=>$user!==null&&$token!==null,"user_id"=>$user?->getAuthIdentifier(),"client_id"=>$token?->client_id,"token_reference_sha256"=>$token===null?null:hash("sha256",$token->id),"read_only"=>true]);}catch(Throwable){echo json_encode(["authenticated"=>false,"error"=>"OAuth validation failed; private details suppressed","read_only"=>true]);}`;
        const output = await this.containerOutput(['/usr/local/bin/php', '-r', php], {
            cwd: '/var/www/html', env: {...containerEnvironment(this.env), FF_MIGRATION_BEARER: token}, stdout: 'pipe', stderr: 'ignore',
        }, 60000);
        if (output.exitCode !== 0 || output.stdout.byteLength > 2048) throw new Error('Private OAuth inspection failed');
        let result;
        try { result = JSON.parse(new TextDecoder().decode(output.stdout)); }
        catch { throw new Error('Invalid private OAuth inspection response'); }
        return {authenticated: result.authenticated === true, user_id: result.user_id ?? null, client_id: result.client_id ?? null,
            token_reference_sha256: result.token_reference_sha256 ?? null, read_only: true};
    }

    async productionCommand(operation, task = null) {
        if (!["install", "task", "inspect-migration", "probe-storage"].includes(operation) || (operation === "task" && (!task || JSON.stringify(task).length > 8192))) throw new Error("Invalid production command");
        if (operation === 'task' && containerEnvironment(this.env).D1_MIGRATION_CHECKS === 'true') throw new Error('Application tasks are disabled during migration inspection');
        if (operation === 'inspect-migration' && (this.env.CANDIDATE_DIAGNOSTICS !== '1' || this.env.BUSINESS_WRITES_ENABLED !== 'false' || this.env.WEBHOOKS_ENABLED !== 'false')) throw new Error('Private migration inspection is disabled');
        if (operation === 'probe-storage' && (this.env.CANDIDATE_DIAGNOSTICS !== '1' || containerEnvironment(this.env).D1_MIGRATION_CHECKS !== 'true')) throw new Error('Private migration inspection is disabled');
        if (!this.ctx.container.running) this.ready = false;
        if (!this.ready) {
            this.starting ??= this.waitForReady().finally(() => { this.starting = null; });
            await this.starting;
        }
        if (!this.ready) throw new Error("Production container is not ready");
        const env = {...containerEnvironment(this.env), FF_PRODUCTION_TASK: JSON.stringify(task)};
        const storageProbe = 'require_once "/var/www/html/vendor/autoload.php";require_once "/var/www/html/deployment/d1-proof/autoload.php";$app=require "/var/www/html/bootstrap/app.php";$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$disk=Illuminate\\Support\\Facades\\Storage::disk("local");$path="migration-probes/".bin2hex(random_bytes(16)).".txt";$data="FamilyFunds isolated storage write verification v1";try{try{$disk->put($path,$data);if($disk->get($path)!==$data){throw new RuntimeException("Storage mismatch");}}finally{$disk->delete($path);}if($disk->exists($path)){throw new RuntimeException("Storage cleanup failed");}echo json_encode(["exit_code"=>0,"write_read_delete_verified"=>true,"sha256"=>hash("sha256",$data)]);}catch(Throwable $error){$cause=$error;while($cause->getPrevious()!==null){$cause=$cause->getPrevious();}echo json_encode(["exit_code"=>1,"error_type"=>get_class($cause),"aws_code"=>method_exists($cause,"getAwsErrorCode")?$cause->getAwsErrorCode():null]);}';
        const migrationInspection = String.raw`require_once "/var/www/html/vendor/autoload.php";require_once "/var/www/html/deployment/d1-proof/autoload.php";$app=require "/var/www/html/bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();FamilyFunds\D1Staging\D1RuntimeProfile::assertEnabled();if(!FamilyFunds\D1Staging\D1RuntimeProfile::production()){throw new RuntimeException("Production inspection requires the production profile");}$before=hash_file("sha256","/var/www/html/bootstrap/cache/config.php");config(["d1-production.migration_checks"=>true,"d1-production.provider_read_only_checks"=>true,"d1-production.queue_worker_enabled"=>false,"d1-production.delivery_enabled"=>false,"d1-production.webhooks_enabled"=>false,"nightwatch.enabled"=>false,"mail.default"=>"array","logging.default"=>"null"]);$result=app(FamilyFunds\D1Staging\D1ProductionMaintenance::class)->inspectMigration();if(!hash_equals($before,hash_file("sha256","/var/www/html/bootstrap/cache/config.php"))){throw new RuntimeException("Inspection changed persistent configuration");}$result["inspection_configuration_ephemeral"]=true;echo json_encode($result,JSON_THROW_ON_ERROR);`;
        const command = operation === 'probe-storage' ? ['/usr/local/bin/php', '-r', storageProbe]
            : operation === 'inspect-migration' ? ['/usr/local/bin/php', '-r', migrationInspection]
                : ["/usr/local/bin/php", "/var/www/html/deployment/d1-proof/run-production.php", operation];
        const result = await this.containerOutput(command,
            {cwd: "/var/www/html", env, stdout: "pipe", stderr: "pipe"}, 160_000);
        if (result.exitCode !== 0 || result.stdout.byteLength > 65536) throw new Error("Production maintenance execution failed");
        let decoded;
        try { decoded = JSON.parse(new TextDecoder().decode(result.stdout).trim()); }
        catch { throw new Error('Production maintenance returned invalid JSON'); }
        if (decoded?.exit_code !== 0) throw new Error("Production maintenance command failed");
        return decoded;
    }
}

export default {
    async fetch(request, env) {
        if (env.DATABASE_NAME !== "familyfunds-production") return new Response("Unavailable", {status: 503});
        const live = env.TRAFFIC_ENABLED === "true";
        if (live && new URL(request.url).hostname !== "familyfunds.app") return new Response("Not found", {status: 404});
        if (!live && !candidateAuthorized(request, env)) return new Response("Authentication required", {status: 401, headers: {"WWW-Authenticate": 'Basic realm="FamilyFunds production candidate"', "X-Robots-Tag": "noindex, nofollow, noarchive"}});
        const path = new URL(request.url).pathname;
        if (path.startsWith("/__staging_admin") || path.startsWith("/__candidate")) return new Response("Not found", {status: 404});
        if (env.BUSINESS_WRITES_ENABLED !== 'true' || containerEnvironment(env).D1_MIGRATION_CHECKS === 'true') {
            let decoded;
            try { decoded = decodeURIComponent(path).replace(/\/+$/, '') || '/'; }
            catch { return new Response('Invalid path', {status: 400}); }
            const mutatingRead = /^\/[^/]+\/(?:reports\/export|contributions\/my\/statement|payment-batches\/[^/]+\/receipt|pay\/callback|subscription\/callback)$/.test(decoded)
                || /^\/invitations\/[^/]+\/accept$/.test(decoded) || decoded === '/oauth/authorize' || /^\/email\/verify\//.test(decoded);
            const authentication = request.method === 'POST' && ['/login', '/logout', '/two-factor-challenge', '/user/confirm-password', '/passkeys/login/options', '/passkeys/login'].includes(decoded);
            if (mutatingRead || (!['GET', 'HEAD'].includes(request.method) && !authentication)) return new Response('This migration mirror only permits inspection and authentication.', {status: 423});
        }
        if (["/webhooks/paystack", "/webhooks/whatsapp"].includes(path)) {
            try { return await receiveProductionWebhook(request, env); }
            catch { return new Response("Webhook processing unavailable", {status: 503, headers: {"Retry-After": "30"}}); }
        }
        const headers = new Headers(request.headers);
        headers.delete("X-FamilyFunds-Preview"); headers.delete("X-FamilyFunds-Relay");
        headers.delete("X-Staging-Admin"); headers.delete("X-D1-Staging-Candidate");
        if (!live && request.headers.get("Authorization")?.startsWith("Basic ")) headers.delete("Authorization");
        const response = await getProductionContainer(env).fetch(new Request(request, {headers}));
        const result = new Response(response.body, response);
        result.headers.set('X-FamilyFunds-Write-Mode', env.BUSINESS_WRITES_ENABLED === 'true' && containerEnvironment(env).D1_MIGRATION_CHECKS !== 'true' ? 'live' : 'read-only');
        if (!live) result.headers.set("X-Robots-Tag", "noindex, nofollow, noarchive");
        return result;
    },
    async queue(batch, env) {
        if (batch.queue === "familyfunds-production-webhooks") return relayProductionWebhooks(batch, env);
        if (batch.queue !== "familyfunds-production-webhooks-dlq") throw new Error("Unknown production queue");
        for (const message of batch.messages) {
            const body = JSON.stringify(message.body);
            if (body.length > 8192) throw new Error("Unexpected dead-letter size");
            await env.DB.prepare("insert into d1_runtime_dead_letters(id,queue,body,created_at) values(?,?,?,unixepoch()) on conflict(id) do nothing").bind(message.id, batch.queue, body).run();
            message.ack();
        }
    },
};
