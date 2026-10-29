import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import test from 'node:test';

function fixture(overrides = {}) {
    const source = readFileSync(new URL('./production-native-proof.mjs', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '').replace('export class ', 'class ').replace('export default ', 'const handler = ');
    const calls = [];
    const env = {NATIVE_PROOF_ENABLED: 'true', DATABASE_NAME: 'familyfunds-production',
        DB: {prepare: sql => ({first: async () => ({n: sql.includes('from users') ? 1 : 2}), all: async () => ({results: []})})}, ...overrides};
    const Candidate = runInNewContext(source + '\nNativeCandidateProof', {WorkerEntrypoint: class {constructor(env) {this.env = env;}},
        maintenance: {scheduled: async (controller, bindings) => { calls.push({controller, bindings}); }}, Response, Date});
    return {proof: new Candidate(env), calls};
}

test('native administrative proofs reject disabled or non-synthetic targets before work', async () => {
    for (const overrides of [{NATIVE_PROOF_ENABLED: 'false'}, {DATABASE_NAME: 'another-database'},
        {DB: {prepare: () => ({first: async () => ({n: 0})})}}]) {
        const f = fixture(overrides);
        await assert.rejects(f.proof.run('schedule'));
        assert.equal(f.calls.length, 0);
    }
});

test('the isolated calendar probe repeats one past schedule without enabling backup, offsite or recurring triggers', async () => {
    const f = fixture();
    const result = await f.proof.run('schedule');
    assert.equal(f.calls.length, 2);
    for (const call of f.calls) {
        assert.equal(call.controller.scheduledTime, Date.parse('2026-10-01T00:05:00Z'));
        assert.equal(call.bindings.BACKUPS_ENABLED, 'false');
        assert.equal(call.bindings.OFFSITE_ENABLED, 'false');
    }
    assert.equal(result.recurring_cron_enabled, false);
    await assert.rejects(f.proof.run('arbitrary-command'), /Unknown native proof operation/);
});

test('native recovery refuses a different target or an already populated database', async () => {
    const wrong = fixture();
    await assert.rejects(wrong.proof.run('restore'), /Wrong isolated recovery database/);
    const populated = fixture({RECOVERY_NAME: 'familyfunds-production-native-recovery', RECOVERY_DB: {
        prepare: () => ({first: async () => ({n: 1})}), batch: () => assert.fail('A populated database must never be mutated'),
    }});
    await assert.rejects(populated.proof.run('restore'), /must be empty/);
});
