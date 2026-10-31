import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {test} from 'node:test';
import runner from './staging-checkpoint-runner.mjs';

function fixture() {
    const rows = [11,13].map(id => ({id, uuid:`00000000-0000-4000-8000-${String(id).padStart(12,'0')}`,
        family_id:43, requested_by:68, type:'fund_statement', format:'csv', disk:'local', path:`reports/${id}.csv`}));
    const artifacts = rows.map(row => ({id:row.id, uuid:row.uuid, path_sha256:createHash('sha256').update(row.path).digest('hex')}));
    const objects = new Map();
    let backups = 0, restores = 0, version = 0;
    const env = {
        RUN_ENABLED:'true', RUN_MODE:'exploratory', DATABASE_NAME:'familyfunds-d1-web-staging', RECOVERY_DATABASE_NAME:'familyfunds-d1-staging-recovery',
        STATUS_BUCKET_NAME:'familyfunds-d1-staging-checkpoints', CHECKPOINT_ID:'cutover-20261031t090000z', EXPECTED_ARTIFACTS:JSON.stringify(artifacts),
        DB:{prepare:() => ({all:async () => ({results:rows})})},
        STATUS_FILES:{
            put:async (key, body, options) => {
                const current = objects.get(key);
                if (options.onlyIf?.etagDoesNotMatch === '*' && current) return null;
                if (options.onlyIf?.etagMatches && current?.etag !== options.onlyIf.etagMatches) return null;
                const entry = {body,etag:'etag-'+(++version)};
                objects.set(key,entry);
                return {etag:entry.etag};
            },
            get:async key => {const item=objects.get(key);return item ? {json:async () => JSON.parse(item.body)} : null;},
        },
        BACKUP:{create:async () => {backups++;return {status:'created',tables:40,files:2,missing_file_ids:[11,13],sha256:'a'.repeat(64)};}},
        RECOVERY:{restore:async () => {restores++;return {status:'passed',tables:40,files:2,missing_file_ids:[11,13],integrity:'ok'};}},
    };
    return {env,rows,objects,counts:() => ({backups,restores})};
}

test('runner captures and restores once, including concurrent scheduled events', async () => {
    const {env,objects,counts} = fixture();
    const pending = await runner.fetch(new Request('https://example.test/status'),env);
    assert.equal(await pending.text(),'PENDING');
    await Promise.all([runner.scheduled({},env),runner.scheduled({},env)]);
    assert.deepEqual(counts(),{backups:1,restores:1});
    const report = JSON.parse([...objects.values()][0].body);
    assert.equal(report.state,'passed');
    assert.equal(report.consistency,'not-frozen');
    assert.deepEqual(report.missing_file_ids,[11,13]);
    assert.equal(await (await runner.fetch(new Request('https://example.test/status'),env)).text(),'EXPLORATORY_RESTORE_PASSED');
    assert.equal((await runner.fetch(new Request('https://example.test/status',{method:'POST'}),env)).status,404);
});

test('runner refuses changed artifacts without creating a checkpoint', async () => {
    const {env,rows,counts} = fixture();
    rows[0].path = 'reports/changed.csv';
    await runner.scheduled({},env);
    assert.deepEqual(counts(),{backups:0,restores:0});
    assert.equal(await (await runner.fetch(new Request('https://example.test/status'),env)).text(),'FAILED');
    await runner.scheduled({},env);
    assert.deepEqual(counts(),{backups:0,restores:0});
});

test('runner refuses mismatched target settings before claiming an attempt', async () => {
    const {env,objects} = fixture();
    env.DATABASE_NAME = 'familyfunds-production';
    await assert.rejects(runner.scheduled({},env),/disabled or misconfigured/);
    assert.equal(objects.size,0);
    assert.equal((await runner.fetch(new Request('https://example.test/status'),env)).status,503);
});

test('runner reports failure if recovery verification is incomplete', async () => {
    const {env,counts} = fixture();
    env.RECOVERY.restore = async () => ({status:'passed',tables:40,files:2,missing_file_ids:[11,13],integrity:'unknown'});
    await runner.scheduled({},env);
    assert.equal(counts().backups,1);
    assert.equal(await (await runner.fetch(new Request('https://example.test/status'),env)).text(),'FAILED');
});

test('runner does not restore an incomplete checkpoint', async () => {
    const {env,counts} = fixture();
    env.BACKUP.create = async () => ({status:'created',tables:40,files:2,missing_file_ids:[11],sha256:'a'.repeat(64)});
    await runner.scheduled({},env);
    assert.equal(counts().restores,0);
    assert.equal(await (await runner.fetch(new Request('https://example.test/status'),env)).text(),'FAILED');
});

test('runner preserves a competing status object', async () => {
    const {env,objects} = fixture();
    env.RECOVERY.restore = async () => {
        const entry = [...objects.values()][0];
        entry.etag = 'competing-etag';
        entry.body = JSON.stringify({state:'external'});
        return {status:'passed',tables:40,files:2,missing_file_ids:[11,13],integrity:'ok'};
    };
    await assert.rejects(runner.scheduled({},env),/report changed/);
    assert.equal(JSON.parse([...objects.values()][0].body).state,'external');
});
