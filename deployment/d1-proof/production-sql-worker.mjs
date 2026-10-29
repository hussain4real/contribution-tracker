import {timingSafeEqual} from "node:crypto";

export default {
    async fetch(request, env) {
        if (env.DATABASE_NAME !== "familyfunds-production" || typeof env.SQL_TOKEN !== "string" || env.SQL_TOKEN.length < 32) return new Response("Unavailable", {status: 503});
        const supplied = new TextEncoder().encode(request.headers.get("Authorization") ?? "");
        const expected = new TextEncoder().encode("Bearer " + env.SQL_TOKEN);
        if (supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) return new Response("Unauthorized", {status: 401});
        const path = new URL(request.url).pathname;
        if (request.method !== "POST" || !["/query", "/batch"].includes(path)) return new Response("Not found", {status: 404});
        const reader = request.body?.getReader();
        if (!reader) return new Response("Missing body", {status: 400});
        let length = 0;
        const chunks = [];
        while (true) {
            const {done, value} = await reader.read();
            if (done) break;
            length += value.byteLength;
            if (length > 8_000_000) { await reader.cancel(); return new Response("Request too large", {status: 413}); }
            chunks.push(value);
        }
        const bytes = new Uint8Array(length);
        let offset = 0;
        for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
        let queries;
        try {
            const body = JSON.parse(new TextDecoder("utf-8", {fatal: true}).decode(bytes));
            queries = path === "/batch" ? body.batch : [body];
            if (!Array.isArray(queries) || queries.length < 1 || queries.length > 1000) throw new Error();
            for (const query of queries) {
                if (!query || typeof query.sql !== "string" || new TextEncoder().encode(query.sql).length > 100_000
                    || !Array.isArray(query.params) || query.params.length > 100
                    || !query.params.every(value => value === null || typeof value === "string" || (typeof value === "number" && Number.isFinite(value) && (!Number.isInteger(value) || Number.isSafeInteger(value))))) throw new Error();
            }
        } catch { return Response.json({error: "Invalid D1 request"}, {status: 400}); }
        try {
            const statements = queries.map(query => env.DB.prepare(query.sql).bind(...query.params));
            const result = path === "/batch" ? await env.DB.batch(statements) : await statements[0].all();
            return Response.json({database: env.DATABASE_NAME, result}, {headers: {"Cache-Control": "no-store"}});
        } catch (error) {
            const stale = error instanceof Error && error.message.includes("d1_staging_fresh_snapshot");
            return Response.json({error: stale ? "d1_staging_fresh_snapshot" : "D1 operation failed"}, {status: stale ? 409 : 503});
        }
    },
};
