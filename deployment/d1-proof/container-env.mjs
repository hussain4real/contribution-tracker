const encoder = new TextEncoder();

export function containerEnvironment(bindings) {
    let json;
    if (bindings.CONTAINER_ENV_PARTS === undefined) {
        json = bindings.CONTAINER_ENV;
    } else {
        const count = Number(bindings.CONTAINER_ENV_PARTS);
        if (!/^(?:[1-9]|1[0-6])$/.test(bindings.CONTAINER_ENV_PARTS) || !Number.isInteger(count)) throw new Error('Invalid container environment part count');
        const parts = [];
        for (let index = 0; index < count; index++) {
            const part = bindings['CONTAINER_ENV_' + index];
            if (typeof part !== 'string' || encoder.encode(part).length > 4096) throw new Error('Missing or oversized container environment part');
            parts.push(part);
        }
        json = parts.join('');
    }
    if (typeof json !== 'string' || encoder.encode(json).length > 65536) throw new Error('Invalid container environment');
    const result = JSON.parse(json);
    if (!result || typeof result !== 'object' || Array.isArray(result)) throw new Error('Invalid container environment shape');
    if (bindings.PRODUCTION_CUTOVER_STAGE !== undefined) {
        const stage = bindings.PRODUCTION_CUTOVER_STAGE;
        if (!['read-only', 'live'].includes(stage) || result.APP_ENV !== 'production'
            || result.D1_PRODUCTION_ENABLED !== 'true' || result.D1_STAGING_ENABLED !== 'false'
            || result.D1_DATABASE !== 'familyfunds-production' || bindings.MIGRATION_AUTH_ORIGIN !== undefined) {
            throw new Error('Invalid production cutover stage');
        }
        Object.assign(result, {
            APP_URL: 'https://familyfunds.app', APP_NAME: 'FamilyFund',
            PASSKEYS_ALLOWED_ORIGINS: 'https://familyfunds.app',
            D1_MIGRATION_CHECKS: stage === 'read-only' ? 'true' : 'false',
            D1_READ_ONLY_PROVIDER_CHECKS: stage === 'read-only' ? 'true' : 'false',
            D1_PRODUCTION_DELIVERY_ENABLED: stage === 'live' ? 'true' : 'false',
            D1_PRODUCTION_WEBHOOKS_ENABLED: stage === 'live' ? 'true' : 'false',
            NIGHTWATCH_ENABLED: stage === 'live' ? 'true' : 'false',
            QUEUE_WORKER_ENABLED: 'false', D1_CANDIDATE_BANK_LOOKUP_TEST_KEY: '',
            MAIL_MAILER: 'cloudflare', BACKUP_GOOGLE_DRIVE_ENABLED: 'false', BACKUP_RCLONE_ENABLED: 'false',
        });
    }
    if (bindings.MIGRATION_AUTH_ORIGIN !== undefined) {
        if (bindings.MIGRATION_AUTH_ORIGIN !== 'https://familyfunds.app' || result.APP_ENV !== 'production'
            || result.D1_PRODUCTION_ENABLED !== 'true' || result.D1_MIGRATION_CHECKS !== 'true') throw new Error('Invalid migration authentication origin');
        result.APP_URL = bindings.MIGRATION_AUTH_ORIGIN;
        result.PASSKEYS_ALLOWED_ORIGINS = bindings.MIGRATION_AUTH_ORIGIN;
    }
    if (bindings.APPLICATION_INTEGRATION_ENV !== undefined) {
        if (result.APP_ENV !== 'production' || result.D1_PRODUCTION_ENABLED !== 'true'
            || typeof bindings.APPLICATION_INTEGRATION_ENV !== 'string' || encoder.encode(bindings.APPLICATION_INTEGRATION_ENV).length > 4096) throw new Error('Invalid production integration environment');
        const extra = JSON.parse(bindings.APPLICATION_INTEGRATION_ENV);
        const allowed = new Set(['OLLAMA_API_KEY', 'OLLAMA_BASE_URL', 'GEMINI_API_KEY', 'OPENAI_API_KEY', 'OPENAI_URL', 'AI_AGENT_PROVIDER', 'AI_AGENT_MODEL',
            'AI_TRANSCRIPTION_PROVIDER', 'GEMINI_TRANSCRIPTION_MODEL', 'OPENAI_TRANSCRIPTION_MODEL', 'GITHUB_RELEASES_TOKEN', 'GITHUB_RELEASES_OWNER', 'GITHUB_RELEASES_REPO',
            'GITHUB_RELEASES_CACHE_FRESH_TTL', 'GITHUB_RELEASES_CACHE_STALE_TTL', 'GITHUB_RELEASES_MAX', 'GITHUB_RELEASES_INCLUDE_PRERELEASES', 'GITHUB_RELEASES_INCLUDE_BODY']);
        if (!extra || typeof extra !== 'object' || Array.isArray(extra) || Object.keys(extra).some(key => !allowed.has(key) || Object.hasOwn(result, key))) throw new Error('Unexpected or duplicate production integration field');
        Object.assign(result, extra);
    }
    if (bindings.AI_ENV !== undefined) {
        if (typeof bindings.AI_ENV !== 'string' || encoder.encode(bindings.AI_ENV).length > 4096) throw new Error('Invalid AI environment');
        const extra = JSON.parse(bindings.AI_ENV);
        const allowed = new Set(['OPENAI_API_KEY', 'OPENAI_URL', 'AI_AGENT_PROVIDER', 'AI_AGENT_MODEL', 'AI_TRANSCRIPTION_PROVIDER']);
        if (!extra || typeof extra !== 'object' || Array.isArray(extra) || Object.keys(extra).some(key => !allowed.has(key))) throw new Error('Unexpected AI environment field');
        Object.assign(result, extra);
    }
    for (const [name, value] of Object.entries(result)) {
        if (!/^[A-Z_][A-Z0-9_]*$/.test(name) || typeof value !== 'string' || value.includes('\0') || encoder.encode(value).length > 16000) throw new Error('Invalid container environment variable');
    }
    return result;
}
