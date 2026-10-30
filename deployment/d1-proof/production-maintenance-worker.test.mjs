import assert from "node:assert/strict";
import test from "node:test";
import handler, {productionCalendarTasks} from "./production-maintenance-worker.mjs";
import {PRODUCTION_CONTAINER_NAME} from './production-container-target.mjs';

test("calendar catch-up covers every family and respects both reminder boundaries", () => {
    const families = [1, 2, 43, 987];
    const early = productionCalendarTasks(new Date("2026-10-25T07:59:59Z"), families, "2026-10");
    assert.equal(early.length, 4);
    assert.equal(productionCalendarTasks(new Date("2026-10-25T08:00:00Z"), families, "2026-10").length, 8);
    const late = productionCalendarTasks(new Date("2026-10-28T08:00:00Z"), families, "2026-09");
    assert.equal(late.length, 24); assert.ok(late.some(task => task.family_id === 987 && task.id === "remind28:2026-09:987"));
    assert.throws(() => productionCalendarTasks(new Date("2026-10-25"), families, "2026-11"));
});

test('configured schedules use UTC and preserve per-period reminder identities', () => {
    const family = {id: 7, reminder_settings: JSON.stringify({reminder_day: 10, follow_up_day: 20, time: '14:35', effective_from: '2026-10'})};
    const before = productionCalendarTasks(new Date('2026-10-10T14:34:59Z'), [family], '2026-10');
    assert.deepEqual(before.map(task => task.kind), ['generate']);
    const at = productionCalendarTasks(new Date('2026-10-20T14:35:00Z'), [family], '2026-10');
    assert.deepEqual(at.map(task => task.id), ['generate:2026-10:7', 'remind25:2026-10:7', 'remind28:2026-10:7']);
    assert.equal(at[1].at, '2026-10-10T14:35:00.000Z');
    const history = productionCalendarTasks(new Date('2026-10-20T14:35:00Z'), [family], '2026-09');
    assert.equal(history.find(task => task.id === 'remind25:2026-09:7').at, '2026-09-25T08:00:00.000Z');
});

test('pending schedule changes keep previously queued months on their original timing', () => {
    const family = {id: 7, reminder_settings: JSON.stringify({reminder_day: 12, follow_up_day: 22, time: '17:00', effective_from: '2026-12',
        history: [{reminder_day: 10, follow_up_day: 20, time: '14:35', effective_from: '2026-11'}]})};
    const tasks = productionCalendarTasks(new Date('2026-12-22T17:00:00Z'), [family], '2026-10');
    assert.equal(tasks.find(task => task.id === 'remind25:2026-10:7').at, '2026-10-25T08:00:00.000Z');
    assert.equal(tasks.find(task => task.id === 'remind25:2026-11:7').at, '2026-11-10T14:35:00.000Z');
    assert.equal(tasks.find(task => task.id === 'remind25:2026-12:7').at, '2026-12-12T17:00:00.000Z');
});

function fixture() {
    const row = {id: "remind25:2026-10:987", kind: "remind25", family_id: 987, at: "2026-10-25T08:00:00.000Z", cursor: 0, completed_at: null, lease_token: null};
    let calls = 0, expired = false;
    const queued = [];
    const env = {
        ENABLED: "true", DATABASE_NAME: "familyfunds-production",
        DB: {prepare: sql => ({bind: (...args) => ({
            first: async () => args[0] === row.id ? {completed_at: row.completed_at} : null,
            all: async () => {
                if (sql.includes("set lease_token=")) {
                    if (row.completed_at !== null || row.lease_token) return {results: []};
                    row.lease_token = args[0];
                    return {results: [{id: row.id, kind: row.kind, family_id: row.family_id, at: row.at, cursor: row.cursor, lease_token: row.lease_token}]};
                }
                if (sql.includes("set cursor=")) {
                    if (expired || row.lease_token !== args[4]) return {results: []};
                    row.cursor = args[0]; row.completed_at = args[1] === 1 ? 1 : null; row.lease_token = null;
                    return {results: [{id: row.id}]};
                }
                throw new Error("Unexpected fixture query");
            },
            run: async () => { if (sql.includes("set lease_until=null") && row.lease_token === args[1]) row.lease_token = null; },
        })})},
        MAINTENANCE_QUEUE: {send: async body => queued.push(body)},
        PRODUCTION_CONTAINER: {getByName: name => ({productionCommand: async (operation, task) => {
            assert.equal(name, PRODUCTION_CONTAINER_NAME); assert.equal(operation, "task"); calls++;
            return {exit_code: 0, complete: true, cursor: task.cursor};
        }})},
    };
    let ack = 0, retry = 0;
    const batch = {queue: "familyfunds-production-maintenance", messages: [{id: "fixture", body: {id: row.id}, ack: () => ack++, retry: () => retry++}]};
    return {env, row, batch, queued, calls: () => calls, ack: () => ack, retry: () => retry, expire: () => { expired = true; }};
}

