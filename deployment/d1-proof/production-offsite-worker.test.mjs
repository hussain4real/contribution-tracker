import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {runInNewContext} from 'node:vm';

const source = readFileSync(new URL('./production-offsite-worker.mjs', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '').replace('export class ', 'class ').replace('export default ', 'const handler = ');
const image = 'registry.cloudflare.com/40344948b73fa9da3008958fdc3faf12/familyfunds-production@sha256:ccf5f0f3cc2b0935e7edea5354ab431cc35e57c77f31f46b2ffe8b9c8c960a2c';
const task = () => ({id: 'backup-sync:2026-10-27', kind: 'backup-sync', family_id: 0, at: '2026-10-27T00:30:00.000Z', cursor: 0});
function fixture(fail = false, failure = null) {
    const calls = [], signals = [];
    let destroyed = 0, alarm = null;
    const env = {OFFSITE_ENABLED: 'true', DATABASE_NAME: 'familyfunds-production', CONTAINER_IMAGE: image,
        FF_RCLONE_CONFIG: 'encoded-drive-credential', BACKUP_R2_ACCESS_KEY_ID: 'backup-read-only-key', BACKUP_R2_SECRET_ACCESS_KEY: 'backup-read-only-secret',
        APP_KEY: 'must-never-reach-the-runner', D1_TOKEN: 'must-never-reach-the-runner', BACKUP_KEY: 'must-never-reach-the-runner'};
    const container = {images: {base: image}, running: false,
        start: options => {calls.push({start: options}); container.running = true;},
        destroy: async () => {destroyed++; container.running = false;},
        inspect: async () => ({image}),
        exec: async (command, options) => {calls.push({command, options}); signals.push(options.signal); return {output: async () => ({exitCode: fail && command.at(-1) === '/run/familyfunds-offsite.php' ? 1 : 0,
            stdout: new TextEncoder().encode(JSON.stringify(fail && failure ? failure : {checkpoint: options.env?.BACKUP_CHECKPOINT || null, files: 2, bytes: 1234, encrypted_only: true, checksum_verified: true}))})};},
    };
    const ctx = {container, storage: {setAlarm: async value => {alarm = value;}, deleteAlarm: async () => {alarm = null;}}};
    const loaded = runInNewContext(source + '\n({FamilyFundsOffsiteContainer, handler})', {DurableObject: class {constructor(ctx, env) {this.ctx = ctx; this.env = env;}}, runner: '<?php /* fixed runner */', copier: '<?php /* fixed copier */',
        TextEncoder, TextDecoder, AbortController, setTimeout, clearTimeout, btoa, Response, Date});
    return {runner: new loaded.FamilyFundsOffsiteContainer(ctx, env), handler: loaded.handler, calls, env, signals, destroyed: () => destroyed, alarm: () => alarm};
}

test('isolated copy starts no Laravel services, receives only offsite credentials and always destroys its VM', async () => {
    const f = fixture();
    const result = await f.runner.copy(task());
    assert.equal(result.checksum_verified, true); assert.equal(result.image, image);
    assert.equal(f.calls[0].start.instance, 'lite');
    assert.deepEqual(JSON.parse(JSON.stringify(f.calls[0].start.env)), {});
    assert.ok(!f.calls[0].start.entrypoint.join(' ').includes('artisan'));
    assert.deepEqual(Object.keys(f.calls[2].options.env).sort(), ['BACKUP_CHECKPOINT', 'BACKUP_R2_ACCESS_KEY_ID', 'BACKUP_R2_SECRET_ACCESS_KEY', 'FF_RCLONE_CONFIG']);
    assert.equal(f.destroyed(), 1); assert.equal(f.alarm(), null);
    assert.ok(f.signals.every(signal => !signal.aborted));
    assert.equal((await f.handler.fetch()).status, 404);
});

test('copy failures stop the isolated VM and remain retryable without a success response', async () => {
    const f = fixture(true);
    await assert.rejects(() => f.runner.copy(task()), /copy failed/);
    assert.equal(f.destroyed(), 1); assert.equal(f.alarm(), null);
});

test('private failure diagnostics preserve useful categories and suppress credentials and raw output', async () => {
    const secret = 'synthetic-sensitive-output';
    const f = fixture(true, {diagnostics: {stage: 'drive-copy', error_code: 'transfer', raw: secret,
        process: {exit_code: 9, seconds: 12.5, categories: ['immutable', secret]}}, credential: secret});
    await assert.rejects(() => f.runner.copy(task()), error => {
        assert.ok(error.message.includes('drive-copy'));
        assert.ok(error.message.includes('immutable'));
        assert.ok(error.message.includes('"exit_code":9'));
        assert.ok(!error.message.includes(secret));
        return true;
    });
    assert.equal(f.destroyed(), 1);
});

test('disabled, malformed and concurrent offsite requests never start a VM', async () => {
    const f = fixture();
    f.env.OFFSITE_ENABLED = 'false'; await assert.rejects(() => f.runner.copy(task()), /disabled/);
    f.env.OFFSITE_ENABLED = 'true';
    await assert.rejects(() => f.runner.copy({...task(), family_id: 1}), /Invalid/);
    await assert.rejects(() => f.runner.copy({...task(), checkpoint: '../path'}), /Invalid offsite checkpoint/);
    f.runner.copying = true; await assert.rejects(() => f.runner.copy(task()), /already running/);
    assert.equal(f.calls.length, 0);
});

test('an explicit cutover checkpoint is forwarded to the encrypted-only copier and verified in its result', async () => {
    const f = fixture();
    const checkpoint = 'cutover-20261028t150836z';
    const result = await f.runner.copy({...task(), checkpoint});
    assert.equal(f.calls[2].options.env.BACKUP_CHECKPOINT, checkpoint);
    assert.equal(result.checkpoint, checkpoint);
    assert.equal(result.checksum_verified, true);
});
