import { DurableObject } from "cloudflare:workers";

const MAX_BODY_BYTES = 2_000_000;
const MAX_BATCH = 1000;

export class StagingGatewayAdmission extends DurableObject {
    async begin() {
        return this.#begin(false);
    }

    async beginStorageWrite() {
        return this.#begin(true);
    }

    async #begin(storageWrite) {
        return this.ctx.blockConcurrencyWhile(async () => {
            const paused = await this.ctx.storage.get("paused");
            if (paused !== undefined && typeof paused !== "boolean") throw new Error("Invalid stored admission state");
            if (paused) return null;
            const id = crypto.randomUUID();
            if (storageWrite) {
                const receipt = crypto.randomUUID();
                await this.ctx.storage.put(`operation:${id}`, {createdAt: Date.now(), receipt});
                return {id, receipt};
            }
            await this.ctx.storage.put(`operation:${id}`, Date.now());
            return id;
        });
    }

    async complete(id) {
        if (typeof id !== "string" || !/^[a-f0-9-]{36}$/.test(id)) throw new Error("Invalid operation ID");
        if (!await this.ctx.storage.delete(`operation:${id}`)) throw new Error("Unknown gateway operation");
    }

    async completeStorageWrite(id, receipt) {
        return this.ctx.blockConcurrencyWhile(async () => {
            const entry = await this.ctx.storage.get(`operation:${id}`);
            if (!entry || typeof entry !== "object" || typeof receipt !== "string" || entry.receipt !== receipt) return false;
            return this.ctx.storage.delete(`operation:${id}`);
        });
    }

    async setPaused(paused) {
        if (typeof paused !== "boolean") throw new Error("Invalid admission state");
        return this.ctx.blockConcurrencyWhile(async () => {
            await this.ctx.storage.put("paused", paused);
            return this.#readStatus();
        });
    }

    async status() {
        return this.ctx.blockConcurrencyWhile(async () => this.#readStatus());
    }

    async inspectOperations() {
        return this.ctx.blockConcurrencyWhile(async () => this.#readStatus(true));
    }

    async #readStatus(details = false) {
        const pending = await this.ctx.storage.list({prefix: "operation:", limit: 1001});
        const paused = await this.ctx.storage.get("paused");
        if (paused !== undefined && typeof paused !== "boolean") throw new Error("Invalid stored admission state");
        const status = {paused: paused === true, unresolved: pending.size};
        if (!details) return status;
        const operations = [...pending].slice(0, 1000).map(([key, entry]) => {
            const createdAt = entry?.createdAt ?? entry;
            const id = key.slice("operation:".length);
            if (!/^[a-f0-9-]{36}$/.test(id) || !Number.isSafeInteger(createdAt) || createdAt < 0) throw new Error("Invalid stored gateway operation");
            return {id, created_at_ms: createdAt};
        });
        return {...status, operations, truncated: pending.size > 1000};
    }
}

