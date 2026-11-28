import assert from "node:assert/strict";
import {createHmac} from "node:crypto";
import test from "node:test";
import {productionWebhookConfig, receiveProductionWebhook, relayProductionWebhooks} from "./production-webhooks.mjs";
import sqlWorker from "./production-sql-worker.mjs";
import {PRODUCTION_CONTAINER_NAME} from './production-container-target.mjs';

function fixture() {
    const inbox = new Map(), objects = new Map(), queued = [];
    const forwarded = [];
    const config = {paystack_secret: "sk_live_synthetic_fixture", meta_app_id: "26405719292393073", meta_app_secret: "synthetic-meta-secret", meta_waba: "production-waba-fixture", meta_phone: "production-phone-fixture", meta_verify_token: "synthetic-verify-token"};
    const env = {
        WEBHOOKS_ENABLED: "true", DATABASE_NAME: "familyfunds-production", WEBHOOK_SECRETS: JSON.stringify(config), RELAY_TOKEN: "r".repeat(40),
        DB: {prepare: sql => ({bind: (...params) => ({
            first: async () => inbox.get(params[0]) ?? null,
            run: async () => {
                if (sql.startsWith("insert")) { if (!inbox.has(params[0])) inbox.set(params[0], {object_key: params[1], accepted_at: null}); }
                else if (sql.startsWith("update")) inbox.get(params[0]).accepted_at = 1;
                else throw new Error("Unexpected fixture write");
            },
        })})},
        FILES: {
            put: async (key, value) => { if (!objects.has(key)) objects.set(key, value); return {}; },
            get: async key => objects.has(key) ? {size: Buffer.byteLength(objects.get(key)), text: async () => objects.get(key)} : null,
            delete: async key => objects.delete(key),
        },
        WEBHOOK_QUEUE: {send: async body => queued.push(body)},
        PRODUCTION_CONTAINER: {getByName: name => ({fetch: async request => {
            assert.equal(name, PRODUCTION_CONTAINER_NAME);
            assert.equal(request.headers.get("X-FamilyFunds-Relay"), env.RELAY_TOKEN);
            forwarded.push(request);
            return new Response("durably accepted", {status: 202});
        }})},
    };
    return {env, config, inbox, objects, queued, forwarded: () => forwarded.length, forwardedRequests: forwarded};
}

function request(config, provider = "paystack", payload = {event: "charge.success", data: {domain: "live", reference: "real-shaped-fixture", metadata: {family_id: 987}}}) {
    const raw = JSON.stringify(payload);
    const meta = provider === "whatsapp";
    const signature = (meta ? "sha256=" : "") + createHmac(meta ? "sha256" : "sha512", meta ? config.meta_app_secret : config.paystack_secret).update(raw).digest("hex");
    return new Request(`https://familyfunds.app/webhooks/${provider}`, {method: "POST", body: raw, headers: {[meta ? "X-Hub-Signature-256" : "X-Paystack-Signature"]: signature}});
}

test("production accepts any authorized family while queuing only a small private-object reference", async () => {
    const f = fixture();
    assert.equal((await receiveProductionWebhook(request(f.config), f.env)).status, 202);
    assert.equal(f.queued.length, 1);
    assert.equal(f.objects.size, 1);
    assert.ok(!JSON.stringify(f.queued[0]).includes("reference"));
    assert.ok(Buffer.byteLength(JSON.stringify(f.queued[0])) < 500);
    assert.equal(f.forwarded(), 0);
});

test("bodies larger than the queue message limit remain durable without placing private payloads in the queue", async () => {
    const f = fixture();
    const payload = {event: "charge.success", data: {domain: "live", note: "a".repeat(150_000)}};
    assert.equal((await receiveProductionWebhook(request(f.config, "paystack", payload), f.env)).status, 202);
    assert.ok(Buffer.byteLength(JSON.stringify(f.queued[0])) < 500);
    assert.ok([...f.objects.values()][0].length > 128_000);
});

