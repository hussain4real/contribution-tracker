import { DurableObject } from "cloudflare:workers";
import { STAGING_BOOTSTRAP_ASSETS } from "./container-bootstrap-assets.mjs";
import { containerEnvironment } from "./container-env.mjs";

function instanceUnavailable(error) {
    return error instanceof Error && error.message.includes("There is no container instance that can be provided");
}

function webhookConfig(env) {
    if (!env.STAGING_WEBHOOK_SECRETS) return null;
    const config = JSON.parse(env.STAGING_WEBHOOK_SECRETS);
    if (config.enabled !== true) return null;
    if (config.database !== "familyfunds-d1-web-staging" || config.family_id !== 43) throw new Error("Invalid isolated webhook target");
    return config;
}

function webhookScope(provider, payload, config) {
    if (provider === "paystack") {
        return config.paystack_test_key?.startsWith("sk_test_")
            && payload.event === "charge.success" && payload.data?.domain === "test"
            && /^D1-WEBHOOK-UAT-[A-Za-z0-9-]{1,80}$/.test(payload.data?.reference ?? "")
            && String(payload.data?.metadata?.family_id) === "43";
    }
    return Boolean(config.meta_app_secret && config.meta_waba && config.meta_phone && config.recipient)
        && payload.object === "whatsapp_business_account" && Array.isArray(payload.entry) && payload.entry.length > 0
        && payload.entry.length <= 5 && payload.entry.every(entry => entry.id === config.meta_waba
            && Array.isArray(entry.changes) && entry.changes.length > 0 && entry.changes.every(change =>
                change.field === "messages" && change.value?.metadata?.phone_number_id === config.meta_phone
                && Array.isArray(change.value.messages ?? []) && Array.isArray(change.value.statuses ?? [])
                && (change.value.messages ?? []).every(message => message.from === config.recipient)
                && (change.value.statuses ?? []).every(status => status.recipient_id === config.recipient)));
}

async function verifyWebhook(record, config) {
    if (!record || !["paystack", "whatsapp"].includes(record.provider) || typeof record.raw !== "string"
        || new TextEncoder().encode(record.raw).length > 49_152 || typeof record.signature !== "string") return false;
    const meta = record.provider === "whatsapp";
    const hex = meta ? record.signature.replace(/^sha256=/, "") : record.signature;
    if (!(meta ? /^[a-f0-9]{64}$/i : /^[a-f0-9]{128}$/i).test(hex) || (meta && !record.signature.startsWith("sha256="))) return false;
    const secret = meta ? config.meta_app_secret : config.paystack_test_key;
    if (typeof secret !== "string" || secret === "") return false;
    const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(secret), {name: "HMAC", hash: meta ? "SHA-256" : "SHA-512"}, false, ["verify"]);
    const bytes = Uint8Array.from(hex.match(/../g), pair => parseInt(pair, 16));
    if (!await crypto.subtle.verify("HMAC", key, bytes, new TextEncoder().encode(record.raw))) return false;
    try {
        return webhookScope(record.provider, JSON.parse(record.raw), config);
    } catch {
        return false;
    }
}

async function receiveWebhook(request, env) {
    const config = webhookConfig(env);
    if (!config) return new Response("Forbidden", {status: 403});
    const provider = new URL(request.url).pathname === "/webhooks/paystack" ? "paystack" : "whatsapp";
    if (provider === "whatsapp" && request.method === "GET") {
        const query = new URL(request.url).searchParams;
        const supplied = new TextEncoder().encode(query.get("hub.verify_token") ?? "");
        const expected = new TextEncoder().encode(config.meta_verify_token ?? "");
        const challenge = query.get("hub.challenge") ?? "";
        if (query.get("hub.mode") !== "subscribe" || expected.length === 0 || supplied.length !== expected.length
            || !crypto.subtle.timingSafeEqual(supplied, expected) || challenge.length > 256) return new Response("Forbidden", {status: 403});
        return new Response(challenge, {headers: {"Content-Type": "text/plain", "Cache-Control": "no-store"}});
    }
    if (request.method !== "POST") return new Response("Method not allowed", {status: 405});
    if (Number(request.headers.get("Content-Length")) > 49_152) return new Response("Payload too large", {status: 413});
    const reader = request.body?.getReader();
    if (!reader) return new Response("Invalid payload", {status: 400});
    const chunks = [];
    let length = 0;
    while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        length += value.byteLength;
        if (length > 49_152) {
            await reader.cancel();
            return new Response("Payload too large", {status: 413});
        }
        chunks.push(value);
    }
    const bytes = new Uint8Array(length);
    let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
    const record = {provider, raw: new TextDecoder("utf-8", {fatal: true}).decode(bytes),
        signature: request.headers.get(provider === "paystack" ? "X-Paystack-Signature" : "X-Hub-Signature-256") ?? ""};
    if (!await verifyWebhook(record, config)) return new Response("Forbidden", {status: 403});
    await env.STAGING_WEBHOOK_QUEUE.send(record, {contentType: "json"});
    return Response.json({message: "Accepted"}, {status: provider === "paystack" ? 202 : 200, headers: {"Cache-Control": "no-store"}});
}

