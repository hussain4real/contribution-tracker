import assert from 'node:assert/strict';
import test from 'node:test';
import {exportD1Continuously} from './production-d1-export.mjs';

const target = {accountId: 'a'.repeat(32), databaseId: '11111111-1111-1111-1111-111111111111'};
const active = {success: true, type: 'export', status: 'active', at_bookmark: 'snapshot-1'};
const complete = {...active, status: 'complete', result: {filename: 'snapshot.sql', signed_url: 'https://test.r2.cloudflarestorage.com/snapshot.sql?signature=private-fixture'}};
const response = result => ({success: true, status: 200, result});

test('an export is continuously polled in one invocation with its original bookmark until the download exists', async () => {
    const calls = [];
    const replies = [active, active, complete];
    const result = await exportD1Continuously(async request => {
        calls.push(request);
        return response(replies.shift());
    }, target);
    assert.equal(calls.length, 3);
    assert.deepEqual(calls.map(r => r.body), [{output_format: 'polling'},
        {output_format: 'polling', current_bookmark: 'snapshot-1'}, {output_format: 'polling', current_bookmark: 'snapshot-1'}]);
    assert.equal(result.completion.result.signed_url, complete.result.signed_url);
    assert.equal(result.trace.length, 3);
    assert.equal(JSON.stringify(result.trace).includes('private-fixture'), false);
});

test('an immediately completed export is not polled again', async () => {
    let calls = 0;
    await exportD1Continuously(async () => { calls++; return response(complete); }, target);
    assert.equal(calls, 1);
});

test('HTTP success with a cancelled inner result fails without silently starting a replacement export', async () => {
    let calls = 0;
    await assert.rejects(exportD1Continuously(async () => response(++calls === 1 ? active
        : {success: false, error: 'Not currently exporting anything.'}), target), error => {
        assert.match(error.message, /cancelled/);
        assert.equal(error.trace.length, 2);
        return true;
    });
    assert.equal(calls, 2);
});

test('the export rejects changed bookmarks, failed states and missing download results', async () => {
    for (const invalid of [{...complete, at_bookmark: 'another-snapshot'}, {...active, status: 'error'}, {...complete, result: {}}]) {
        const replies = [active, invalid];
        await assert.rejects(exportD1Continuously(async () => response(replies.shift()), target));
    }
});

test('a continuous export is bounded and does not leak transport details', async () => {
    let time = 0;
    await assert.rejects(exportD1Continuously(async () => { time += 10; return response(active); },
        {...target, timeoutMs: 20, now: () => time}), /deadline/);
    await assert.rejects(exportD1Continuously(async () => { throw new Error('private transport fixture'); }, target),
        error => error.message === 'D1 export transport failed' && !JSON.stringify(error).includes('private transport fixture'));
});
