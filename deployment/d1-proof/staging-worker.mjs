const MAX_BODY_BYTES = 2_000_000;
const MAX_BATCH = 1000;

export default {
    async fetch(request, env) {
        const supplied = new TextEncoder().encode(request.headers.get("authorization") ?? "");
        const expected = new TextEncoder().encode("Bearer " + env.PROOF_TOKEN);
        if (supplied.byteLength !== expected.byteLength ||
            !crypto.subtle.timingSafeEqual(supplied, expected)) {
            return Response.json({ error: "Unauthorized" }, { status: 401 });
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
        try {
            const body = JSON.parse(new TextDecoder().decode(bytes));
            if (new URL(request.url).pathname === "/manifest") {
                return Response.json(await manifest(env));
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
            const result = isBatch
                ? await env.DB.batch(statements)
                : await statements[0].all();
            return Response.json({ database: env.DATABASE_NAME, result });
        } catch (error) {
            return Response.json({
                error: error instanceof Error ? error.message : "D1 query failed",
            }, { status: 422 });
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