test("maintenance uses a fenced stored task and acknowledges a completed duplicate without rerunning PHP", async () => {
    const f = fixture();
    await handler.queue(f.batch, f.env); await handler.queue(f.batch, f.env);
    assert.equal(f.calls(), 1); assert.equal(f.ack(), 2); assert.equal(f.retry(), 0); assert.equal(f.row.completed_at, 1);
});

test("a partial reminder cursor is durable before the next queue reference is published", async () => {
    const f = fixture();
    f.env.PRODUCTION_CONTAINER.getByName = () => ({productionCommand: async (_, task) => ({exit_code: 0, complete: task.cursor === 100, cursor: 100})});
    f.env.MAINTENANCE_QUEUE.send = async body => { assert.equal(f.row.cursor, 100); assert.equal(f.row.completed_at, null); f.queued.push(body); };
    await handler.queue(f.batch, f.env);
    assert.equal(f.row.cursor, 100); assert.equal(f.queued.length, 1); assert.equal(f.ack(), 1);
    await handler.queue(f.batch, f.env);
    assert.equal(f.row.completed_at, 1); assert.equal(f.ack(), 2);
});

test("PHP failure keeps the task retryable without marking completion", async () => {
    const f = fixture();
    f.env.PRODUCTION_CONTAINER.getByName = () => ({productionCommand: async () => { throw new Error("interrupted"); }});
    await handler.queue(f.batch, f.env);
    assert.equal(f.row.completed_at, null); assert.equal(f.row.lease_token, null); assert.equal(f.ack(), 0); assert.equal(f.retry(), 1);
});

test("an expired maintenance lease cannot mark work complete or acknowledge the event", async () => {
    const f = fixture();
    f.env.PRODUCTION_CONTAINER.getByName = () => ({productionCommand: async () => { f.expire(); return {exit_code: 0, complete: true, cursor: 0}; }});
    await handler.queue(f.batch, f.env);
    assert.equal(f.row.completed_at, null); assert.equal(f.ack(), 0); assert.equal(f.retry(), 1);
});

test("unknown queue tasks never reach the application and disabled maintenance refuses execution", async () => {
    const f = fixture(); f.batch.messages[0].body.id = "unknown";
    await handler.queue(f.batch, f.env);
    assert.equal(f.calls(), 0); assert.equal(f.ack(), 0); assert.equal(f.retry(), 1);
    f.env.ENABLED = "false";
    await assert.rejects(() => handler.queue(f.batch, f.env));
});

test("the maintenance Worker has no public application endpoint", async () => {
    assert.equal((await handler.fetch(new Request("https://maintenance/"))).status, 404);
});