function bootstrapParts(env, prefix, content) {
    const characters = Array.from(content);
    const names = [];
    for (let offset = 0; offset < characters.length; offset += 3500) {
        const name = offset === 0 ? prefix : `${prefix}_${offset}`;
        env[name] = characters.slice(offset, offset + 3500).join("");
        names.push(name);
    }
    return names;
}

export class FamilyFundsWebStagingContainer extends DurableObject {
    constructor(ctx, env) {
        super(ctx, env);
        this.ready = false;
        this.starting = null;
        this.inFlight = 0;
        this.waiters = [];
        this.startupState = {attempts: 0, allocations: 0};
        this.label = env.RUNTIME_PROFILE === "production" ? "FamilyFunds" : "Staging";
        this.requestLimit = Number(env.APP_CONCURRENCY ?? 2);
        if (!Number.isInteger(this.requestLimit) || this.requestLimit < 1 || this.requestLimit > 20) {
            throw new Error("Invalid staging application concurrency limit");
        }
        if (ctx.container?.running) {
            ctx.blockConcurrencyWhile(async () => {
                try {
                    await ctx.container.setInactivityTimeout(120_000);
                } catch (error) {
                    if (!instanceUnavailable(error)) throw error;
                }
            });
        }
    }

    startupOptions(env) {
        return {env, enableInternet: true};
    }

