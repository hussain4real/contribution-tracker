import {timingSafeEqual} from "node:crypto";
import {FamilyFundsWebStagingContainer} from "./container-worker.mjs";
import {receiveNativePush} from './production-native-push.mjs';
import {getProductionContainer} from './production-container-target.mjs';

export class FamilyFundsStagingContainer extends FamilyFundsWebStagingContainer {}

function equal(value, expected) {
    if (typeof value !== 'string' || typeof expected !== 'string' || expected.length < 32) return false;
    const a = new TextEncoder().encode(value), b = new TextEncoder().encode(expected);
    return a.length === b.length && timingSafeEqual(a, b);
}

export default {
    async fetch(request, env) {
        if (new URL(request.url).pathname === '/__native/push') return receiveNativePush(request, env);
        if (!equal(request.headers.get('Authorization'), env.NATIVE_AUTH) && !equal(request.headers.get('X-FamilyFunds-Preview'), env.PREVIEW_TOKEN)) {
            return new Response('Authentication required', {status: 401, headers: {'WWW-Authenticate': 'Basic realm="FamilyFunds isolated native check"'}});
        }
        const path = new URL(request.url).pathname;
        if (path.startsWith('/__native/')) {
            if (!equal(request.headers.get('X-Native-Admin'), env.NATIVE_ADMIN)) return new Response('Forbidden', {status: 403});
            if (env.MIGRATION_ONLY === '1' && !['/__native/inspect-migration', '/__native/inspect-oauth', '/__native/integrations', '/__native/probe-storage', '/__native/runtime', '/__native/startup', '/__native/restart', '/__native/migration/status', '/__native/migration/archive-synthetic', '/__native/migration/backup', '/__native/migration/restore', '/__native/migration/offsite'].includes(path)) return new Response('Not found', {status: 404});
            const target = getProductionContainer(env);
            try {
                if (request.method === 'POST' && ['/__native/migration/status', '/__native/migration/archive-synthetic', '/__native/migration/backup', '/__native/migration/restore', '/__native/migration/offsite'].includes(path)) {
                    const operation = path.split('/').at(-1);
                    const checkpoint = request.headers.get('X-FamilyFunds-Checkpoint');
                    if (['backup', 'restore', 'offsite'].includes(operation) && (typeof checkpoint !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(checkpoint))) return new Response('Explicit checkpoint required', {status: 400});
                    return Response.json(await env.MIGRATION_RECOVERY.run(operation, checkpoint));
                }
                if (request.method === 'POST' && ['/__native/proof/backup', '/__native/proof/schedule', '/__native/proof/tasks', '/__native/proof/queue-work', '/__native/proof/restore', '/__native/proof/offsite', '/__native/proof/webpush'].includes(path)) {
                    return Response.json(await env.NATIVE_PROOF.run(path.split('/').at(-1)));
                }
                if (path === '/__native/install' && request.method === 'POST') return Response.json(await target.productionCommand('install'));
                if (path === '/__native/inspect-migration' && request.method === 'POST') return Response.json(await target.productionCommand('inspect-migration'));
                if (path === '/__native/probe-storage' && request.method === 'POST') return Response.json(await target.productionCommand('probe-storage'));
                if (path === '/__native/task' && request.method === 'POST') {
                    const body = await request.text();
                    if (body.length > 8192) return new Response('Too large', {status: 413});
                    return Response.json(await target.productionCommand('task', JSON.parse(body)));
                }
                if (path === '/__native/runtime' && request.method === 'GET') return Response.json(await target.runtime());
                if (path === '/__native/integrations' && request.method === 'GET') return Response.json(await target.inspectIntegrations());
                if (path === '/__native/inspect-oauth' && request.method === 'POST') {
                    const reader = request.body?.getReader();
                    if (!reader) return new Response('Missing input', {status: 400});
                    const chunks = [];
                    let size = 0;
                    while (true) {
                        const {done, value} = await reader.read();
                        if (done) break;
                        size += value.byteLength;
                        if (size > 10000) { await reader.cancel(); return new Response('Too large', {status: 413}); }
                        chunks.push(value);
                    }
                    const bytes = new Uint8Array(size);
                    let offset = 0;
                    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
                    let body;
                    try { body = JSON.parse(new TextDecoder().decode(bytes)); }
                    catch { return new Response('Invalid input', {status: 400}); }
                    if (!body || typeof body !== 'object' || Object.keys(body).length !== 1 || typeof body.token !== 'string') return new Response('Invalid input', {status: 400});
                    return Response.json(await target.inspectExistingOAuth(body.token));
                }
                if (path === '/__native/startup' && request.method === 'GET') return Response.json(await target.startupStatus());
                if (path === '/__native/restart' && request.method === 'POST') return Response.json({restarted: await target.restart()});
            } catch (error) { return Response.json({error: String(error).slice(0, 512)}, {status: 503}); }
            return new Response('Not found', {status: 404});
        }
        const targetURL = new URL(request.url);
        if (env.MIGRATION_ONLY === '1') targetURL.hostname = 'familyfunds.app';
        const response = await env.RUNTIME.fetch(new Request(targetURL, request));
        const result = new Response(response.body, response);
        result.headers.set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        result.headers.set('X-FamilyFunds-Proxy-Revision', 'production-v19');
        return result;
    },
};
