const decode = text => Uint8Array.from(atob(text.replaceAll('-', '+').replaceAll('_', '/')), char => char.charCodeAt(0));

export async function receiveNativePush(request, env) {
    if (env.NATIVE_PUSH_ENABLED !== 'true' || request.method !== 'POST'
        || new URL(request.url).origin !== 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev') return new Response('Not found', {status: 404});
    try {
        const auth = request.headers.get('Authorization');
        const match = /^vapid t=([A-Za-z0-9_.-]+), ?k=([A-Za-z0-9_-]+)$/.exec(auth ?? '');
        if (!match || match[2] !== env.NATIVE_VAPID_PUBLIC_KEY) throw new Error('Invalid test sender');
        const [header, claims, signature, extra] = match[1].split('.');
        if (extra || !header || !claims || !signature) throw new Error('Invalid VAPID token');
        const metadata = JSON.parse(new TextDecoder().decode(decode(header)));
        const data = JSON.parse(new TextDecoder().decode(decode(claims)));
        const now = Math.floor(Date.now() / 1000);
        if (metadata.alg !== 'ES256' || data.aud !== new URL(request.url).origin || !Number.isInteger(data.exp)
            || data.exp <= now || data.exp > now + 86400 || data.sub !== 'mailto:candidate@example.invalid') throw new Error('Invalid VAPID claims');
        const key = await crypto.subtle.importKey('raw', decode(match[2]), {name: 'ECDSA', namedCurve: 'P-256'}, false, ['verify']);
        if (!await crypto.subtle.verify({name: 'ECDSA', hash: 'SHA-256'}, key, decode(signature), new TextEncoder().encode(header + '.' + claims))) throw new Error('Invalid VAPID signature');
        if (request.headers.get('Content-Encoding') !== 'aes128gcm') throw new Error('Invalid content encoding');
        const reader = request.body?.getReader();
        if (!reader) throw new Error('Missing push body');
        const chunks = []; let size = 0;
        try {
            while (true) {
                const part = await reader.read(); if (part.done) break;
                size += part.value.byteLength;
                if (size > 8192) {await reader.cancel(); return new Response('Too large', {status: 413});}
                chunks.push(part.value);
            }
        } finally {reader.releaseLock();}
        const bytes = new Uint8Array(size); let offset = 0;
        for (const chunk of chunks) {bytes.set(chunk, offset); offset += chunk.length;}
        if (size < 103) throw new Error('Missing encrypted push payload');
        await env.NATIVE_PROOF.receivePush({body: btoa(String.fromCharCode(...bytes)), encoding: 'aes128gcm', vapid_verified: true});
        return new Response('', {status: 201});
    } catch { return new Response('Unauthorized test push', {status: 401}); }
}