    async containerOutput(command, options, timeoutMs) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const process = await this.ctx.container.exec(command, {...options, signal: controller.signal});
            return await process.output();
        } finally {
            clearTimeout(timeout);
        }
    }

    async waitForReady() {
        const container = this.ctx.container;
        this.startupState = {attempts: 0, allocations: 0, requested_ms: Date.now()};
        const deadline = Date.now() + 25_000;
        while (Date.now() < deadline) {
            const allocated = await this.ctx.blockConcurrencyWhile(async () => {
                this.startupState.attempts++;
                try {
                    if (!container.running) {
                        this.startupState.phase = "start";
                        const env = containerEnvironment(this.env);
                        if (this.env.STARTUP_PROFILE === "1") {
                            env.FF_STARTUP_PROFILE = "1";
                            env.FF_STAGING_START_REQUEST_MS = String(Date.now());
                        }
                        const options = this.startupOptions(env);
                        if (this.env.OPTIMIZED_BOOTSTRAP === "v1") {
                            const files = STAGING_BOOTSTRAP_ASSETS.filter(asset => asset.path !== "/var/www/html/deployment/d1-proof/run-staging.php").map((asset, i) => {
                                if (!/^\/var\/www\/html\/[A-Za-z0-9_./-]+$/.test(asset.path) || asset.path.includes("..") || !/^[a-f0-9]{64}$/.test(asset.sha256)) throw new Error("Invalid packaged bootstrap asset");
                                const name = `FF_STAGING_ASSET_${i}`;
                                const names = bootstrapParts(env, name, asset.content);
                                const destination = asset.path.endsWith("/container-nginx.conf") ? "/etc/nginx/nginx.conf"
                                    : asset.path.endsWith("/container-supervisor.conf") ? "/etc/supervisord.conf" : asset.path;
                                const mode = asset.path.endsWith("/container-slim-entrypoint.sh") ? "0755" : "0644";
                                return `["path"=>${JSON.stringify(destination)},"hash"=>${JSON.stringify(asset.sha256)},"names"=>[${names.map(name => JSON.stringify(name)).join(",")}],"mode"=>${mode}]`;
                            });
                            const php = `$entry=microtime(true)*1000;$files=[${files.join(",")}];foreach($files as $file){$content="";foreach($file["names"] as $name){$part=getenv($name);if(!is_string($part)){throw new RuntimeException("Missing staging bootstrap part");}$content.=$part;}if(!hash_equals($file["hash"],hash("sha256",$content))){throw new RuntimeException("Invalid staging bootstrap bytes");}if(file_put_contents($file["path"],$content)!==strlen($content)){throw new RuntimeException("Staging bootstrap write failed");}chmod($file["path"],$file["mode"]);}if(getenv("FF_STARTUP_PROFILE")==="1"){file_put_contents("/run/familyfunds-startup.json",json_encode(["start_requested_ms"=>(float)getenv("FF_STAGING_START_REQUEST_MS"),"entrypoint_ms"=>$entry,"assets_ready_ms"=>microtime(true)*1000]));chmod("/run/familyfunds-startup.json",0600);}`;
                            const leanExtensions = this.env.LEAN_EXTENSIONS === "v1"
                                ? 'for file in /usr/local/etc/php/conf.d/docker-php-ext-*.ini; do case "$file" in */docker-php-ext-bcmath.ini|*/docker-php-ext-gd.ini|*/docker-php-ext-intl.ini|*/docker-php-ext-pcntl.ini|*/docker-php-ext-sodium.ini|*/docker-php-ext-zip.ini) ;; *) rm -f "$file" ;; esac; done; '
                                : "";
                            options.entrypoint = ["/bin/sh", "-c", `${leanExtensions}/usr/local/bin/php -r '${php}' && exec /var/www/html/deployment/d1-proof/container-slim-entrypoint.sh`];
                        }
                        container.start(options);
                        if (this.env.STARTUP_MONITOR === "1" && typeof container.monitor === "function") {
                            this.ctx.waitUntil(container.monitor().catch(error => {
                                this.startupState.start_error = String(error).slice(0, 512);
                                this.ready = false;
                            }));
                        }

                    }
                    return true;
                } catch (error) {
                    this.startupState.allocationError = String(error).slice(0, 512);
                    if (!instanceUnavailable(error)) throw error;
                    return false;
                }
            });
            if (allocated) {
                this.startupState.phase = "idle-timeout";
                try {
                    await container.setInactivityTimeout(120_000);
                } catch (error) {
                    this.startupState.allocationError = String(error).slice(0, 512);
                    if (!instanceUnavailable(error)) throw error;
                    await new Promise(resolve => setTimeout(resolve, 500));
                    continue;
                }
                this.startupState.allocations++;
                if (this.env.RUNTIME_PROFILE === "production") {
                    this.startupState.phase = "outbound-intercept";
                    await this.configureOutbound();
                }
                this.startupState.phase = "readiness";
                try {
                    const response = await container.getTcpPort(80).fetch("http://container/up", {signal: AbortSignal.timeout(500)});
                    this.startupState.portStatus = response.status;
                    await response.body?.cancel();
                    if (response.status === 200) {
                        this.ready = true;
                        return;
                    }
                } catch (error) { this.startupState.portError = String(error).slice(0, 512); }
            }
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }

    async acquireRequest() {
        if (this.inFlight < this.requestLimit) {
            this.inFlight++;
            return true;
        }
        if (this.waiters.length >= 16) return false;
        return new Promise(resolve => {
            const waiter = {resolve, timer: null};
            waiter.timer = setTimeout(() => {
                this.waiters = this.waiters.filter(pending => pending !== waiter);
                resolve(false);
            }, 20_000);
            this.waiters.push(waiter);
        });
    }

    releaseRequest() {
        const waiter = this.waiters.shift();
        if (waiter) {
            clearTimeout(waiter.timer);
            waiter.resolve(true);
        } else {
            this.inFlight--;
        }
    }

    async fetch(request) {
        const startupStarted = performance.now();
        const container = this.ctx.container;
        if (!container) return new Response(`${this.label} container is not configured`, {status: 503});
        if (!container.running) this.ready = false;
        if (!this.ready) {
            this.starting ??= this.waitForReady().finally(() => { this.starting = null; });
            await this.starting;
        }
        if (!this.ready) return new Response(`${this.label} container is starting. Retry shortly.`, {status: 503, headers: {"Retry-After": "2"}});
        const startupMs = performance.now() - startupStarted;
        const url = new URL(request.url);
        const consumesSlot = !url.pathname.startsWith("/build/");
        const queueStarted = performance.now();
        if (consumesSlot && !await this.acquireRequest()) return new Response(`${this.label} is busy. No operation was submitted; retry shortly.`, {status: 503, headers: {"Retry-After": "2"}});
        const queueMs = performance.now() - queueStarted;
        let released = false;
        const release = () => {
            if (released || !consumesSlot) return;
            released = true;
            this.releaseRequest();
        };
        const port = container.getTcpPort(80);
        const headers = new Headers(request.headers);
        headers.delete("X-Staging-Admin");
        headers.delete("X-D1-Staging-Candidate");
        headers.set("X-Forwarded-Proto", "https");
        headers.set("X-Forwarded-Host", url.host);
        headers.set("X-Forwarded-Port", "443");
        headers.set("X-Forwarded-For", request.headers.get("CF-Connecting-IP") ?? "127.0.0.1");
        const upstream = new Request("http://container" + url.pathname + url.search, {method: request.method, headers, body: request.body, redirect: "manual"});
        let response;
        const upstreamStarted = performance.now();
        try {
            response = await port.fetch(upstream);
        } catch (error) {
            release();
            this.ready = false;
            if (!instanceUnavailable(error) && !(error instanceof Error && error.message === "Network connection lost.")) throw error;
            console.warn(JSON.stringify({event: "container_transport_interrupted", method: request.method}));
            return new Response(`${this.label} container connection interrupted. Check the operation status before retrying.`, {status: 503});
        }
        if (response.status === 503) this.ready = false;
        let body = null;
        if (response.body) {
            const reader = response.body.getReader();
            body = new ReadableStream({
                async pull(controller) {
                    try {
                        const chunk = await reader.read();
                        if (chunk.done) {
                            release();
                            controller.close();
                        } else {
                            controller.enqueue(chunk.value);
                        }
                    } catch (error) {
                        release();
                        controller.error(error);
                    }
                },
                async cancel(reason) {
                    try {
                        await reader.cancel(reason);
                    } finally {
                        release();
                    }
                },
            });
        } else {
            release();
        }
        const result = new Response(body, response);
        result.headers.set("X-FamilyFunds-Runtime", "cloudflare-container");
        if (this.env.RUNTIME_PROFILE !== "production") result.headers.set("X-Robots-Tag", "noindex, nofollow, noarchive");
        const applicationTiming = response.headers.get("Server-Timing");
        result.headers.set("Server-Timing", `container_start;dur=${startupMs.toFixed(1)}, gateway_queue;dur=${queueMs.toFixed(1)}, container_headers;dur=${(performance.now() - upstreamStarted).toFixed(1)}${applicationTiming ? `, ${applicationTiming}` : ""}`);
        const immutableAsset = response.status === 200 && ["GET", "HEAD"].includes(request.method)
            && (/^\/build\/assets\/[^/]+-[A-Za-z0-9_-]{6,}\.(?:js|css|woff2?)$/.test(url.pathname)
                || (/^\/livewire-[a-f0-9]{8}\/livewire\.min\.js$/.test(url.pathname)
                    && /^[a-f0-9]{8}$/.test(url.searchParams.get("id") ?? "")
                    && (response.headers.get("Content-Type") ?? "").includes("javascript")));
        result.headers.set("Cache-Control", immutableAsset ? "private, max-age=31536000, immutable" : "no-store");
        return result;
    }

    async restart() {
        if (this.starting) await this.starting;
        await this.ctx.container.destroy();
        this.ready = false;
        return true;
    }

    async inspect() {
        const info = await this.ctx.container.inspect();
        return {running: this.ctx.container.running, image: info?.image ?? null};
    }

    async scheduledProof(kind, familyId, at) {
        const families = JSON.parse(this.env.STAGING_SCHEDULE_FAMILIES ?? "{}");
        const expected = kind === "purge" ? families.purge : kind === "reports" ? 43 : families.family;
        if (!["generate", "remind25", "remind28", "purge", "reports", "retry-notifications"].includes(kind)
            || !Number.isSafeInteger(familyId) || familyId !== expected || typeof at !== "string"
            || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(at) || !Number.isFinite(Date.parse(at)) || Date.parse(at) > Date.now()) {
            throw new Error("Invalid isolated maintenance operation");
        }
        let stage = "startup";
        try {
            if (!this.ctx.container.running) this.ready = false;
            if (!this.ready) {
                this.starting ??= this.waitForReady().finally(() => { this.starting = null; });
                await this.starting;
            }
            if (!this.ready) throw new Error("Staging container is not ready");
            const env = {...JSON.parse(this.env.CONTAINER_ENV), APP_ENV: "d1-staging", D1_STAGING_ENABLED: "true",
                D1_STAGING_DATABASE: "familyfunds-d1-web-staging", D1_STAGING_URL: "https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev"};
            if (this.env.OPTIMIZED_BOOTSTRAP === "v1") {
                stage = "CLI installation";
                const asset = STAGING_BOOTSTRAP_ASSETS.find(asset => asset.path === "/var/www/html/deployment/d1-proof/run-staging.php");
                if (!asset || !/^[a-f0-9]{64}$/.test(asset.sha256)) throw new Error("Missing packaged maintenance CLI");
                const installEnv = {...env};
                const names = bootstrapParts(installEnv, "FF_STAGING_CLI", asset.content);
                const php = `$content="";foreach([${names.map(name => JSON.stringify(name)).join(",")}] as $name){$part=getenv($name);if(!is_string($part)){throw new RuntimeException("Missing staging CLI part");}$content.=$part;}if(!hash_equals("${asset.sha256}",hash("sha256",$content))){throw new RuntimeException("Invalid staging CLI bytes");}if(file_put_contents("/var/www/html/deployment/d1-proof/run-staging.php",$content)!==strlen($content)){throw new RuntimeException("Staging CLI write failed");}chmod("/var/www/html/deployment/d1-proof/run-staging.php",0644);`;
                const installer = await this.containerOutput(["php", "-r", php], {env: installEnv, stdout: "pipe", stderr: "pipe"}, 5000);
                if (installer.exitCode !== 0) throw new Error("Staging CLI installation failed");
            }
            stage = "command execution";
            const result = await this.containerOutput(["php", "/var/www/html/deployment/d1-proof/run-staging.php", "scheduled-maintenance-proof", "/nonexistent-staging-secret-file", "web", kind, String(familyId), at],
                {cwd: "/var/www/html", env, stdout: "pipe", stderr: "pipe"}, 65_000);
            if (result.exitCode !== 0 || result.stdout.byteLength > 131072) throw new Error("Invalid maintenance output");
            stage = "result validation";
            const decoded = JSON.parse(new TextDecoder().decode(result.stdout).trim());
            if (!Number.isInteger(decoded?.exit_code)) throw new Error("Missing maintenance exit code");
            return decoded;
        } catch {
            throw new Error(`Isolated staging maintenance failed during ${stage}`);
        }
    }

    async runtime() {
        const info = await this.inspect();
        const version = {build: this.env.RUNTIME_REVISION, bootstrap: this.env.OPTIMIZED_BOOTSTRAP,
            startup: {attempts: this.startupState.attempts, allocations: this.startupState.allocations,
                requested_ms: this.startupState.requested_ms, port_status: this.startupState.portStatus,
                phase: this.startupState.phase, allocation_error: this.startupState.allocationError,
                start_error: this.startupState.start_error}};
        if (!info.running) return {...info, ...version};
        const php = 'preg_match("/^MemTotal:\\s+(\\d+) kB/m",file_get_contents("/proc/meminfo"),$memory);echo json_encode(["php"=>PHP_VERSION,"extensions"=>get_loaded_extensions(),"memory_limit"=>ini_get("memory_limit"),"vm_memory_kib"=>isset($memory[1])?(int)$memory[1]:null,"cpu_count"=>substr_count(file_get_contents("/proc/cpuinfo"),"processor"),"nginx_configuration_sha256"=>hash_file("sha256","/etc/nginx/nginx.conf"),"supervisor_configuration_sha256"=>hash_file("sha256","/etc/supervisord.conf"),"runtime_source_sha256"=>hash_file("sha256","/var/www/html/deployment/d1-proof/D1StagingRuntime.php"),"provider_source_sha256"=>hash_file("sha256","/var/www/html/deployment/d1-proof/D1StagingServiceProvider.php"),"startup_profile"=>is_file("/run/familyfunds-startup.json")?json_decode(file_get_contents("/run/familyfunds-startup.json"),true):null,"ready_profile"=>is_file("/var/www/html/bootstrap/cache/startup-ready.json")?json_decode(file_get_contents("/var/www/html/bootstrap/cache/startup-ready.json"),true):null,"processes"=>array_count_values(array_map(fn($f)=>trim(file_get_contents($f)),glob("/proc/[0-9]*/comm")))]);';
        const output = await this.containerOutput(["php", "-r", php], {stdout: "pipe", stderr: "pipe"}, 5000);
        if (output.exitCode !== 0) throw new Error("Staging runtime inspection failed");
        return {...info, ...version, runtime: JSON.parse(new TextDecoder().decode(output.stdout))};
    }

}

