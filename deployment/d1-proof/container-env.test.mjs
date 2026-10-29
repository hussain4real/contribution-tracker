import assert from 'node:assert/strict';
import test from 'node:test';
import {containerEnvironment} from './container-env.mjs';

test('existing single-part staging configuration remains compatible', () => {
    assert.deepEqual(containerEnvironment({CONTAINER_ENV: JSON.stringify({APP_ENV: 'd1-staging'})}), {APP_ENV: 'd1-staging'});
});

test('bounded Worker secret parts reconstruct a larger OAuth environment without forwarding Worker-only secrets', () => {
    const config = {APP_ENV: 'production', PASSPORT_PRIVATE_KEY: 'generated-fixture-key\n'.repeat(230), PASSPORT_PUBLIC_KEY: 'generated-public-key'};
    const json = JSON.stringify(config);
    assert.ok(json.length > 5120);
    const bindings = {CONTAINER_ENV_PARTS: String(Math.ceil(json.length / 4000)), SQL_GATEWAY_TOKEN: 'must-stay-in-worker', BACKUP_KEY: 'must-stay-in-maintenance'};
    for (let index = 0; index < Number(bindings.CONTAINER_ENV_PARTS); index++) bindings['CONTAINER_ENV_' + index] = json.slice(index * 4000, (index + 1) * 4000);
    assert.deepEqual(containerEnvironment(bindings), config);
    delete bindings.CONTAINER_ENV_1;
    assert.throws(() => containerEnvironment(bindings));
});

test('malformed, missing and oversized environment parts fail closed', () => {
    for (const bindings of [
        {}, {CONTAINER_ENV: '[]'}, {CONTAINER_ENV: 'null'}, {CONTAINER_ENV: '{"APP_DEBUG":false}'},
        {CONTAINER_ENV_PARTS: '01', CONTAINER_ENV_0: '{}'}, {CONTAINER_ENV_PARTS: '17'},
        {CONTAINER_ENV_PARTS: '1', CONTAINER_ENV_0: 'x'.repeat(4097)},
        {CONTAINER_ENV: JSON.stringify({APP_KEY: 'x'.repeat(16001)})},
        {CONTAINER_ENV: JSON.stringify({'bad-name': 'value'})}, {CONTAINER_ENV: JSON.stringify({APP_NAME: 'invalid\0value'})},
    ]) assert.throws(() => containerEnvironment(bindings));
});

test('approved production integrations cannot introduce unrelated credentials or override the core environment', () => {
    const base = {APP_ENV: 'production', D1_PRODUCTION_ENABLED: 'true'};
    const config = extra => ({CONTAINER_ENV: JSON.stringify(base), APPLICATION_INTEGRATION_ENV: JSON.stringify(extra)});
    assert.equal(containerEnvironment(config({OLLAMA_API_KEY: 'fixture', AI_AGENT_PROVIDER: 'ollama'})).OLLAMA_API_KEY, 'fixture');
    for (const extra of [{BACKUP_KEY: 'forbidden'}, {APP_KEY: 'forbidden'}, {SQL_GATEWAY_TOKEN: 'forbidden'}, {OPENAI_API_KEY: false}]) assert.throws(() => containerEnvironment(config(extra)));
    assert.throws(() => containerEnvironment({...config({}), CONTAINER_ENV: JSON.stringify({...base, APP_ENV: 'd1-staging'})}));
    assert.throws(() => containerEnvironment({...config({OLLAMA_API_KEY: 'new'}), CONTAINER_ENV: JSON.stringify({...base, OLLAMA_API_KEY: 'existing'})}));
});

test('environment-specific AI secrets replace the provider without changing other integrations', () => {
    for (const environment of ['d1-staging', 'production']) {
        const base = {APP_ENV: environment, APP_KEY: 'existing-app-key', OPENAI_API_KEY: 'previous-key',
            AI_AGENT_PROVIDER: 'ollama', AI_AGENT_MODEL: 'previous-model', GITHUB_RELEASES_TOKEN: 'existing-github-key'};
        const ai = {OPENAI_API_KEY: environment + '-openai-key', OPENAI_URL: 'https://api.openai.com/v1',
            AI_AGENT_PROVIDER: 'openai', AI_AGENT_MODEL: 'gpt-6.1-terra', AI_TRANSCRIPTION_PROVIDER: 'openai'};
        const result = containerEnvironment({CONTAINER_ENV: JSON.stringify(base), AI_ENV: JSON.stringify(ai), SQL_GATEWAY_TOKEN: 'worker-only'});
        assert.deepEqual(result, {...base, ...ai});
        assert.equal(Object.hasOwn(result, 'SQL_GATEWAY_TOKEN'), false);
    }
    const production = {APP_ENV: 'production', D1_PRODUCTION_ENABLED: 'true'};
    assert.equal(containerEnvironment({CONTAINER_ENV: JSON.stringify(production),
        APPLICATION_INTEGRATION_ENV: JSON.stringify({OPENAI_API_KEY: 'previous-key'}),
        AI_ENV: JSON.stringify({OPENAI_API_KEY: 'new-key'})}).OPENAI_API_KEY, 'new-key');
});

