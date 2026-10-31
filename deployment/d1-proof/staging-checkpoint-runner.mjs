const statusPrefix = 'private/staging-backups/d1-web/orchestration/';

function configuration(env) {
    if (env.RUN_ENABLED !== 'true' || env.RUN_MODE !== 'exploratory' || env.DATABASE_NAME !== 'familyfunds-d1-web-staging'
        || env.RECOVERY_DATABASE_NAME !== 'familyfunds-d1-staging-recovery'
        || env.STATUS_BUCKET_NAME !== 'familyfunds-d1-staging-checkpoints') throw new Error('Staging checkpoint runner is disabled or misconfigured');
    if (typeof env.CHECKPOINT_ID !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(env.CHECKPOINT_ID)) throw new Error('Invalid checkpoint ID');
    const artifacts = JSON.parse(env.EXPECTED_ARTIFACTS ?? 'null');
    if (!Array.isArray(artifacts) || artifacts.length !== 2 || artifacts[0]?.id !== 11 || artifacts[1]?.id !== 13
        || artifacts.some(item => typeof item.uuid !== 'string' || !/^[a-f0-9-]{36}$/.test(item.uuid)
            || typeof item.path_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(item.path_sha256))) throw new Error('Invalid expected artifacts');
    return {checkpoint: env.CHECKPOINT_ID, artifacts};
}

async function artifactExceptions(env, expected) {
    const rows = (await env.DB.prepare('select id,uuid,family_id,requested_by,type,format,disk,path from report_artifacts where id in (11,13) order by id').all()).results;
    if (!Array.isArray(rows) || rows.length !== expected.length) throw new Error('Expected artifacts changed');
    const exceptions = [];
    for (let index = 0; index < rows.length; index++) {
        const row = rows[index], item = expected[index];
        if (row.id !== item.id || row.uuid !== item.uuid || row.family_id !== 43 || row.requested_by !== 68
            || row.type !== 'fund_statement' || row.format !== 'csv' || row.disk !== 'local'
            || typeof row.path !== 'string' || !row.path || /(^\/|\.\.|\\)/.test(row.path)) throw new Error('Expected artifacts changed');
        const hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(row.path))), byte => byte.toString(16).padStart(2, '0')).join('');
        if (hash !== item.path_sha256) throw new Error('Expected artifacts changed');
        exceptions.push({id: row.id, path: row.path});
    }
    return exceptions;
}

export default {
    async scheduled(_controller, env) {
        const {checkpoint, artifacts} = configuration(env);
        const key = statusPrefix + checkpoint + '.json';
        const claim = await env.STATUS_FILES.put(key, JSON.stringify({state: 'running'}), {onlyIf: {etagDoesNotMatch: '*'}});
        if (!claim) return;
        let report, stage = 'preflight';
        try {
            const exceptions = await artifactExceptions(env, artifacts);
            stage = 'backup';
            const backup = await env.BACKUP.create(checkpoint, exceptions);
            if (backup.status !== 'created' || JSON.stringify(backup.missing_file_ids) !== '[11,13]'
                || !Number.isSafeInteger(backup.tables) || backup.tables < 40 || !Number.isSafeInteger(backup.files) || backup.files < 0
                || typeof backup.sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(backup.sha256)) throw new Error('Backup did not preserve the approved missing-file record');
            stage = 'recovery';
            const recovery = await env.RECOVERY.restore(checkpoint);
            if (recovery.status !== 'passed' || recovery.integrity !== 'ok' || JSON.stringify(recovery.missing_file_ids) !== '[11,13]'
                || recovery.files !== backup.files || recovery.tables !== backup.tables) throw new Error('Recovery verification failed');
            report = {state: 'passed', consistency: 'not-frozen', checkpoint, tables: backup.tables, files: backup.files,
                missing_file_ids: backup.missing_file_ids, plaintext_sha256: backup.sha256, integrity: recovery.integrity};
        } catch {
            report = {state: 'failed', checkpoint, stage};
        }
        if (!await env.STATUS_FILES.put(key, JSON.stringify(report), {onlyIf: {etagMatches: claim.etag}})) throw new Error('Checkpoint report changed');
    },

    async fetch(request, env) {
        if (request.method !== 'GET' || new URL(request.url).pathname !== '/status') return new Response('Not found', {status: 404});
        try {
            const {checkpoint} = configuration(env);
            const object = await env.STATUS_FILES.get(statusPrefix + checkpoint + '.json');
            if (!object) return new Response('PENDING', {headers: {'Cache-Control': 'no-store'}});
            const state = (await object.json()).state;
            return new Response(state === 'passed' ? 'EXPLORATORY_RESTORE_PASSED' : state === 'failed' ? 'FAILED' : 'PENDING', {headers: {'Cache-Control': 'no-store'}});
        } catch {
            return new Response('UNAVAILABLE', {status: 503, headers: {'Cache-Control': 'no-store'}});
        }
    },
};