test('offsite tasks use their separate credential container and completed replays do not repeat the copy', async () => {
    const f = fixture();
    f.row.kind = 'backup-sync'; f.row.family_id = 0;
    let copies = 0;
    f.env.OFFSITE_CONTAINER = {getByName: name => ({copy: async task => {
        assert.equal(name, 'familyfunds-production-offsite'); assert.equal(task.kind, 'backup-sync'); copies++;
        return {exit_code: 0, complete: true, cursor: 0};
    }})};
    await handler.queue(f.batch, f.env); await handler.queue(f.batch, f.env);
    assert.equal(copies, 1); assert.equal(f.calls(), 0); assert.equal(f.ack(), 2); assert.equal(f.retry(), 0);
});

async function nativeSqlFixture() {
    const {DatabaseSync} = await import('node:sqlite');
    const sqlite = new DatabaseSync(':memory:');
    sqlite.exec(`
        create table families(id integer primary key, archived_at text,legal_hold_at text,purge_after text,reminder_settings text);
        create table report_schedules(id integer primary key,family_id integer,is_active integer,next_run_at text);
        create table jobs(id integer primary key,available_at integer,reserved_at integer);
        create table failed_jobs(id integer primary key);
        create table d1_runtime_dead_letters(id text primary key,resolved_at integer);
        create table d1_runtime_health(name text primary key,status text,details text,checked_at integer);
        create table d1_runtime_schedule_outbox(id text primary key,kind text,family_id integer,due_at text,cursor integer default 0,dispatched_at integer,lease_token text,lease_until integer,completed_at integer);
    `);
    const queued = [];
    const wrap = (sql, params = []) => ({
        bind: (...values) => wrap(sql, values),
        all: async () => ({results: sqlite.prepare(sql).all(...params)}),
        first: async () => sqlite.prepare(sql).get(...params) ?? null,
        run: async () => sqlite.prepare(sql).run(...params),
    });
    const env = {ENABLED: 'true', DATABASE_NAME: 'familyfunds-production', SCHEDULE_START_MONTH: '2026-10',
        DB: {prepare: sql => wrap(sql), batch: async statements => {
            sqlite.exec('begin');
            try { const result = []; for (const statement of statements) result.push(await statement.run()); sqlite.exec('commit'); return result; }
            catch (error) { sqlite.exec('rollback'); throw error; }
        }},
        MAINTENANCE_QUEUE: {send: async body => queued.push(body)},
        PRODUCTION_CONTAINER: {getByName: () => { throw new Error('Cron must not start PHP directly'); }},
    };
    return {sqlite, env, queued, controller: {scheduledTime: new Date('2026-10-26T12:00:00Z').getTime()}};
}

test('the real scheduled SQL catches up more than fifty families without waking PHP or duplicating tasks', async () => {
    const f = await nativeSqlFixture();
    try {
        const insert = f.sqlite.prepare('insert into families(id) values(?)');
        for (let id = 1; id <= 125; id++) insert.run(id);
        for (let tick = 0; tick < 4; tick++) await handler.scheduled(f.controller, f.env);
        assert.equal(f.sqlite.prepare("select count(*) as n from d1_runtime_schedule_outbox where kind='generate'").get().n, 125);
        assert.equal(f.sqlite.prepare("select count(*) as n from d1_runtime_schedule_outbox where kind='remind25'").get().n, 125);
        assert.equal(f.sqlite.prepare("select count(*) as n from d1_runtime_schedule_outbox where kind in ('remind28','drain','reports','purge')").get().n, 0);
        assert.equal(f.sqlite.prepare('select count(*) as n from d1_runtime_schedule_outbox').get().n, 251);
        assert.equal(new Set(f.queued.map(item => item.id)).size, f.queued.length);
        assert.ok(f.queued.every(item => Object.keys(item).join(',') === 'id'));
    } finally { f.sqlite.close(); }
});