test('AI secrets reject malformed values and changes to unrelated application configuration', () => {
    for (const value of [false, 'x'.repeat(4097), '[]', 'null', '{',
        ...[{APP_KEY: 'forbidden'}, {SQL_GATEWAY_TOKEN: 'forbidden'}, {GITHUB_RELEASES_TOKEN: 'forbidden'},
            {OPENAI_API_KEY: false}, {OPENAI_API_KEY: 'invalid\0value'}].map(JSON.stringify)]) {
        assert.throws(() => containerEnvironment({CONTAINER_ENV: '{"APP_ENV":"production"}', AI_ENV: value}));
    }
});

test('the real authentication origin is available only for the isolated read-only production mirror', () => {
    const config = {APP_ENV: 'production', D1_PRODUCTION_ENABLED: 'true', D1_MIGRATION_CHECKS: 'true', APP_URL: 'https://protected-preview'};
    const bindings = {CONTAINER_ENV: JSON.stringify(config), MIGRATION_AUTH_ORIGIN: 'https://familyfunds.app'};
    assert.equal(containerEnvironment(bindings).APP_URL, 'https://familyfunds.app');
    assert.equal(containerEnvironment(bindings).PASSKEYS_ALLOWED_ORIGINS, 'https://familyfunds.app');
    for (const extra of [{APP_ENV: 'd1-staging'}, {D1_MIGRATION_CHECKS: 'false'}, {D1_PRODUCTION_ENABLED: 'false'}]) {
        assert.throws(() => containerEnvironment({...bindings, CONTAINER_ENV: JSON.stringify({...config, ...extra})}));
    }
    assert.throws(() => containerEnvironment({...bindings, MIGRATION_AUTH_ORIGIN: 'https://other.example'}));
});

test('cutover stages preserve credentials and separate inspection from live delivery', () => {
    const base = {APP_ENV: 'production', D1_PRODUCTION_ENABLED: 'true', D1_STAGING_ENABLED: 'false',
        D1_DATABASE: 'familyfunds-production', APP_KEY: 'fixture-key', MAIL_MAILER: 'array'};
    const bindings = {CONTAINER_ENV: JSON.stringify(base), PRODUCTION_CUTOVER_STAGE: 'read-only'};
    const inspection = containerEnvironment(bindings);
    assert.equal(inspection.APP_KEY, base.APP_KEY);
    assert.equal(inspection.APP_URL, 'https://familyfunds.app');
    assert.equal(inspection.D1_MIGRATION_CHECKS, 'true');
    assert.equal(inspection.D1_PRODUCTION_DELIVERY_ENABLED, 'false');
    const live = containerEnvironment({...bindings, PRODUCTION_CUTOVER_STAGE: 'live'});
    assert.equal(live.D1_MIGRATION_CHECKS, 'false');
    assert.equal(live.D1_PRODUCTION_DELIVERY_ENABLED, 'true');
    assert.equal(live.D1_PRODUCTION_WEBHOOKS_ENABLED, 'true');
    assert.equal(live.QUEUE_WORKER_ENABLED, 'false');
    assert.equal(live.D1_READ_ONLY_PROVIDER_CHECKS, 'false');
    assert.equal(live.MAIL_MAILER, 'cloudflare');
    for (const extra of [{PRODUCTION_CUTOVER_STAGE: 'unknown'}, {MIGRATION_AUTH_ORIGIN: 'https://familyfunds.app'},
        {CONTAINER_ENV: JSON.stringify({...base, D1_DATABASE: 'other'})}]) {
        assert.throws(() => containerEnvironment({...bindings, ...extra}));
    }
});
