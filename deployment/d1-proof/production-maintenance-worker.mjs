import {cleanProductionBackups, createProductionBackup, productionBackupSlot} from "./production-backup.mjs";
import {getProductionContainer} from './production-container-target.mjs';

function enabled(env) {
    if (env.ENABLED !== "true" || env.DATABASE_NAME !== "familyfunds-production") throw new Error("Production maintenance is disabled");
}

export function productionCalendarTasks(at, families, startMonth) {
    if (!/^\d{4}-\d{2}$/.test(startMonth) || startMonth > at.toISOString().slice(0, 7)) throw new Error("Invalid production schedule start month");
    const start = new Date(startMonth + "-01T00:00:00Z");
    if (!Number.isFinite(start.getTime()) || at.getTime() - start.getTime() > 3660 * 86400000) throw new Error("Production calendar catch-up exceeds its audit window");
    const tasks = [];
    for (const row of families) {
        const family = typeof row === 'number' ? row : row.id;
        if (!Number.isSafeInteger(family) || family < 1) throw new Error("Invalid production family");
        for (const month = new Date(start); month <= at; month.setUTCMonth(month.getUTCMonth() + 1)) {
            const period = month.toISOString().slice(0, 7);
            tasks.push({id: `generate:${period}:${family}`, kind: "generate", family_id: family, at: `${period}-01T00:00:00.000Z`});
            const raw = typeof row === 'number' ? null : row.reminder_settings;
            const settings = raw ? JSON.parse(raw) : null;
            const revisions = settings ? [...(Array.isArray(settings.history) ? settings.history : []), settings] : [];
            const configured = revisions.filter(revision => revision && typeof revision.effective_from === 'string' && period >= revision.effective_from)
                .sort((a, b) => b.effective_from.localeCompare(a.effective_from))[0];
            const first = configured ? configured.reminder_day : 25;
            const second = configured ? configured.follow_up_day : 28;
            const time = configured ? configured.time : '08:00';
            if (!Number.isInteger(first) || first < 1 || first > 28 || !Number.isInteger(second) || second <= first || second > 28 || typeof time !== 'string' || !/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) throw new Error('Invalid family reminder schedule');
            for (const [kind, day] of [['remind25', first], ['remind28', second]]) {
                const due = `${period}-${String(day).padStart(2, '0')}T${time}:00.000Z`;
                if (new Date(due) <= at) tasks.push({id: `${kind}:${period}:${family}`, kind, family_id: family, at: due});
            }
        }
    }
    return tasks;
}

async function health(env, name, status, details) {
    await env.DB.prepare("insert into d1_runtime_health(name,status,details,checked_at) values(?,?,?,unixepoch()) on conflict(name) do update set status=excluded.status,details=excluded.details,checked_at=excluded.checked_at").bind(name, status, JSON.stringify(details)).run();
}

async function backupTick(env, at) {
    if (env.BACKUPS_ENABLED !== "true") return;
    const owner = crypto.randomUUID();
    const lock = await env.DB.prepare("insert into d1_runtime_cache_locks(key,owner,expires_at) values('production-backup-tick',?,unixepoch()+900) on conflict(key) do update set owner=excluded.owner,expires_at=excluded.expires_at where d1_runtime_cache_locks.expires_at<=unixepoch() returning key").bind(owner).all();
    if (lock.results.length === 0) return;
    try {
        const result = await createProductionBackup(env, at);
        await health(env, "backup", "healthy", {slot: productionBackupSlot(at), status: result.status});
        const cleaned = await env.DB.prepare("select details from d1_runtime_health where name='backup-cleanup' and status='healthy'").first();
        if (!cleaned || JSON.parse(cleaned.details).slot !== productionBackupSlot(at)) {
            const result = await cleanProductionBackups(env, at);
            await health(env, "backup-cleanup", "healthy", {slot: productionBackupSlot(at), ...result});
        }
    } catch {
        await health(env, "backup", "error", {slot: productionBackupSlot(at)});
        throw new Error("Production backup or retention failed");
    } finally {
        await env.DB.prepare("delete from d1_runtime_cache_locks where key='production-backup-tick' and owner=?").bind(owner).run();
    }
}