export default {
    async queue(batch, env) {
        const config = webhookConfig(env);
        for (const message of batch.messages) {
            try {
                if (!config || !await verifyWebhook(message.body, config)) throw new Error("Invalid staging relay event");
                const record = message.body;
                const headers = {"Content-Type": "application/json", "X-D1-Staging-Relay": env.ADMIN_TOKEN};
                headers[record.provider === "paystack" ? "X-Paystack-Signature" : "X-Hub-Signature-256"] = record.signature;
                const container = env.STAGING_CONTAINER.getByName("isolated-staging");
                const response = await container.fetch(new Request(`https://d1-staging.familyfunds.app/webhooks/${record.provider}`, {method: "POST", headers, body: record.raw}));
                await response.body?.cancel();
                if (![200, 202].includes(response.status)) throw new Error(`Staging webhook relay returned ${response.status}`);
                message.ack();
            } catch {
                message.retry({delaySeconds: 30});
                console.warn(JSON.stringify({event: "staging_webhook_relay_retry", messageId: message.id}));
            }
        }
    },
    async scheduled(controller, env) {
        const container = env.STAGING_CONTAINER.getByName("isolated-staging");
        const response = await container.fetch(new Request("https://d1-staging.familyfunds.app/up", {signal: AbortSignal.timeout(30_000)}));
        await response.body?.cancel();
        if (response.status !== 200) throw new Error(`Staging scheduler wake failed: ${response.status}`);
        console.log(JSON.stringify({event: "staging_scheduler_wake", scheduledTime: controller.scheduledTime, status: response.status}));
    },
    async fetch(request, env) {
        if (["/webhooks/paystack", "/webhooks/whatsapp"].includes(new URL(request.url).pathname)) return receiveWebhook(request, env);
        const actual = new TextEncoder().encode(request.headers.get("Authorization") ?? "");
        const expected = new TextEncoder().encode(env.BASIC_AUTHORIZATION);
        if (actual.length !== expected.length || !crypto.subtle.timingSafeEqual(actual, expected)) {
            return new Response("Authentication required", {status: 401, headers: {"WWW-Authenticate": 'Basic realm="FamilyFunds isolated D1 staging"', "X-Robots-Tag": "noindex, nofollow, noarchive"}});
        }
        const candidate = request.headers.get("X-D1-Staging-Candidate");
        if (candidate && (candidate !== "lean" || request.headers.get("X-Staging-Admin") !== env.ADMIN_TOKEN)) {
            return new Response("Forbidden", {status: 403});
        }
        if (candidate && !env.STAGING_CANDIDATE) return new Response("Candidate unavailable", {status: 503});
        const container = (candidate ? env.STAGING_CANDIDATE : env.STAGING_CONTAINER).getByName("isolated-staging");
        const path = new URL(request.url).pathname;
        if (path.startsWith("/__staging_admin/")) {
            if (request.headers.get("X-Staging-Admin") !== env.ADMIN_TOKEN) return new Response("Forbidden", {status: 403});
            if (request.method === "POST" && path === "/__staging_admin/restart") {
                await container.restart();
                return Response.json({restarted: true});
            }
            if (request.method === "GET" && path === "/__staging_admin/inspect") return Response.json(await container.inspect(), {headers: {"Cache-Control": "no-store"}});
            if (request.method === "GET" && path === "/__staging_admin/runtime") return Response.json(await container.runtime(), {headers: {"Cache-Control": "no-store"}});
            return new Response("Not found", {status: 404});
        }
        const rpcStarted = performance.now();
        const response = await container.fetch(request);
        const result = new Response(response.body, response);
        result.headers.append("Server-Timing", `edge_rpc;dur=${(performance.now() - rpcStarted).toFixed(1)}`);
        return result;
    }
};