export default {
    async fetch(request, env) {
        const supplied = new TextEncoder().encode(request.headers.get("authorization") ?? "");
        const expected = new TextEncoder().encode("Bearer " + env.PROOF_TOKEN);
        if (typeof env.PROOF_TOKEN !== "string" || !env.PROOF_TOKEN.trim() || supplied.byteLength !== expected.byteLength ||
            !crypto.subtle.timingSafeEqual(supplied, expected)) {
            return Response.json({ error: "Unauthorized" }, { status: 401 });
        }
        const path = new URL(request.url).pathname;
        const namespace = env.STAGING_GATEWAY_ADMISSION_OBJECT;
        // The original gateway has neither binding. An installed coordinator requires an explicit mode.
        const mode = env.STAGING_GATEWAY_ADMISSION === undefined && !namespace ? "0" : env.STAGING_GATEWAY_ADMISSION;
        if (mode !== "0" && (mode !== "1" || !namespace)) {
            return Response.json({error: "Invalid gateway admission configuration"}, {status: 503, headers: {"Cache-Control": "no-store"}});
        }
        if (path === "/storage-write/begin" || path === "/storage-write/complete") {
            const headers = {"Cache-Control": "no-store"};
            if (request.method !== "POST" || mode !== "1" || env.DATABASE_NAME !== "familyfunds-d1-web-staging") {
                return Response.json({error: "Storage admission unavailable"}, {status: 404, headers});
            }
            const gate = namespace.getByName("staging-gateway");
            if (path === "/storage-write/begin") {
                const operation = await gate.beginStorageWrite();
                return operation ? Response.json(operation, {headers}) : Response.json({error: "Paused"}, {status: 503, headers});
            }
            const id = request.headers.get("X-Storage-Operation");
            const receipt = request.headers.get("X-Storage-Receipt");
            const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
            if (!uuid.test(id) || !uuid.test(receipt) || !await gate.completeStorageWrite(id, receipt)) {
                return Response.json({error: "Unknown storage operation"}, {status: 409, headers});
            }
            return new Response(null, {status: 204, headers});
        }
        if (path === "/__staging_admin/admission") {
            if (mode !== "1" || env.DATABASE_NAME !== "familyfunds-d1-web-staging") return Response.json({error: "Not found"}, {status: 404});
            const actual = new TextEncoder().encode(request.headers.get("X-Staging-Gateway-Admin") ?? "");
            const admin = new TextEncoder().encode(env.STAGING_GATEWAY_ADMIN_TOKEN ?? "");
            if (admin.length < 32 || actual.length !== admin.length || !crypto.subtle.timingSafeEqual(actual, admin)) return Response.json({error: "Forbidden"}, {status: 403});
            const gate = namespace.getByName("staging-gateway");
            if (request.method === "GET") {
                const details = new URL(request.url).searchParams.get("details");
                if (details !== null && details !== "1") return Response.json({error: "Invalid inspection request"}, {status: 400});
                return Response.json(details ? await gate.inspectOperations() : await gate.status(), {headers: {"Cache-Control": "no-store"}});
            }
            if (request.method !== "POST") return Response.json({error: "Not found"}, {status: 404});
            const state = new URL(request.url).searchParams.get("paused");
            if (state !== "true" && state !== "false") return Response.json({error: "Invalid admission state"}, {status: 400});
            return Response.json(await gate.setPaused(state === "true"), {headers: {"Cache-Control": "no-store"}});
        }
        if (request.method !== "POST" || !["/query", "/batch", "/manifest"].includes(new URL(request.url).pathname)) {
            return Response.json({ error: "Not found" }, { status: 404 });
        }
        const reader = request.body?.getReader();
        if (!reader) return Response.json({ error: "Missing body" }, { status: 400 });
        const chunks = [];
        let length = 0;
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            length += value.byteLength;
            if (length > MAX_BODY_BYTES) {
                await reader.cancel();
                return Response.json({ error: "Request too large" }, { status: 413 });
            }
            chunks.push(value);
        }
        const bytes = new Uint8Array(length);
        let offset = 0;
        for (const chunk of chunks) {
            bytes.set(chunk, offset);
            offset += chunk.byteLength;
        }
        let operationId = null;
        try {
            const body = JSON.parse(new TextDecoder().decode(bytes));
            const gateEnabled = mode === "1";
            if (gateEnabled && env.DATABASE_NAME !== "familyfunds-d1-web-staging") throw new Error("Invalid staging admission target");
            const gate = gateEnabled ? namespace.getByName("staging-gateway") : null;
            if (path === "/manifest") {
                const id = gate ? await gate.begin() : null;
                if (gate && id === null) return Response.json({error: "Staging maintenance in progress"}, {status: 503, headers: {"Cache-Control": "no-store"}});
                operationId = id;
                const result = await manifest(env);
                if (gate) await gate.complete(id);
                return Response.json(result);
            }
            const isBatch = new URL(request.url).pathname === "/batch";
            const queries = isBatch ? body.batch : [body];
            if (!Array.isArray(queries) || queries.length < 1 || queries.length > MAX_BATCH) {
                return Response.json({ error: "Invalid batch" }, { status: 400 });
            }
            const statements = queries.map((query) => {
                if (!query || typeof query.sql !== "string" || query.sql.length > 100_000 ||
                    !Array.isArray(query.params) || query.params.length > 100 ||
                    !query.params.every((p) => p === null || typeof p === "string" ||
                        (typeof p === "number" && Number.isFinite(p)))) {
                    throw new Error("Invalid query");
                }
                return env.DB.prepare(query.sql).bind(...query.params);
            });
            const id = gate ? await gate.begin() : null;
            if (gate && id === null) return Response.json({error: "Staging maintenance in progress"}, {status: 503, headers: {"Cache-Control": "no-store"}});
            operationId = id;
            const result = isBatch
                ? await env.DB.batch(statements)
                : await statements[0].all();
            if (gate) await gate.complete(id);
            return Response.json({ database: env.DATABASE_NAME, result });
        } catch (error) {
            return Response.json({
                error: error instanceof Error ? error.message : "D1 query failed",
            }, { status: 422, headers: operationId ? {"X-Staging-Gateway-Operation": operationId, "Cache-Control": "no-store"} : {} });
        }
    },
};


async function manifest(env) {
    const schema = await env.DB.prepare("select name from sqlite_master where type = 'table' and name not glob 'sqlite_*' and name not glob 'd1_*' and name not glob '_cf_*' order by name").all();
    const tables = {};
    const encoder = new TextEncoder();
    const names = schema.results.map(({ name }) => {
        if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(name)) throw new Error("Invalid table name");
        return name;
    });
    const results = await env.DB.batch([
        ...names.map((name) => env.DB.prepare(`select * from "${name}"`)),
        env.DB.prepare("pragma foreign_key_check"), env.DB.prepare("pragma quick_check"),
    ]);
    for (const [index, name] of names.entries()) {
        const data = results[index];
        const rows = data.results.map((row) => {
            const text = JSON.stringify(Object.fromEntries(Object.keys(row).sort().map((key) => [key, row[key]])));
            return { text, bytes: encoder.encode(text) };
        });
        rows.sort((left, right) => {
            const length = Math.min(left.bytes.length, right.bytes.length);
            for (let i = 0; i < length; i++) {
                if (left.bytes[i] !== right.bytes[i]) return left.bytes[i] - right.bytes[i];
            }
            return left.bytes.length - right.bytes.length;
        });
        const bytes = encoder.encode(rows.map((row) => row.text).join("\n"));
        const digest = await crypto.subtle.digest("SHA-256", bytes);
        tables[name] = { rows: rows.length, sha256: Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("") };
    }
    return { database: env.DATABASE_NAME, tables, foreign_key_violations: results[names.length].results.length, quick_check: results[names.length + 1].results[0]?.quick_check };
}
