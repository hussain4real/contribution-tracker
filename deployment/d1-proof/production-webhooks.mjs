import {timingSafeEqual} from "node:crypto";
import {getProductionContainer} from './production-container-target.mjs';

const encoder = new TextEncoder();
const MAX_BYTES = 2_000_000;

function equal(left, right) {
    const a = encoder.encode(left), b = encoder.encode(right);
    return a.length === b.length && timingSafeEqual(a, b);
}

export function productionWebhookConfig(env) {
    if (env.WEBHOOKS_ENABLED !== "true") return null;
    if (env.DATABASE_NAME !== "familyfunds-production") throw new Error("Wrong production webhook database");
    const config = JSON.parse(env.WEBHOOK_SECRETS ?? "{}");
    if (!config.paystack_secret?.startsWith("sk_live_") || config.meta_app_id !== "26405719292393073"
        || !config.meta_app_secret || !config.meta_waba || !config.meta_phone || !config.meta_verify_token) {
        throw new Error("Production webhook credentials are incomplete");
    }
    return config;
}

export async function verifyProductionWebhook(record, config) {
    if (!record || !["paystack", "whatsapp"].includes(record.provider) || typeof record.raw !== "string"
        || encoder.encode(record.raw).length > MAX_BYTES || typeof record.signature !== "string") return false;
    const meta = record.provider === "whatsapp";
    const hex = meta ? record.signature.replace(/^sha256=/, "") : record.signature;
    if (!(meta ? /^[a-f0-9]{64}$/i : /^[a-f0-9]{128}$/i).test(hex) || (meta && !record.signature.startsWith("sha256="))) return false;
    const key = await crypto.subtle.importKey("raw", encoder.encode(meta ? config.meta_app_secret : config.paystack_secret), {name: "HMAC", hash: meta ? "SHA-256" : "SHA-512"}, false, ["verify"]);
    const signature = Uint8Array.from(hex.match(/../g), pair => parseInt(pair, 16));
    if (!await crypto.subtle.verify("HMAC", key, signature, encoder.encode(record.raw))) return false;
    try {
        const payload = JSON.parse(record.raw);
        if (!meta) return typeof payload.event === "string" && payload.event.length <= 128 && payload.data !== null
            && typeof payload.data === "object" && !Array.isArray(payload.data)
            && (payload.data.domain === undefined || payload.data.domain === "live");
        return payload.object === "whatsapp_business_account" && Array.isArray(payload.entry) && payload.entry.length > 0
            && payload.entry.every(entry => entry.id === config.meta_waba && Array.isArray(entry.changes) && entry.changes.length > 0
                && entry.changes.every(change => change.field === "messages" && change.value?.metadata?.phone_number_id === config.meta_phone));
    } catch { return false; }
}

async function hash(raw) {
    return Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", encoder.encode(raw))), byte => byte.toString(16).padStart(2, "0")).join("");
}

export async function receiveProductionWebhook(request, env) {
    const config = productionWebhookConfig(env);
    if (!config) return new Response("Webhook processing is not active", {status: 503});
    const provider = new URL(request.url).pathname === "/webhooks/paystack" ? "paystack" : "whatsapp";
    if (provider === "whatsapp" && request.method === "GET") {
        const query = new URL(request.url).searchParams;
        const challenge = query.get("hub.challenge") ?? "";
        if (query.get("hub.mode") !== "subscribe" || !equal(query.get("hub.verify_token") ?? "", config.meta_verify_token) || challenge.length > 256) return new Response("Forbidden", {status: 403});
        return new Response(challenge, {headers: {"Content-Type": "text/plain", "Cache-Control": "no-store"}});
    }
    if (request.method !== "POST") return new Response("Method not allowed", {status: 405});
    const reader = request.body?.getReader();
    if (!reader) return new Response("Missing body", {status: 400});
    const chunks = [];
    let size = 0;
    while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        size += value.byteLength;
        if (size > MAX_BYTES) { await reader.cancel(); return new Response("Payload too large", {status: 413}); }
        chunks.push(value);
    }
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
    let raw;
    try { raw = new TextDecoder("utf-8", {fatal: true}).decode(bytes); }
    catch { return new Response("Invalid encoding", {status: 400}); }
    const record = {provider, raw, signature: request.headers.get(provider === "paystack" ? "X-Paystack-Signature" : "X-Hub-Signature-256") ?? ""};
    if (!await verifyProductionWebhook(record, config)) return new Response("Forbidden", {status: 403});
    const digest = await hash(raw);
    const id = `${provider}:${digest}`;
    const key = `private/webhook-inbox/${provider}/${digest}.json`;
    const previous = await env.DB.prepare("select accepted_at from d1_runtime_webhook_inbox where id=?").bind(id).first();
    if (previous?.accepted_at == null) {
        await env.FILES.put(key, JSON.stringify(record), {onlyIf: {etagDoesNotMatch: "*"}, httpMetadata: {contentType: "application/json"}});
        await env.DB.prepare("insert into d1_runtime_webhook_inbox(id,object_key,created_at) values(?,?,unixepoch()) on conflict(id) do nothing").bind(id, key).run();
        await env.WEBHOOK_QUEUE.send({id, provider, key, sha256: digest}, {contentType: "json"});
    }
    return Response.json({message: "Accepted"}, {status: provider === "paystack" ? 202 : 200, headers: {"Cache-Control": "no-store"}});
}

export async function relayProductionWebhooks(batch, env) {
    const config = productionWebhookConfig(env);
    for (const message of batch.messages) {
        try {
            if (!config) throw new Error("Production webhooks are disabled");
            const ref = message.body;
            if (!ref || !["paystack", "whatsapp"].includes(ref.provider) || !/^[a-f0-9]{64}$/.test(ref.sha256)
                || ref.id !== `${ref.provider}:${ref.sha256}` || ref.key !== `private/webhook-inbox/${ref.provider}/${ref.sha256}.json`) throw new Error("Invalid webhook reference");
            const inbox = await env.DB.prepare("select object_key,accepted_at from d1_runtime_webhook_inbox where id=?").bind(ref.id).first();
            if (!inbox || inbox.object_key !== ref.key) throw new Error("Unknown webhook inbox record");
            if (inbox.accepted_at == null) {
                const object = await env.FILES.get(ref.key);
                if (!object || object.size > 16_000_000) throw new Error("Webhook body unavailable");
                const record = JSON.parse(await object.text());
                if (record.provider !== ref.provider || !await verifyProductionWebhook(record, config) || await hash(record.raw) !== ref.sha256) throw new Error("Webhook integrity check failed");
                if (typeof env.RELAY_TOKEN !== "string" || env.RELAY_TOKEN.length < 32) throw new Error("Missing production relay token");
                const headers = {"Content-Type": "application/json", "X-FamilyFunds-Relay": env.RELAY_TOKEN};
                headers[ref.provider === "paystack" ? "X-Paystack-Signature" : "X-Hub-Signature-256"] = record.signature;
                const response = await getProductionContainer(env).fetch(new Request(`https://familyfunds.app/webhooks/${ref.provider}`, {method: "POST", body: record.raw, headers}));
                await response.body?.cancel();
                if (![200, 202].includes(response.status)) throw new Error("Application has not durably accepted the webhook");
                await env.DB.prepare("update d1_runtime_webhook_inbox set accepted_at=unixepoch() where id=?").bind(ref.id).run();
            }
            await env.FILES.delete(ref.key);
            message.ack();
        } catch {
            message.retry({delaySeconds: 30});
            console.warn(JSON.stringify({event: "production_webhook_retry", messageId: message.id}));
        }
    }
}
