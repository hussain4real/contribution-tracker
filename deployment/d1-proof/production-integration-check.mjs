import {createHash} from 'node:crypto';

export async function inspectIntegrationMetadata(config, send = fetch) {
    if (config.D1_MIGRATION_CHECKS !== 'true') throw new Error('Migration inspection required');
    if (new URL(config.OLLAMA_BASE_URL).origin !== 'https://ollama.com' || new URL(config.OPENAI_URL).origin !== 'https://api.openai.com'
        || !/^[\w.-]+$/.test(config.GITHUB_RELEASES_OWNER) || !/^[\w.-]+$/.test(config.GITHUB_RELEASES_REPO)) throw new Error('Unreviewed integration endpoint');
    const keys = ['OLLAMA_API_KEY', 'GEMINI_API_KEY', 'OPENAI_API_KEY', 'GITHUB_RELEASES_TOKEN'];
    if (keys.some(key => typeof config[key] !== 'string' || config[key].length === 0)) throw new Error('Missing integration credential');
    const checks = [
        ['ollama', 'https://ollama.com/api/tags', {Authorization: 'Bearer ' + config.OLLAMA_API_KEY}],
        ['openai', 'https://api.openai.com/v1/models', {Authorization: 'Bearer ' + config.OPENAI_API_KEY}],
        ['gemini', 'https://generativelanguage.googleapis.com/v1beta/models', {'x-goog-api-key': config.GEMINI_API_KEY}],
        ['github', 'https://api.github.com/repos/' + config.GITHUB_RELEASES_OWNER + '/' + config.GITHUB_RELEASES_REPO + '/releases?per_page=1',
            {Authorization: 'Bearer ' + config.GITHUB_RELEASES_TOKEN, Accept: 'application/vnd.github+json', 'User-Agent': 'FamilyFunds-migration-inspection', 'X-GitHub-Api-Version': '2022-11-28'}],
    ];
    const results = await Promise.all(checks.map(async ([provider, url, headers]) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await send(url, {method: 'GET', headers, redirect: 'manual', signal: controller.signal});
            if (response.status !== 200) { await response.body?.cancel(); return {provider, status: response.status, ok: false}; }
            const reader = response.body.getReader();
            const chunks = [];
            let size = 0;
            try {
                while (true) {
                    const {done, value} = await reader.read();
                    if (done) break;
                    size += value.byteLength;
                    if (size > 1048576) throw new Error('Metadata limit');
                    chunks.push(value);
                }
            } finally { await reader.cancel(); }
            const bytes = new Uint8Array(size);
            let offset = 0;
            for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
            const data = JSON.parse(new TextDecoder().decode(bytes));
            const models = provider === 'openai' ? data.data : data.models;
            if (provider === 'github') return {provider, status: 200, ok: Array.isArray(data), releases_returned: Array.isArray(data) ? data.length : 0};
            return {provider, status: 200, ok: Array.isArray(models), models_returned: Array.isArray(models) ? models.length : 0,
                ...(provider === 'ollama' ? {configured_model_available: Array.isArray(models) && models.some(model => model.name === config.AI_AGENT_MODEL || model.model === config.AI_AGENT_MODEL)} : {})};
        } catch { return {provider, ok: false, error: 'Metadata inspection failed; private details suppressed'}; }
        finally { clearTimeout(timer); }
    }));
    return {key_fingerprints: Object.fromEntries(keys.map(key => [key, createHash('sha256').update(config[key]).digest('hex')])),
        agent_provider: config.AI_AGENT_PROVIDER, agent_model: config.AI_AGENT_MODEL, transcription_provider: config.AI_TRANSCRIPTION_PROVIDER,
        generation_or_transcription_requested: false, checks: results};
}