test("production refuses test credentials, test events and tampered signatures", async () => {
    const f = fixture();
    const invalid = request(f.config); invalid.headers.set("X-Paystack-Signature", "0".repeat(128));
    assert.equal((await receiveProductionWebhook(invalid, f.env)).status, 403);
    assert.equal((await receiveProductionWebhook(request(f.config, "paystack", {event: "charge.success", data: {domain: "test"}}), f.env)).status, 403);
    f.env.WEBHOOK_SECRETS = JSON.stringify({...f.config, paystack_secret: "sk_test_fixture"});
    assert.throws(() => productionWebhookConfig(f.env));
    assert.equal(f.objects.size, 0);
});

function enableTimestampedPaystack(env) {
    const credentials = {api_key: 'rk_live_fixture', public_key: 'pk_live_fixture', webhook_signing_secret: 'whsec_fixture'};
    env.PRODUCTION_CUTOVER_STAGE = 'live';
    env.PAYSTACK_LIVE_CREDENTIALS = JSON.stringify(credentials);
    return credentials;
}

function timestampedRequest(secret, timestamp = Math.floor(Date.now() / 1000)) {
    const raw = JSON.stringify({event: "charge.success", data: {domain: "live", reference: "timestamped-fixture"}});
    const digest = createHmac('sha512', secret).update(timestamp + '.' + raw).digest('hex');
    const signature = `v1=whk_retired:${'0'.repeat(128)}, t=${timestamp}, other=value, v1=whk_current:${digest}`;
    return new Request('https://familyfunds.app/webhooks/paystack', {method: 'POST', body: raw, headers: {'Paystack-Signature': signature}});
}

test("production requires a fresh Paystack signature made with the signing secret", async () => {
    const f = fixture();
    const keys = enableTimestampedPaystack(f.env);
    const timestamp = Math.floor(Date.now() / 1000);
    assert.equal((await receiveProductionWebhook(request(f.config), f.env)).status, 403);
    assert.equal((await receiveProductionWebhook(timestampedRequest(keys.api_key), f.env)).status, 403);
    for (const offset of [-301, 301]) {
        assert.equal((await receiveProductionWebhook(timestampedRequest(keys.webhook_signing_secret, timestamp + offset), f.env)).status, 403);
    }
    const valid = timestampedRequest(keys.webhook_signing_secret);
    const altered = new Request(valid.url, {method: 'POST', body: '{"event":"charge.success","data":{}}', headers: valid.headers});
    assert.equal((await receiveProductionWebhook(altered, f.env)).status, 403);
    assert.equal((await receiveProductionWebhook(valid, f.env)).status, 200);
    assert.equal(f.queued.length, 1);
});

test("a delayed Paystack relay rechecks the original signature and preserves its header", async t => {
    const f = fixture();
    const keys = enableTimestampedPaystack(f.env);
    const incoming = timestampedRequest(keys.webhook_signing_secret);
    assert.equal((await receiveProductionWebhook(incoming, f.env)).status, 200);
    t.mock.method(Date, 'now', () => new Date('2099-01-01T00:00:00Z').getTime());

    let acknowledged = false;
    const message = {body: f.queued[0], ack: () => { acknowledged = true; }, retry: () => assert.fail('An admitted signature should relay')};
    await relayProductionWebhooks({messages: [message]}, f.env);
    assert.equal(acknowledged, true);
    const forwarded = f.forwardedRequests[0];
    assert.equal(forwarded.headers.get('Paystack-Signature'), incoming.headers.get('Paystack-Signature'));
});

test("the signing cutover still relays a previously admitted legacy Paystack record", async () => {
    const f = fixture();
    assert.equal((await receiveProductionWebhook(request(f.config), f.env)).status, 202);
    enableTimestampedPaystack(f.env);

    let acknowledged = false;
    const message = {body: f.queued[0], ack: () => { acknowledged = true; }, retry: () => assert.fail('Pending legacy signature should relay')};
    await relayProductionWebhooks({messages: [message]}, f.env);
    assert.equal(acknowledged, true);
    assert.ok(f.forwardedRequests[0].headers.has('X-Paystack-Signature'));
});

