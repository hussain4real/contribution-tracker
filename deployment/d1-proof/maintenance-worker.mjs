import {WorkerEntrypoint} from 'cloudflare:workers';

const DATABASE = "familyfunds-d1-web-staging";
const BACKUP_PREFIX = "private/staging-backups/d1-web/";
const encoder = new TextEncoder();

function target(env) {
    if (env.DATABASE_NAME !== DATABASE || env.ENABLED !== "true") throw new Error("Isolated maintenance is disabled");
    const family = Number(env.MAINTENANCE_FAMILY_ID);
    const purge = Number(env.PURGE_FAMILY_ID);
    if (!Number.isSafeInteger(family) || family < 1 || !Number.isSafeInteger(purge) || purge < 1 || family === purge) throw new Error("Invalid synthetic maintenance families");
    return {family, purge};
}

function calendarTasks(at, families, raw = null) {
    const month = at.toISOString().slice(0, 7);
    const tasks = [{id: `generate:${month}`, kind: "generate", family_id: families.family, at: `${month}-01T00:00:00.000Z`}];
    const settings = raw ? JSON.parse(raw) : null;
    const revisions = settings ? [...(Array.isArray(settings.history) ? settings.history : []), settings] : [];
    const configured = revisions.filter(revision => revision && typeof revision.effective_from === 'string' && month >= revision.effective_from)
        .sort((a, b) => b.effective_from.localeCompare(a.effective_from))[0];
    const first = configured ? configured.reminder_day : 25, second = configured ? configured.follow_up_day : 28;
    const time = configured ? configured.time : '08:00';
    if (!Number.isInteger(first) || first < 1 || first > 28 || !Number.isInteger(second) || second <= first || second > 28 || typeof time !== 'string' || !/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) throw new Error('Invalid family reminder schedule');
    for (const [kind, day] of [['remind25', first], ['remind28', second]]) {
        const due = `${month}-${String(day).padStart(2, '0')}T${time}:00.000Z`;
        if (new Date(due) <= at) tasks.push({id: `${kind}:${month}`, kind, family_id: families.family, at: due});
    }
    const day = new Date(at);
    day.setUTCHours(1, 30, 0, 0);
    if (day > at) day.setUTCDate(day.getUTCDate() - 1);
    tasks.push({id: `purge:${day.toISOString().slice(0, 10)}`, kind: "purge", family_id: families.purge, at: day.toISOString()});
    return tasks;
}

function base64(bytes) {
    let result = "";
    for (let offset = 0; offset < bytes.length; offset += 16384) result += String.fromCharCode(...bytes.subarray(offset, offset + 16384));
    return btoa(result);
}

function backupSlot(at) {
    const local = new Date(at.getTime() + 3 * 3600000);
    const due = new Date(local);
    due.setUTCHours(2, 30, 0, 0);
    if (due > local) due.setUTCDate(due.getUTCDate() - 1);
    return due;
}

async function backupKey(secret) {
    if (typeof secret !== "string" || !secret.startsWith("base64:")) throw new Error("Missing staging backup encryption key");
    const bytes = Uint8Array.from(atob(secret.slice(7)), c => c.charCodeAt(0));
    if (bytes.length !== 32) throw new Error("Invalid staging backup encryption key");
    const source = await crypto.subtle.importKey("raw", bytes, "HKDF", false, ["deriveKey"]);
    return crypto.subtle.deriveKey({name: "HKDF", hash: "SHA-256", salt: encoder.encode(DATABASE), info: encoder.encode("FamilyFunds staging backup v1")}, source, {name: "AES-GCM", length: 256}, false, ["encrypt", "decrypt"]);
}

async function sealBackup(snapshot, secret) {
    const bytes = encoder.encode(JSON.stringify(snapshot));
    if (bytes.length > 20_000_000) throw new Error("Staging backup exceeds size budget");
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const aad = encoder.encode(`FamilyFunds staging backup v1:${DATABASE}`);
    const ciphertext = await crypto.subtle.encrypt({name: "AES-GCM", iv, additionalData: aad}, await backupKey(secret), bytes);
    const hash = await crypto.subtle.digest("SHA-256", bytes);
    return {envelope: {version: 1, database: DATABASE, iv: base64(iv), ciphertext: base64(new Uint8Array(ciphertext))},
        bytes: bytes.length, sha256: Array.from(new Uint8Array(hash), n => n.toString(16).padStart(2, "0")).join("")};
}

