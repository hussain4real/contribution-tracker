import { DurableObject } from "cloudflare:workers";
import { STAGING_BOOTSTRAP_ASSETS } from "./container-bootstrap-assets.mjs";
import { containerEnvironment } from "./container-env.mjs";

function instanceUnavailable(error) {
    return error instanceof Error && error.message.includes("There is no container instance that can be provided");
}

const replacementDatabase = "familyfunds-d1-staging-clone-20261104t184003z";

function replacementWebhooks(env) {
    return ["replacement-webhook-rehearsal", "replacement-live"].includes(env.RUNTIME_PROFILE);
}

function webhookContainer(env) {
    return replacementWebhooks(env) ? env.REPLACEMENT_CONTAINER.getByName("replacement-staging") : env.STAGING_CONTAINER.getByName("isolated-staging");
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

function signatureHeader(provider, config) {
    if (provider === "whatsapp") return "X-Hub-Signature-256";
    return config.paystack_webhook_signing_secret ? "Paystack-Signature" : "X-Paystack-Signature";
}

async function verifyWebhook(record, config) {
    if (!record || !["paystack", "whatsapp"].includes(record.provider) || typeof record.raw !== "string"
        || new TextEncoder().encode(record.raw).length > 49_152 || typeof record.signature !== "string") return false;
    if (config.database === replacementDatabase ? record.database !== replacementDatabase : record.database !== undefined) return false;
    const meta = record.provider === "whatsapp";
    let digests = [meta ? record.signature.replace(/^sha256=/, "") : record.signature];
    let signedPayload = record.raw;
    if (!meta && config.paystack_webhook_signing_secret) {
        if (record.signature.length > 4096) return false;
        const [timestamp, ...entries] = record.signature.split(",");
        if (!/^t=\d{10}$/i.test(timestamp) || !entries.length) return false;
        if (!entries.every(entry => /^v1=whk_[A-Za-z0-9]+:[a-f0-9]{128}$/i.test(entry))) return false;
        digests = entries.map(entry => entry.slice(-128));
        signedPayload = timestamp.slice(2) + "." + record.raw;
    } else if (!(meta ? /^[a-f0-9]{64}$/i : /^[a-f0-9]{128}$/i).test(digests[0]) || (meta && !record.signature.startsWith("sha256="))) {
        return false;
    }
    const secret = meta ? config.meta_app_secret : (config.paystack_webhook_signing_secret ?? config.paystack_test_key);
    if (typeof secret !== "string" || secret === "") return false;
    const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(secret), {name: "HMAC", hash: meta ? "SHA-256" : "SHA-512"}, false, ["verify"]);
    const data = new TextEncoder().encode(signedPayload);
    let verified = false;
    for (const digest of digests) {
        const bytes = Uint8Array.from(digest.match(/../g), pair => parseInt(pair, 16));
        if (await crypto.subtle.verify("HMAC", key, bytes, data)) {
            verified = true;
            break;
        }
    }
    if (!verified) return false;
    try {
        return webhookScope(record.provider, JSON.parse(record.raw), config);
    } catch {
        return false;
    }
}