test('a failed scheduled queue send retains its stored outbox row and later recovers it', async () => {
    const f = await nativeSqlFixture();
    try {
        f.sqlite.exec('insert into families(id) values(987)');
        f.env.MAINTENANCE_QUEUE.send = async () => { throw new Error('queue outage'); };
        await assert.rejects(() => handler.scheduled(f.controller, f.env));
        assert.equal(f.sqlite.prepare('select count(*) as n from d1_runtime_schedule_outbox where dispatched_at is null').get().n, 3);
        f.env.MAINTENANCE_QUEUE.send = async body => f.queued.push(body);
        await handler.scheduled(f.controller, f.env);
        assert.equal(f.queued.length, 3);
        f.sqlite.exec('update d1_runtime_schedule_outbox set dispatched_at=unixepoch()-301');
        await handler.scheduled(f.controller, f.env);
        assert.equal(f.queued.length, 6);
        assert.equal(f.sqlite.prepare('select count(*) as n from d1_runtime_schedule_outbox').get().n, 3);
    } finally { f.sqlite.close(); }
});

test('scheduled SQL wakes only due reports, eligible purges and available or expired-lease application jobs', async () => {
    const f = await nativeSqlFixture();
    try {
        f.sqlite.exec(`
            insert into families(id,archived_at,legal_hold_at,purge_after) values(1,datetime('now','-1 year'),null,datetime('now','-1 day')),
                (2,datetime('now','-1 year'),datetime('now'),datetime('now','-1 day')),
                (3,datetime('now'),null,datetime('now','+1 day'));
            insert into report_schedules values(1,1,1,datetime('now','-1 hour')),(2,2,0,datetime('now','-1 hour')),(3,3,1,datetime('now','+1 hour'));
            insert into jobs values(1,unixepoch()-1,unixepoch()),(2,unixepoch()+3600,null);
        `);
        await handler.scheduled(f.controller, f.env);
        assert.equal(f.sqlite.prepare("select count(*) as n from d1_runtime_schedule_outbox where kind='drain'").get().n, 0);
        assert.deepEqual(f.sqlite.prepare("select family_id from d1_runtime_schedule_outbox where kind='purge'").all().map(row => row.family_id), [1]);
        assert.deepEqual(f.sqlite.prepare("select family_id from d1_runtime_schedule_outbox where kind='reports'").all().map(row => row.family_id), [1]);
        f.sqlite.exec('update jobs set reserved_at=unixepoch()-91 where id=1; insert into failed_jobs values(1)');
        await handler.scheduled(f.controller, f.env);
        assert.equal(f.sqlite.prepare("select count(*) as n from d1_runtime_schedule_outbox where kind='drain'").get().n, 1);
        assert.equal(f.sqlite.prepare("select status from d1_runtime_health where name='jobs'").get().status, 'error');
    } finally { f.sqlite.close(); }
});

test('an overdue or failed offsite copy cannot retain a healthy monitoring state', async () => {
    const f = await nativeSqlFixture();
    try {
        f.env.OFFSITE_ENABLED = 'true';
        f.sqlite.prepare('insert into d1_runtime_health values(?,?,?,unixepoch()-86400)').run('offsite-copy', 'healthy', JSON.stringify({slot: '2026-10-25T00:30:00.000Z'}));
        await handler.scheduled(f.controller, f.env);
        assert.equal(f.sqlite.prepare("select status from d1_runtime_health where name='offsite-copy'").get().status, 'pending');
        const task = f.sqlite.prepare("select id from d1_runtime_schedule_outbox where kind='backup-sync'").get();
        assert.ok(task);
        let retried = false, acknowledged = false;
        f.env.PRODUCTION_CONTAINER.getByName = () => ({productionCommand: async () => { throw new Error('offsite outage'); }});
        await handler.queue({queue: 'familyfunds-production-maintenance', messages: [{id: 'synthetic-offsite', body: {id: task.id}, ack: () => { acknowledged = true; }, retry: () => { retried = true; }}]}, f.env);
        assert.equal(retried, true); assert.equal(acknowledged, false);
        assert.equal(f.sqlite.prepare("select status from d1_runtime_health where name='offsite-copy'").get().status, 'error');
        assert.equal(f.sqlite.prepare('select completed_at from d1_runtime_schedule_outbox where id=?').get(task.id).completed_at, null);
    } finally { f.sqlite.close(); }
});