async function createBackup(env, at, checkpoint = null) {
    if (checkpoint !== null && (typeof checkpoint !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(checkpoint))) throw new Error('Invalid staging checkpoint');
    const objectKey = BACKUP_PREFIX + (checkpoint === null ? at.toISOString().slice(0, 10) : 'checkpoints/' + checkpoint) + ".json.aes-gcm";
    if (await env.STAGING_FILES.head(objectKey)) {
        if (checkpoint !== null) throw new Error('Staging checkpoint already exists');
        return {status: "already_present", key: objectKey};
    }
    const includedRuntime = checkpoint === null ? "name not glob 'd1_*'" : "(name not glob 'd1_*' or name glob 'd1_staging_*' or name glob 'd1_read_revision_*')";
    const schemaSql = "select type,name,tbl_name,sql from sqlite_master where sql is not null and name not glob 'sqlite_*' and " + includedRuntime + " and name not glob '_cf_*' order by type,name";
    const before = (await env.DB.prepare(schemaSql).all()).results;
    const tables = before.filter(item => item.type === "table").map(item => item.name);
    if (tables.length < 40 || tables.some(name => !/^[a-z_][a-z0-9_]*$/i.test(name))) throw new Error("Unexpected staging backup schema");
    const result = await env.DB.batch([
        ...tables.map(name => env.DB.prepare(`select * from "${name}"`)),
        env.DB.prepare("select name,seq from sqlite_sequence order by name"),
        env.DB.prepare("pragma foreign_key_check"), env.DB.prepare("pragma quick_check"), env.DB.prepare(schemaSql),
    ]);
    if (JSON.stringify(before) !== JSON.stringify(result[tables.length + 3].results)) throw new Error("Schema changed during staging backup");
    if (result[tables.length + 1].results.length !== 0 || result[tables.length + 2].results[0]?.quick_check !== "ok") throw new Error("Staging backup integrity check failed");
    const snapshot = {version: 1, database: DATABASE, captured_at: new Date().toISOString(), schema: before,
        tables: Object.fromEntries(tables.map((name, i) => [name, result[i].results])), sequences: result[tables.length].results};
    const sealed = await sealBackup(snapshot, env.BACKUP_APP_KEY);
    const stored = await env.STAGING_FILES.put(objectKey, JSON.stringify(sealed.envelope), {
        onlyIf: {etagDoesNotMatch: "*"}, httpMetadata: {contentType: "application/octet-stream"},
        customMetadata: {database: DATABASE, plaintext_sha256: sealed.sha256, captured_at: snapshot.captured_at},
    });
    if (!stored && checkpoint !== null) throw new Error('Staging checkpoint already exists');
    return {status: stored ? "created" : "already_present", key: objectKey, plaintext_bytes: sealed.bytes, sha256: sealed.sha256, tables: tables.length};
}

export class StagingBackupOnly extends WorkerEntrypoint {
    async create(checkpoint) {
        if (this.env.STAGING_BACKUP_ONLY_ENABLED !== 'true') throw new Error('Staging backup-only operation is disabled');
        target(this.env);
        if (typeof checkpoint !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(checkpoint)) throw new Error('Invalid staging checkpoint');
        return createBackup(this.env, new Date(), checkpoint);
    }
}

export default {
    async fetch() { return new Response("Not found", {status: 404}); },
    async scheduled(controller, env) {
        const families = target(env);
        const actual = new Date(controller.scheduledTime);
        const at = env.PROOF_AS_OF ? new Date(env.PROOF_AS_OF) : actual;
        if (!Number.isFinite(at.getTime()) || at > actual) throw new Error("Invalid maintenance proof time");
        const backup = await createBackup(env, backupSlot(actual));
        console.log(JSON.stringify({event: "staging_backup", ...backup}));
        const family = await env.DB.prepare('select reminder_settings from families where id=?').bind(families.family).first();
        const tasks = calendarTasks(at, families, family?.reminder_settings ?? null);
        const reports = await env.DB.prepare("select count(*) as due from report_schedules where family_id=43 and name like 'D1-SCHEDULE-UAT-%' and is_active=1 and next_run_at<=datetime('now')").first();
        if (Number(reports?.due) > 0) {
            const minute = new Date(Math.floor(actual.getTime() / 300000) * 300000).toISOString();
            tasks.push({id: `reports:${minute}`, kind: "reports", family_id: 43, at: minute});
        }
        await env.DB.batch(tasks.map(task => env.DB.prepare("insert into d1_staging_schedule_outbox(id,kind,family_id,due_at) values(?,?,?,?) on conflict(id) do nothing").bind(task.id, task.kind, task.family_id, task.at)));
        const pending = (await env.DB.prepare("select id,kind,family_id,due_at as at from d1_staging_schedule_outbox where completed_at is null and (dispatched_at is null or dispatched_at<unixepoch()-300) order by due_at,id limit 20").all()).results;
        for (const task of pending) {
            await env.MAINTENANCE_QUEUE.send(task, {contentType: "json"});
            await env.DB.prepare("update d1_staging_schedule_outbox set dispatched_at=unixepoch() where id=? and completed_at is null").bind(task.id).run();
        }
        console.log(JSON.stringify({event: "staging_schedule_tick", pending: pending.length, php_woken_by_timer: false}));
    },
    async queue(batch, env) {
        const families = target(env);
        for (const message of batch.messages) {
            try {
                const task = message.body;
                const expected = task?.kind === "purge" ? families.purge : task?.kind === "reports" ? 43 : families.family;
                if (!["generate", "remind25", "remind28", "purge", "reports", "retry-notifications"].includes(task?.kind) || task.family_id !== expected) throw new Error("Invalid staging task scope");
                const stored = await env.DB.prepare("select kind,family_id,due_at,completed_at from d1_staging_schedule_outbox where id=?").bind(task.id).first();
                if (!stored || stored.kind !== task.kind || stored.family_id !== task.family_id || stored.due_at !== task.at) throw new Error("Unrecognized staging task");
                if (stored.completed_at === null) {
                    const result = await env.STAGING_CONTAINER.getByName("isolated-staging").scheduledProof(task.kind, task.family_id, task.at);
                    if (result.exit_code !== 0) throw new Error("Staging maintenance command failed");
                    await env.DB.prepare("update d1_staging_schedule_outbox set completed_at=unixepoch() where id=?").bind(task.id).run();
                }
                message.ack();
            } catch (error) {
                message.retry({delaySeconds: 30});
                console.warn(JSON.stringify({event: "staging_schedule_retry", messageId: message.id, reason: String(error).slice(0, 256)}));
            }
        }
    },
};
