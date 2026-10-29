import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {getProductionContainer, PRODUCTION_CONTAINER_NAME} from './production-container-target.mjs';

test('web requests, diagnostics, webhooks and scheduled work share the European runtime identity', () => {
    let target;
    const stub = {};
    assert.equal(getProductionContainer({PRODUCTION_CONTAINER: {getByName(name, options) {target = {name, options}; return stub;}}}), stub);
    assert.deepEqual(target, {name: PRODUCTION_CONTAINER_NAME, options: {locationHint: 'weur'}});
    for (const name of ['production-worker.mjs', 'production-candidate-proxy.mjs', 'production-webhooks.mjs', 'production-maintenance-worker.mjs']) {
        const source = readFileSync(new URL('./' + name, import.meta.url), 'utf8');
        assert.ok(source.includes('getProductionContainer(env)'));
        assert.ok(!source.includes('PRODUCTION_CONTAINER.getByName('));
    }
});

test('the identity-transfer upload includes the complete managed module graph', () => {
    const source = readFileSync(new URL('./production-direct-identity-transfer.py', import.meta.url), 'utf8');
    const list = source.match(/MODULES = \[([\s\S]*?)\]/)[1];
    const modules = [...list.matchAll(/'([^']+\.mjs)'/g)].map(match => match[1]);
    for (const name of modules) {
        const content = readFileSync(new URL('./' + name, import.meta.url), 'utf8');
        for (const [, dependency] of content.matchAll(/from\s+['"]\.\/([^'"]+)['"]/g)) {
            assert.ok(modules.includes(dependency), `${name} requires ${dependency}`);
        }
    }
});