async function receiveWebhook(request, env) {
    const config = FamilyFundsWebStagingContainer.webhookConfig(env);
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
        signature: request.headers.get(signatureHeader(provider, config)) ?? ""};
    if (replacementWebhooks(env)) record.database = config.database;
    if (!await verifyWebhook(record, config)) return new Response("Forbidden", {status: 403});
    if (env.RUNTIME_PROFILE !== "production" && env.STAGING_ADMISSION_CONTROL === "1") {
        const admitted = await webhookContainer(env).enqueueWebhook(record);
        if (!admitted) return new Response("Staging maintenance in progress. No operation was submitted.", {status: 503, headers: {"Retry-After": "30", "Cache-Control": "no-store"}});
    } else {
        await env.STAGING_WEBHOOK_QUEUE.send(record, {contentType: "json"});
    }
    return Response.json({message: "Accepted"}, {status: 200, headers: {"Cache-Control": "no-store"}});
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
    static webhookConfig(env) {
        const replacement = replacementWebhooks(env);
        if (!replacement && env.RUNTIME_PROFILE?.startsWith("replacement-")) return null;
        const raw = replacement ? env.REPLACEMENT_WEBHOOK_SECRETS : env.STAGING_WEBHOOK_SECRETS;
        if (!raw) return null;
        const config = JSON.parse(raw);
        if (config.enabled !== true) return null;
        if (config.database !== (replacement ? replacementDatabase : "familyfunds-d1-web-staging") || config.family_id !== 43) {
            throw new Error("Invalid isolated webhook target");
        }
        if (!replacement && env.STAGING_WEBHOOK_SIGNING_ENV !== undefined) {
            if (env.RUNTIME_PROFILE === "production") throw new Error("Invalid staging signing environment");
            const signing = JSON.parse(env.STAGING_WEBHOOK_SIGNING_ENV);
            if (!/^whsec_[A-Za-z0-9_-]+$/.test(signing.paystack_webhook_signing_secret ?? "")
                || !/^[a-f0-9]{32}$/i.test(signing.meta_app_secret ?? "")
                || typeof signing.meta_verify_token !== "string" || !signing.meta_verify_token) throw new Error("Invalid staging webhook credentials");
            config.paystack_webhook_signing_secret = signing.paystack_webhook_signing_secret;
            config.meta_app_secret = signing.meta_app_secret;
            config.meta_verify_token = signing.meta_verify_token;
        }
        if (replacement && (env.STAGING_ADMISSION_CONTROL !== "1" || !env.REPLACEMENT_WEBHOOK_QUEUE?.send
            || !env.REPLACEMENT_CONTAINER?.getByName || !/^sk_test_[a-zA-Z0-9_-]+$/.test(config.paystack_test_key ?? "")
            || !/^whsec_[A-Za-z0-9_-]+$/.test(config.paystack_webhook_signing_secret ?? "")
            || !config.meta_verify_token || !/^[a-f0-9]{32}$/i.test(config.meta_app_secret ?? "") || !/^[a-f0-9]{64}$/.test(config.relay_token ?? "")
            || config.relay_token === env.REPLACEMENT_SQL_TOKEN || !config.meta_phone || !config.meta_waba || !config.recipient)) {
            throw new Error("Invalid replacement webhook configuration");
        }
        return config;
    }

    constructor(ctx, env) {
        super(ctx, env);
        this.ready = false;
        this.starting = null;
        this.inFlight = 0;
        this.waiters = [];
        this.activeMaintenance = 0;
        this.activeRestarts = 0;
        this.activeWebhookSends = 0;
        this.startupState = {attempts: 0, allocations: 0};
        this.label = env.RUNTIME_PROFILE === "production" ? "FamilyFunds" : "Staging";
        this.admissionEnabled = env.RUNTIME_PROFILE !== "production" && env.STAGING_ADMISSION_CONTROL === "1";
        this.admissionPaused = false;
        this.admissionGeneration = 0;
        this.admissionReady = this.admissionEnabled ? ctx.blockConcurrencyWhile(async () => {
            const state = await ctx.storage.get("staging-admission-paused");
            if (state !== undefined && typeof state !== "boolean") throw new Error("Invalid staging admission state");
            this.admissionPaused = state === true;
        }) : Promise.resolve();
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
            if (this.admissionPaused) return;
            const allocated = await this.ctx.blockConcurrencyWhile(async () => {
                if (this.admissionPaused) return false;
                this.startupState.attempts++;
                try {
                    if (!container.running) {
                        this.startupState.phase = "start";
                        const env = containerEnvironment(this.env, {allowStagingPaystackTest: true});
                        if (this.env.STAGING_WEBHOOK_SIGNING_ENV !== undefined && !this.env.RUNTIME_PROFILE?.startsWith("replacement-")) {
                            const webhook = FamilyFundsWebStagingContainer.webhookConfig(this.env);
                            if (!webhook) throw new Error("Staging webhooks are disabled");
                            env.D1_STAGING_PAYSTACK_WEBHOOK_SIGNING_SECRET = webhook.paystack_webhook_signing_secret;
                            env.D1_STAGING_META_APP_SECRET = webhook.meta_app_secret;
                        }
                        if (this.env.STARTUP_PROFILE === "1") {
                            env.FF_STARTUP_PROFILE = "1";
                            env.FF_STAGING_START_REQUEST_MS = String(Date.now());
                        }
                        const options = await this.startupOptions(env);
                        if (this.env.OPTIMIZED_BOOTSTRAP === "v1") {
                            const files = STAGING_BOOTSTRAP_ASSETS.filter(asset => asset.path !== "/var/www/html/deployment/d1-proof/run-staging.php").map((asset, i) => {
                                if (!/^\/var\/www\/html\/[A-Za-z0-9_./-]+$/.test(asset.path) || asset.path.includes("..") || !/^[a-f0-9]{64}$/.test(asset.sha256)) throw new Error("Invalid packaged bootstrap asset");
                                const name = `FF_STAGING_ASSET_${i}`;
                                const names = bootstrapParts(env, name, asset.content);
                                const destination = asset.path.endsWith("/container-nginx-staging.conf") ? "/etc/nginx/nginx.conf"
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
            if (this.admissionPaused) return;
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
                if (this.admissionPaused) return;
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
        await this.admissionReady;
        if (this.admissionPaused) return new Response("Staging maintenance in progress. No operation was submitted.", {status: 503, headers: {"Retry-After": "30", "Cache-Control": "no-store"}});
        const startupStarted = performance.now();
        const container = this.ctx.container;
        if (!container) return new Response(`${this.label} container is not configured`, {status: 503});
        if (!container.running) this.ready = false;
        if (!this.ready) {
            this.starting ??= this.waitForReady().finally(() => { this.starting = null; });
            await this.starting;
        }
        if (this.admissionPaused) return new Response("Staging maintenance in progress. No operation was submitted.", {status: 503, headers: {"Retry-After": "30", "Cache-Control": "no-store"}});
        if (!this.ready) return new Response(`${this.label} container is starting. Retry shortly.`, {status: 503, headers: {"Retry-After": "2"}});
        const startupMs = performance.now() - startupStarted;
        const url = new URL(request.url);
        const consumesSlot = !url.pathname.startsWith("/build/");
        const queueStarted = performance.now();
        if (consumesSlot && !await this.acquireRequest()) return new Response(`${this.label} is busy. No operation was submitted; retry shortly.`, {status: 503, headers: {"Retry-After": "2"}});
        if (this.admissionPaused) {
            if (consumesSlot) this.releaseRequest();
            return new Response("Staging maintenance in progress. No operation was submitted.", {status: 503, headers: {"Retry-After": "30", "Cache-Control": "no-store"}});
        }
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
        await this.admissionReady;
        if (this.admissionPaused) throw new Error("Staging admission is paused");
        this.activeRestarts++;
        try {
            if (this.starting) await this.starting;
            if (this.admissionPaused) throw new Error("Staging admission is paused");
            await this.ctx.container.destroy();
            this.ready = false;
            return true;
        } finally {
            this.activeRestarts--;
        }
    }

    async inspect() {
        await this.admissionReady;
        const info = await this.ctx.container.inspect();
        const result = {running: this.ctx.container.running, image: info?.image ?? null};
        if (this.env.RUNTIME_PROFILE === "production") return result;
        const admission = this.admissionEnabled ? await this.ctx.blockConcurrencyWhile(async () => {
            const unresolved = await this.ctx.storage.list({prefix: "staging-webhook-send:", limit: 1001});
            return {admission_paused: this.admissionPaused, unresolved_webhook_sends: unresolved.size};
        }) : {};
        return {...result, activity: {request_slots: this.inFlight, waiting_requests: this.waiters.length,
            starting: this.starting !== null, maintenance_rpcs: this.activeMaintenance},
            ...(this.admissionEnabled ? {...admission, restart_operations: this.activeRestarts, webhook_sends: this.activeWebhookSends} : {})};
    }

    async enqueueWebhook(record) {
        await this.admissionReady;
        if (!this.admissionEnabled) throw new Error("Staging admission control is disabled");
        const config = FamilyFundsWebStagingContainer.webhookConfig(this.env);
        if (!config || !await verifyWebhook(record, config)) throw new Error("Invalid staging webhook");
        const id = await this.ctx.blockConcurrencyWhile(async () => {
            if (this.admissionPaused) return null;
            const operation = crypto.randomUUID();
            await this.ctx.storage.put(`staging-webhook-send:${operation}`, true);
            return operation;
        });
        if (id === null) return false;
        this.activeWebhookSends++;
        try {
            const queue = replacementWebhooks(this.env) ? this.env.REPLACEMENT_WEBHOOK_QUEUE : this.env.STAGING_WEBHOOK_QUEUE;
            await queue.send(record, {contentType: "json"});
            if (!await this.ctx.storage.delete(`staging-webhook-send:${id}`)) throw new Error("Unknown staging webhook operation");
            return true;
        } finally {
            this.activeWebhookSends--;
        }
    }

    async setAdmissionPaused(paused) {
        await this.admissionReady;
        if (!this.admissionEnabled || typeof paused !== "boolean") throw new Error("Staging admission control is disabled");
        await this.ctx.blockConcurrencyWhile(async () => {
            if (paused) this.admissionPaused = true;
            this.admissionGeneration++;
            await this.ctx.storage.put("staging-admission-paused", paused);
            if (!paused) this.admissionPaused = false;
        });
        return {admission_paused: this.admissionPaused};
    }

    async stagingFpmStatus() {
        await this.admissionReady;
        if (!this.admissionEnabled || this.env.STAGING_FPM_STATUS_ENABLED !== "1" || !this.admissionPaused || !this.ctx.container?.running) {
            throw new Error("Staging FPM status is unavailable");
        }
        const generation = this.admissionGeneration;
        const asset = STAGING_BOOTSTRAP_ASSETS.find(asset => asset.path === "/var/www/html/deployment/d1-proof/staging-fpm-status.php");
        if (!asset || !/^[a-f0-9]{64}$/.test(asset.sha256)) throw new Error("Staging FPM status is unavailable");
        const php = `$path=${JSON.stringify(asset.path)};$hash=@hash_file("sha256",$path);if(!is_string($hash)||!hash_equals(${JSON.stringify(asset.sha256)},$hash)){exit(1);}require $path;`;
        const output = await this.containerOutput(["php", "-r", php], {stdout: "pipe", stderr: "pipe"}, 5000);
        if (output.exitCode !== 0 || output.stdout.byteLength > 4096 || !this.admissionPaused || this.admissionGeneration !== generation || !this.ctx.container?.running) throw new Error("Staging FPM status is unavailable");
        const status = JSON.parse(new TextDecoder().decode(output.stdout));
        const keys = ["active_processes", "listen_queue", "listen_queue_capacity", "total_processes", "start_time"];
        if (!status || typeof status !== "object" || Object.keys(status).length !== keys.length
            || keys.some(key => !Number.isSafeInteger(status[key]) || status[key] < 0)
            || status.total_processes < 1 || status.active_processes > status.total_processes || status.start_time < 1
            || status.listen_queue_capacity < 1 || status.listen_queue > status.listen_queue_capacity + 1) {
            throw new Error("Invalid staging FPM status");
        }
        return status;
    }

    async scheduledProof(kind, familyId, at) {
        await this.admissionReady;
        if (this.admissionPaused) throw new Error("Staging admission is paused");
        const families = JSON.parse(this.env.STAGING_SCHEDULE_FAMILIES ?? "{}");
        const replacement = ["replacement-rehearsal", "replacement-live"].includes(this.env.RUNTIME_PROFILE);
        if (replacement && (!Number.isSafeInteger(families.family) || families.family < 1
            || !Number.isSafeInteger(families.purge) || families.purge < 1 || families.family === families.purge)) {
            throw new Error("Missing synthetic replacement maintenance families");
        }
        const expected = kind === "purge" ? families.purge : kind === "reports" ? 43 : families.family;
        if (!["generate", "remind25", "remind28", "purge", "reports", "retry-notifications"].includes(kind)
            || !Number.isSafeInteger(familyId) || familyId !== expected || typeof at !== "string"
            || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(at) || !Number.isFinite(Date.parse(at)) || Date.parse(at) > Date.now()) {
            throw new Error("Invalid isolated maintenance operation");
        }
        const database = replacement ? "familyfunds-d1-staging-clone-20261104t184003z" : "familyfunds-d1-web-staging";
        const url = replacement ? "https://familyfunds-d1-replacement-sql-nov04c.familyfunds-lab.workers.dev" : "https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev";
        let configured = replacement ? {} : containerEnvironment(this.env, {allowStagingPaystackTest: true});
        if (!replacement && ((configured.D1_STAGING_DATABASE !== undefined && configured.D1_STAGING_DATABASE !== database)
            || (configured.D1_STAGING_URL !== undefined && configured.D1_STAGING_URL !== url))) {
            throw new Error("Maintenance CLI requires the original staging target");
        }
        const generation = this.admissionGeneration;
        let stage = "startup";
        this.activeMaintenance++;
        try {
            if (replacement) {
                stage = "replacement maintenance target validation";
                configured = (await this.startupOptions()).env;
                if (configured?.D1_STAGING_DATABASE !== database || configured.D1_STAGING_URL !== url
                    || configured.D1_STAGING_R2_BUCKET !== database + "-files" || configured.D1_STAGING_R2_ADMISSION !== "1"
                    || this.env.STAGING_ADMISSION_CONTROL !== "1" || this.env.OPTIMIZED_BOOTSTRAP !== undefined) {
                    throw new Error("Invalid replacement maintenance target");
                }
                if (this.admissionPaused || generation !== this.admissionGeneration) throw new Error("Staging admission changed");
                stage = "startup";
            }
            if (!this.ctx.container.running) this.ready = false;
            if (!this.ready) {
                this.starting ??= this.waitForReady().finally(() => { this.starting = null; });
                await this.starting;
            }
            if (this.admissionPaused || generation !== this.admissionGeneration) throw new Error("Staging admission changed");
            if (!this.ready) throw new Error("Staging container is not ready");
            const env = {...configured, APP_ENV: "d1-staging", D1_STAGING_ENABLED: "true",
                D1_STAGING_DATABASE: database, D1_STAGING_URL: url};
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
            const result = await this.containerOutput(["php", "/var/www/html/deployment/d1-proof/run-staging.php", "scheduled-maintenance-proof", "/nonexistent-staging-secret-file", replacement ? "replacement" : "web", kind, String(familyId), at],
                {cwd: "/var/www/html", env, stdout: "pipe", stderr: "pipe"}, 65_000);
            if (result.exitCode !== 0 || result.stdout.byteLength > 131072) throw new Error("Invalid maintenance output");
            stage = "result validation";
            const decoded = JSON.parse(new TextDecoder().decode(result.stdout).trim());
            if (!Number.isInteger(decoded?.exit_code)) throw new Error("Missing maintenance exit code");
            return decoded;
        } catch {
            throw new Error(`Isolated staging maintenance failed during ${stage}`);
        } finally {
            this.activeMaintenance--;
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
        const php = '$c=require "/var/www/html/bootstrap/cache/config.php";preg_match("/^MemTotal:\\s+(\\d+) kB/m",file_get_contents("/proc/meminfo"),$memory);echo json_encode(["agent_provider"=>$c["ai"]["agent"]["provider"]??null,"agent_model"=>$c["ai"]["agent"]["model"]??null,"transcription_provider"=>$c["ai"]["default_for_transcription"]??null,"openai_key_sha256"=>is_string($c["ai"]["providers"]["openai"]["key"]??null)?hash("sha256",$c["ai"]["providers"]["openai"]["key"]):null,"php"=>PHP_VERSION,"extensions"=>get_loaded_extensions(),"memory_limit"=>ini_get("memory_limit"),"vm_memory_kib"=>isset($memory[1])?(int)$memory[1]:null,"cpu_count"=>substr_count(file_get_contents("/proc/cpuinfo"),"processor"),"nginx_configuration_sha256"=>hash_file("sha256","/etc/nginx/nginx.conf"),"supervisor_configuration_sha256"=>hash_file("sha256","/etc/supervisord.conf"),"runtime_source_sha256"=>hash_file("sha256","/var/www/html/deployment/d1-proof/D1StagingRuntime.php"),"provider_source_sha256"=>hash_file("sha256","/var/www/html/deployment/d1-proof/D1StagingServiceProvider.php"),"startup_profile"=>is_file("/run/familyfunds-startup.json")?json_decode(file_get_contents("/run/familyfunds-startup.json"),true):null,"ready_profile"=>is_file("/var/www/html/bootstrap/cache/startup-ready.json")?json_decode(file_get_contents("/var/www/html/bootstrap/cache/startup-ready.json"),true):null,"processes"=>array_count_values(array_map(fn($f)=>trim(file_get_contents($f)),glob("/proc/[0-9]*/comm")))]);';
        const output = await this.containerOutput(["php", "-r", php], {stdout: "pipe", stderr: "pipe"}, 5000);
        if (output.exitCode !== 0) throw new Error("Staging runtime inspection failed");
        return {...info, ...version, runtime: JSON.parse(new TextDecoder().decode(output.stdout))};
    }

}

export default {
    async queue(batch, env) {
        const replacement = replacementWebhooks(env);
        if (replacement && batch.queue !== "familyfunds-replacement-webhook-nov04c") throw new Error("Unexpected replacement webhook queue");
        const config = FamilyFundsWebStagingContainer.webhookConfig(env);
        for (const message of batch.messages) {
            try {
                if (!config || !await verifyWebhook(message.body, config)) throw new Error("Invalid staging relay event");
                const record = message.body;
                const headers = {"Content-Type": "application/json", "X-D1-Staging-Relay": replacement ? config.relay_token : env.ADMIN_TOKEN};
                headers[signatureHeader(record.provider, config)] = record.signature;
                const container = webhookContainer(env);
                const origin = replacement ? "https://replacement-staging.internal" : "https://d1-staging.familyfunds.app";
                const response = await container.fetch(new Request(`${origin}/webhooks/${record.provider}`, {method: "POST", headers, body: record.raw}));
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
            if (request.method === "POST" && ["/__staging_admin/pause-admission", "/__staging_admin/resume-admission"].includes(path)) {
                if (env.RUNTIME_PROFILE === "production" || env.STAGING_ADMISSION_CONTROL !== "1") return new Response("Not found", {status: 404});
                return Response.json(await container.setAdmissionPaused(path.endsWith("/pause-admission")), {headers: {"Cache-Control": "no-store"}});
            }
            if (request.method === "POST" && path === "/__staging_admin/restart") {
                await container.restart();
                return Response.json({restarted: true});
            }
            if (request.method === "GET" && path === "/__staging_admin/inspect") return Response.json(await container.inspect(), {headers: {"Cache-Control": "no-store"}});
            if (request.method === "GET" && path === "/__staging_admin/fpm-status") {
                if (env.RUNTIME_PROFILE === "production" || env.STAGING_ADMISSION_CONTROL !== "1" || env.STAGING_FPM_STATUS_ENABLED !== "1") return new Response("Not found", {status: 404});
                return Response.json(await container.stagingFpmStatus(), {headers: {"Cache-Control": "no-store"}});
            }
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
