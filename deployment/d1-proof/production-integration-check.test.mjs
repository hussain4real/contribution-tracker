import assert from 'node:assert/strict';
import test from 'node:test';
import {inspectIntegrationMetadata} from './production-integration-check.mjs';

const config = {D1_MIGRATION_CHECKS: 'true', OLLAMA_BASE_URL: 'https://ollama.com', OPENAI_URL: 'https://api.openai.com/v1',
    OLLAMA_API_KEY: 'private-ollama', OPENAI_API_KEY: 'private-openai', GEMINI_API_KEY: 'private-gemini', GITHUB_RELEASES_TOKEN: 'private-github',
    AI_AGENT_PROVIDER: 'ollama', AI_AGENT_MODEL: 'gemma4:31b', AI_TRANSCRIPTION_PROVIDER: 'gemini', GITHUB_RELEASES_OWNER: 'owner', GITHUB_RELEASES_REPO: 'repo'};

test('integration inspection uses only four fixed metadata GETs and returns no credentials or response bodies', async () => {
    const calls = [];
    const result = await inspectIntegrationMetadata(config, async (url, options) => {
        calls.push({url, options});
        return Response.json(url.includes('github') ? [{body: 'private release body'}] : url.includes('openai') ? {data: [{id: 'model'}]} : {models: [{name: config.AI_AGENT_MODEL}]});
    });
    assert.equal(calls.length, 4);
    for (const {url, options} of calls) {
        assert.equal(options.method, 'GET'); assert.equal(options.redirect, 'manual');
        assert.ok(!url.includes('private-')); assert.equal(options.signal.aborted, false);
    }
    assert.equal(calls[2].options.headers['x-goog-api-key'], config.GEMINI_API_KEY);
    assert.ok(result.checks.every(check => check.ok));
    assert.equal(result.checks[0].configured_model_available, true);
    assert.ok(!JSON.stringify(result).includes('private-'));
    assert.ok(!JSON.stringify(result).includes('private release body'));
    assert.equal(result.generation_or_transcription_requested, false);
});

test('integration inspection rejects unreviewed hosts and hides upstream errors, redirects and oversized bodies', async () => {
    let calls = 0;
    const send = async () => {calls++;throw new Error('private-credential');};
    await assert.rejects(inspectIntegrationMetadata({...config, OLLAMA_BASE_URL: 'https://other.example'}, send));
    await assert.rejects(inspectIntegrationMetadata({...config, D1_MIGRATION_CHECKS: 'false'}, send));
    assert.equal(calls, 0);
    const failed = await inspectIntegrationMetadata(config, send);
    assert.ok(failed.checks.every(check => !check.ok));
    assert.ok(!JSON.stringify(failed).includes('private-credential'));
    const redirect = await inspectIntegrationMetadata(config, async () => new Response('', {status: 302}));
    assert.ok(redirect.checks.every(check => check.status === 302 && !check.ok));
    const large = await inspectIntegrationMetadata(config, async () => new Response('x'.repeat(1048577)));
    assert.ok(large.checks.every(check => !check.ok));
});