function qatarSlot(at, hour, minute) {
    const local = new Date(at.getTime() + 3 * 3600000), due = new Date(local);
    due.setUTCHours(hour, minute, 0, 0);
    if (due > local) due.setUTCDate(due.getUTCDate() - 1);
    return new Date(due.getTime() - 3 * 3600000);
}

export default {
    async fetch() { return new Response("Not found", {status: 404}); },
    async scheduled(controller, env) {
        enabled(env);
        const at = new Date(controller.scheduledTime);
        if (!Number.isFinite(at.getTime()) || at.getTime() > Date.now() + 5000) throw new Error("Invalid production schedule time");
        const state = await env.DB.prepare("select details from d1_runtime_health where name='family-schedule-cursor'").first();
        const saved = state ? JSON.parse(state.details) : {cursor: 0, month: env.SCHEDULE_START_MONTH};
        const cursor = saved.cursor;
        if (!Number.isSafeInteger(cursor) || cursor < 0) throw new Error("Invalid production family cursor");
        const families = (await env.DB.prepare("select id,reminder_settings from families where id>? order by id limit 50").bind(cursor).all()).results;
        const currentMonth = at.toISOString().slice(0, 7);
        if (typeof saved.month !== "string" || !/^\d{4}-\d{2}$/.test(saved.month) || saved.month > currentMonth) throw new Error("Invalid production calendar checkpoint");
        const nextMonth = new Date(saved.month + "-01T00:00:00Z");
        nextMonth.setUTCMonth(nextMonth.getUTCMonth() + 1);
        const calendarAt = saved.month === currentMonth ? at : new Date(nextMonth.getTime() - 1);
        const tasks = productionCalendarTasks(calendarAt, families, saved.month);
        const purgeAt = new Date(at); purgeAt.setUTCHours(1, 30, 0, 0);
        if (purgeAt > at) purgeAt.setUTCDate(purgeAt.getUTCDate() - 1);
        const purges = (await env.DB.prepare("select id from families where archived_at is not null and legal_hold_at is null and purge_after<=datetime('now') order by id limit 50").all()).results;
        for (const family of purges) tasks.push({id: `purge:${purgeAt.toISOString().slice(0, 10)}:${family.id}`, kind: "purge", family_id: family.id, at: purgeAt.toISOString()});
        const minute = new Date(Math.floor(at.getTime() / 60000) * 60000).toISOString();
        const reportSlot = new Date(Math.floor(at.getTime() / 300000) * 300000).toISOString();
        const reports = (await env.DB.prepare("select distinct family_id from report_schedules where is_active=1 and next_run_at<=datetime('now') order by family_id limit 50").all()).results;
        for (const family of reports) tasks.push({id: `reports:${reportSlot}:${family.family_id}`, kind: "reports", family_id: family.family_id, at: reportSlot});
        const jobs = await env.DB.prepare("select count(*) as count from jobs where available_at<=unixepoch() and (reserved_at is null or reserved_at<=unixepoch()-90)").first();
        if (Number(jobs?.count) > 0) tasks.push({id: `drain:${minute}`, kind: "drain", family_id: 0, at: minute});
        const failed = await env.DB.prepare("select count(*) as count from failed_jobs").first();
        const dead = await env.DB.prepare("select count(*) as count from d1_runtime_dead_letters where resolved_at is null").first();
        await health(env, "jobs", Number(failed?.count) || Number(dead?.count) ? "error" : "healthy", {failed: Number(failed?.count), dead_letters: Number(dead?.count)});
        try { await backupTick(env, at); }
        catch { console.error(JSON.stringify({event: "production_backup_failure"})); }
        if (env.OFFSITE_ENABLED === "true") {
            const due = qatarSlot(at, 3, 30);
            const previous = await env.DB.prepare("select status,details from d1_runtime_health where name='offsite-copy'").first();
            if (!previous || JSON.parse(previous.details).slot !== due.toISOString()) {
                await health(env, "offsite-copy", "pending", {slot: due.toISOString()});
            }
            tasks.push({id: `backup-sync:${due.toISOString().slice(0, 10)}`, kind: "backup-sync", family_id: 0, at: due.toISOString()});
        }
        const monitor = qatarSlot(at, 8, 0);
        tasks.push({id: `monitor:${monitor.toISOString().slice(0, 10)}`, kind: "monitor", family_id: 0, at: monitor.toISOString()});
        for (let offset = 0; offset < tasks.length; offset += 100) {
            await env.DB.batch(tasks.slice(offset, offset + 100).map(task => env.DB.prepare("insert into d1_runtime_schedule_outbox(id,kind,family_id,due_at) values(?,?,?,?) on conflict(id) do nothing").bind(task.id, task.kind, task.family_id, task.at)));
        }
        await health(env, "family-schedule-cursor", "healthy", {cursor: families.length === 50 ? families.at(-1).id : 0,
            month: families.length === 50 || saved.month === currentMonth ? saved.month : nextMonth.toISOString().slice(0, 7)});
        const pending = (await env.DB.prepare("select id from d1_runtime_schedule_outbox where completed_at is null and (dispatched_at is null or dispatched_at<unixepoch()-300) and (lease_until is null or lease_until<=unixepoch()) order by due_at,id limit 50").all()).results;
        for (const task of pending) {
            await env.MAINTENANCE_QUEUE.send({id: task.id}, {contentType: "json"});
            await env.DB.prepare("update d1_runtime_schedule_outbox set dispatched_at=unixepoch() where id=? and completed_at is null").bind(task.id).run();
        }
    },
    async queue(batch, env) {
        enabled(env);
        if (batch.queue === "familyfunds-production-maintenance-dlq") {
            for (const message of batch.messages) {
                const body = JSON.stringify(message.body);
                if (body.length > 8192) throw new Error("Invalid production dead letter");
                await env.DB.prepare("insert into d1_runtime_dead_letters(id,queue,body,created_at) values(?,?,?,unixepoch()) on conflict(id) do nothing").bind(message.id, batch.queue, body).run();
                message.ack();
            }
            return;
        }
        if (batch.queue !== "familyfunds-production-maintenance") throw new Error("Unknown production maintenance queue");
        for (const message of batch.messages) {
            let owner;
            let taskKind;
            const id = message.body?.id;
            try {
                if (typeof id !== "string" || id.length > 200) throw new Error("Invalid production task reference");
                const stored = await env.DB.prepare("select completed_at from d1_runtime_schedule_outbox where id=?").bind(id).first();
                if (!stored) throw new Error("Unknown production task");
                if (stored.completed_at !== null) { message.ack(); continue; }
                owner = crypto.randomUUID();
                const leased = await env.DB.prepare("update d1_runtime_schedule_outbox set lease_token=?,lease_until=unixepoch()+240 where id=? and completed_at is null and (lease_until is null or lease_until<=unixepoch()) returning id,kind,family_id,due_at as at,cursor,lease_token").bind(owner, id).all();
                const task = leased.results[0];
                if (!task) throw new Error("Production task already leased");
                taskKind = task.kind;
                const result = task.kind === "backup-sync"
                    ? await env.OFFSITE_CONTAINER.getByName("familyfunds-production-offsite").copy(task)
                    : await getProductionContainer(env).productionCommand("task", task);
                if (result?.exit_code !== 0 || typeof result.complete !== "boolean" || !Number.isSafeInteger(result.cursor) || result.cursor < task.cursor) throw new Error("Production maintenance result is invalid");
                const updated = await env.DB.prepare("update d1_runtime_schedule_outbox set cursor=?,completed_at=case when ?=1 then unixepoch() else null end,dispatched_at=case when ?=1 then dispatched_at else null end,lease_until=null,lease_token=null where id=? and lease_token=? and lease_until>unixepoch() returning id").bind(result.cursor, result.complete ? 1 : 0, result.complete ? 1 : 0, id, owner).all();
                if (updated.results.length !== 1) throw new Error("Production task lease expired");
                if (!result.complete) await env.MAINTENANCE_QUEUE.send({id}, {contentType: "json"});
                if (task.kind === "backup-sync") await health(env, "offsite-copy", "healthy", {slot: task.at, files: result.files, bytes: result.bytes, encrypted_only: result.encrypted_only, checksum_verified: result.checksum_verified});
                message.ack();
            } catch {
                if (owner) await env.DB.prepare("update d1_runtime_schedule_outbox set lease_until=null,lease_token=null where id=? and lease_token=?").bind(id, owner).run();
                if (taskKind === "backup-sync") await health(env, "offsite-copy", "error", {task_id: id});
                message.retry({delaySeconds: 30});
                console.warn(JSON.stringify({event: "production_maintenance_retry", messageId: message.id}));
            }
        }
    },
};