test("production Meta accepts inbound and status recipients within the approved production account scope", async () => {
    const f = fixture();
    const payload = {object: "whatsapp_business_account", entry: [{id: f.config.meta_waba, changes: [{field: "messages", value: {metadata: {phone_number_id: f.config.meta_phone}, messages: [{from: "recipient-one"}], statuses: [{recipient_id: "recipient-two"}]}}]}]};
    assert.equal((await receiveProductionWebhook(request(f.config, "whatsapp", payload), f.env)).status, 200);
    payload.entry[0].id = "other-account";
    assert.equal((await receiveProductionWebhook(request(f.config, "whatsapp", payload), f.env)).status, 403);
});

test("a queue failure never acknowledges the provider and retains the durable inbox body", async () => {
    const f = fixture();
    f.env.WEBHOOK_QUEUE.send = async () => { throw new Error("queue unavailable"); };
    await assert.rejects(() => receiveProductionWebhook(request(f.config), f.env));
    assert.equal(f.objects.size, 1);
    assert.equal([...f.inbox.values()][0].accepted_at, null);
});

test("a relay acknowledges only durable application acceptance and skips a previously accepted duplicate", async () => {
    const f = fixture();
    await receiveProductionWebhook(request(f.config), f.env);
    let acknowledged = 0, retried = 0;
    const message = {id: "queue-fixture", body: f.queued[0], ack: () => acknowledged++, retry: () => retried++};
    await relayProductionWebhooks({messages: [message]}, f.env);
    await relayProductionWebhooks({messages: [message]}, f.env);
    assert.equal(acknowledged, 2); assert.equal(retried, 0); assert.equal(f.forwarded(), 1); assert.equal(f.objects.size, 0);
});

test("a changed private object is retained for recovery without forwarding or acknowledgement", async () => {
    const f = fixture(); await receiveProductionWebhook(request(f.config), f.env);
    const key = f.queued[0].key;
    f.objects.set(key, JSON.stringify({provider: "paystack", raw: "tampered", signature: "0".repeat(128)}));
    let ack = 0, retry = 0;
    await relayProductionWebhooks({messages: [{id: "fixture", body: f.queued[0], ack: () => ack++, retry: () => retry++}]}, f.env);
    assert.equal(ack, 0); assert.equal(retry, 1); assert.equal(f.forwarded(), 0); assert.equal(f.objects.size, 1);
});

test("prepared production webhooks stay disabled until explicitly activated", async () => {
    const f = fixture(); f.env.WEBHOOKS_ENABLED = "false";
    assert.equal((await receiveProductionWebhook(request(f.config), f.env)).status, 503);
    assert.equal(f.objects.size, 0);
});

test("the production SQL bridge requires a real credential, refuses manifest access and sanitizes failures", async () => {
    const env = {DATABASE_NAME: "familyfunds-production", SQL_TOKEN: "t".repeat(40), DB: {prepare: () => ({bind: () => ({all: async () => { throw new Error("private SQL payload must not escape"); }})})}};
    assert.equal((await sqlWorker.fetch(new Request("https://sql/query", {method: "POST", body: "{}"}), env)).status, 401);
    const headers = {Authorization: "Bearer " + env.SQL_TOKEN};
    assert.equal((await sqlWorker.fetch(new Request("https://sql/manifest", {method: "POST", headers, body: "{}"}), env)).status, 404);
    const response = await sqlWorker.fetch(new Request("https://sql/query", {method: "POST", headers, body: JSON.stringify({sql: "select 1", params: []})}), env);
    assert.equal(response.status, 503); assert.equal((await response.json()).error, "D1 operation failed");
});
