const MAX_BODY_BYTES = 200_000;
const MAX_BATCH = 20;

export default {
    async fetch(request, env) {
        const supplied = new TextEncoder().encode(request.headers.get("authorization") ?? "");
        const expected = new TextEncoder().encode("Bearer " + env.PROOF_TOKEN);
        if (supplied.byteLength !== expected.byteLength ||
            !crypto.subtle.timingSafeEqual(supplied, expected)) {
            return Response.json({ error: "Unauthorized" }, { status: 401 });
        }
        if (request.method !== "POST" || !["/query", "/batch"].includes(new URL(request.url).pathname)) {
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
            return Response.json({ database: "familyfunds-d1-staging-proof", result });
        } catch (error) {
            return Response.json({
                error: error instanceof Error ? error.message : "D1 query failed",
            }, { status: 422 });
        }
    },
};
