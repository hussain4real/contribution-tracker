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

async function openBackup(envelope, secret) {
    if (envelope.version !== 1 || envelope.database !== DATABASE || typeof envelope.iv !== 'string' || typeof envelope.ciphertext !== 'string') throw new Error('Invalid staging checkpoint envelope');
    const decode = value => Uint8Array.from(atob(value), c => c.charCodeAt(0));
    const bytes = await crypto.subtle.decrypt({name: 'AES-GCM', iv: decode(envelope.iv), additionalData: encoder.encode(`FamilyFunds staging backup v1:${DATABASE}`)}, await backupKey(secret), decode(envelope.ciphertext));
    if (bytes.byteLength > 20_000_000) throw new Error('Excessive staging checkpoint');
    return JSON.parse(new TextDecoder().decode(bytes));
}

async function verifyStoredBackup(bucket, key, expected, secret) {
    const stored = await bucket.get(key);
    if (!stored || stored.size > 28_000_000) throw new Error('Missing or excessive stored staging checkpoint');
    const actual = await openBackup(JSON.parse(new TextDecoder().decode(await stored.arrayBuffer())), secret);
    if (JSON.stringify(actual) !== JSON.stringify(expected)) throw new Error('Stored staging checkpoint differs');
}

function checkpointBucket(env) {
    if (env.STAGING_CHECKPOINT_BUCKET_NAME !== 'familyfunds-d1-staging-checkpoints' || !env.STAGING_CHECKPOINT_FILES) throw new Error('Wrong staging checkpoint bucket');
    return env.STAGING_CHECKPOINT_FILES;
}

async function stagingFiles(bucket) {
    const objects = [];
    for (const prefix of ['private/', 'public/']) {
        const cursors = new Set();
        let cursor;
        do {
            const page = await bucket.list({prefix, ...(cursor ? {cursor} : {})});
            objects.push(...page.objects.filter(item => !item.key.startsWith(BACKUP_PREFIX)));
            if (objects.length > 2000 || (page.truncated && (!page.cursor || cursors.has(page.cursor)))) throw new Error('Excessive or incomplete staging file inventory');
            if (page.truncated) cursors.add(page.cursor);
            cursor = page.truncated ? page.cursor : null;
        } while (cursor);
    }
    return objects;
}

async function checkpointFiles(env, snapshot, checkpoint, exceptions) {
    const objects = await stagingFiles(env.STAGING_FILES);
    const paths = new Set(objects.map(item => item.key));
    const missing = [];
    for (const name of ['report_artifacts', 'reconciliation_imports']) {
        for (const row of snapshot.tables[name] ?? []) {
            if (!['local', 'public'].includes(row.disk) || typeof row.path !== 'string' || /(^\/|\.\.|\\)/.test(row.path)) throw new Error('Invalid staging file reference');
            if (!paths.has((row.disk === 'public' ? 'public/' : 'private/') + row.path)) {
                if (name !== 'report_artifacts' || row.disk !== 'local' || !exceptions.some(item => item.id === row.id && item.path === row.path)) throw new Error('A referenced file is missing from staging');
                missing.push({table: name, id: row.id, disk: row.disk, path: row.path});
            }
        }
    }
    if (missing.length !== exceptions.length) throw new Error('Staging missing-file exceptions do not match the inventory');
    if (objects.some(item => item.size > 10_000_000) || objects.reduce((sum, item) => sum + item.size, 0) > 64_000_000) throw new Error('Staging files exceed checkpoint size budget');
    const manifest = [], captured = new Set();
    for (const item of objects) {
        const object = await env.STAGING_FILES.get(item.key, {onlyIf: {etagMatches: item.etag}});
        if (!object || !('body' in object) || object.etag !== item.etag) throw new Error('Staging file changed during checkpoint');
        const bytes = new Uint8Array(await object.arrayBuffer());
        if (bytes.length !== item.size) throw new Error('Staging file size changed during checkpoint');
        const sha256 = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), n => n.toString(16).padStart(2, '0')).join('');
        const backupKey = BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/files/' + sha256 + '.json.aes-gcm';
        if (!captured.has(sha256)) {
            const fileSnapshot = {kind: 'file', sha256, contents: base64(bytes)};
            const sealed = await sealBackup(fileSnapshot, env.BACKUP_APP_KEY);
            if (!await checkpointBucket(env).put(backupKey, JSON.stringify(sealed.envelope), {onlyIf: {etagDoesNotMatch: '*'}, httpMetadata: {contentType: 'application/octet-stream'}})) throw new Error('Staging checkpoint file already exists');
            await verifyStoredBackup(checkpointBucket(env), backupKey, fileSnapshot, env.BACKUP_APP_KEY);
            captured.add(sha256);
        }
        manifest.push({path: item.key, bytes: bytes.length, sha256, backup_key: backupKey,
            http_metadata: object.httpMetadata ?? {}, custom_metadata: object.customMetadata ?? {}});
    }
    const current = await stagingFiles(env.STAGING_FILES);
    const before = JSON.stringify(objects.map(item => [item.key, item.etag, item.size]).sort());
    const after = JSON.stringify(current.map(item => [item.key, item.etag, item.size]).sort());
    if (before !== after) throw new Error('Staging file inventory changed during checkpoint');
    return {manifest, missing};
}

async function createBackup(env, at, checkpoint = null, exceptions = []) {
    if (checkpoint !== null && (typeof checkpoint !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(checkpoint))) throw new Error('Invalid staging checkpoint');
    if (!Array.isArray(exceptions) || exceptions.length > 2 || exceptions.some(item => !item || ![11, 13].includes(item.id) || typeof item.path !== 'string' || !item.path || /(^\/|\.\.|\\)/.test(item.path)) || new Set(exceptions.map(item => item.id)).size !== exceptions.length) throw new Error('Invalid staging missing-file exceptions');
    const destination = checkpoint === null ? env.STAGING_FILES : checkpointBucket(env);
    const objectKey = BACKUP_PREFIX + (checkpoint === null ? at.toISOString().slice(0, 10) : 'checkpoints/' + checkpoint) + ".json.aes-gcm";
    if (await destination.head(objectKey)) {
        if (checkpoint !== null) throw new Error('Staging checkpoint already exists');
        return {status: "already_present", key: objectKey};
    }
    if (checkpoint !== null) {
        const claimKey = BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/claim';
        if (!await destination.put(claimKey, 'staging checkpoint claimed', {onlyIf: {etagDoesNotMatch: '*'}})) throw new Error('Staging checkpoint already exists or was attempted');
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
    if (checkpoint !== null) {
        if (encoder.encode(JSON.stringify(snapshot)).length > 20_000_000) throw new Error('Staging backup exceeds size budget');
        const files = await checkpointFiles(env, snapshot, checkpoint, exceptions);
        snapshot.files = files.manifest;
        snapshot.missing_files = files.missing;
        const after = await env.DB.batch([
            ...tables.map(name => env.DB.prepare(`select * from "${name}"`)),
            env.DB.prepare("select name,seq from sqlite_sequence order by name"), env.DB.prepare(schemaSql),
        ]);
        const canonical = rows => JSON.stringify(rows.map(row => JSON.stringify(Object.keys(row).sort().map(key => [key, row[key]]))).sort());
        if (JSON.stringify(before) !== JSON.stringify(after[tables.length + 1].results)
            || canonical(snapshot.sequences) !== canonical(after[tables.length].results)
            || tables.some((name, i) => canonical(snapshot.tables[name]) !== canonical(after[i].results))) throw new Error('Staging database changed during checkpoint');
    }
    const sealed = await sealBackup(snapshot, env.BACKUP_APP_KEY);
    const stored = await destination.put(objectKey, JSON.stringify(sealed.envelope), {
        onlyIf: {etagDoesNotMatch: "*"}, httpMetadata: {contentType: "application/octet-stream"},
        customMetadata: {database: DATABASE, plaintext_sha256: sealed.sha256, captured_at: snapshot.captured_at},
    });
    if (!stored && checkpoint !== null) throw new Error('Staging checkpoint already exists');
    if (checkpoint !== null) await verifyStoredBackup(destination, objectKey, snapshot, env.BACKUP_APP_KEY);
    return {status: stored ? "created" : "already_present", key: objectKey, plaintext_bytes: sealed.bytes, sha256: sealed.sha256, tables: tables.length, ...(checkpoint === null ? {} : {files: snapshot.files.length, missing_file_ids: snapshot.missing_files.map(item => item.id)})};
}

export class StagingBackupOnly extends WorkerEntrypoint {
    async create(checkpoint, exceptions = []) {
        if (this.env.STAGING_BACKUP_ONLY_ENABLED !== 'true') throw new Error('Staging backup-only operation is disabled');
        target(this.env);
        if (typeof checkpoint !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(checkpoint)) throw new Error('Invalid staging checkpoint');
        return createBackup(this.env, new Date(), checkpoint, exceptions);
    }
}

export class StagingRecoveryOnly extends WorkerEntrypoint {
    async restore(checkpoint) {
        if (this.env.STAGING_RESTORE_ONLY_ENABLED !== 'true') throw new Error('Staging recovery is disabled');
        target(this.env);
        if (this.env.STAGING_RECOVERY_NAME !== 'familyfunds-d1-staging-recovery' || !this.env.RECOVERY_DB || this.env.STAGING_RECOVERY_BUCKET_NAME !== 'familyfunds-d1-staging-recovery-files' || !this.env.RECOVERY_FILES) throw new Error('Wrong staging recovery target');
        if (typeof checkpoint !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(checkpoint)) throw new Error('Invalid staging checkpoint');
        const existing = await this.env.RECOVERY_DB.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*' and name not glob '_cf_*'").first();
        if (existing.n !== 0) throw new Error('Staging recovery target must be empty');
        const existingFiles = await this.env.RECOVERY_FILES.list({limit: 1});
        if (existingFiles.objects.length || existingFiles.truncated) throw new Error('Staging recovery bucket must be empty');
        const object = await checkpointBucket(this.env).get(BACKUP_PREFIX + 'checkpoints/' + checkpoint + '.json.aes-gcm');
        if (!object || object.size > 28_000_000) throw new Error('Missing or excessive staging checkpoint');
        const snapshot = await openBackup(JSON.parse(new TextDecoder().decode(await object.arrayBuffer())), this.env.BACKUP_APP_KEY);
        if (snapshot.version !== 1 || snapshot.database !== DATABASE || !Array.isArray(snapshot.schema) || !Array.isArray(snapshot.sequences) || !Array.isArray(snapshot.files) || !snapshot.tables || typeof snapshot.tables !== 'object' || Array.isArray(snapshot.tables)) throw new Error('Invalid staging checkpoint contents');
        if (snapshot.files.length > 2000) throw new Error('Excessive staging checkpoint files');
        const seenPaths = new Set();
        const readFile = async file => {
            if (typeof file.path !== 'string' || !/^(private|public)\//.test(file.path) || /\.\.|\\/.test(file.path) || file.path.startsWith(BACKUP_PREFIX) || typeof file.sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(file.sha256) || !Number.isSafeInteger(file.bytes) || file.bytes < 0 || file.bytes > 10_000_000 || file.backup_key !== BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/files/' + file.sha256 + '.json.aes-gcm') throw new Error('Invalid staging checkpoint file');
            if (!file.http_metadata || typeof file.http_metadata !== 'object' || Array.isArray(file.http_metadata) || !file.custom_metadata || typeof file.custom_metadata !== 'object' || Array.isArray(file.custom_metadata) || Object.values(file.custom_metadata).some(value => typeof value !== 'string') || Object.entries(file.http_metadata).some(([key, value]) => !['contentType', 'contentLanguage', 'contentDisposition', 'contentEncoding', 'cacheControl', 'cacheExpiry'].includes(key) || typeof value !== 'string' || (key === 'cacheExpiry' && !Number.isFinite(Date.parse(value))))) throw new Error('Invalid staging checkpoint file metadata');
            const stored = await checkpointBucket(this.env).get(file.backup_key);
            if (!stored || stored.size > 28_000_000) throw new Error('Missing or excessive staging checkpoint file');
            const contents = await openBackup(JSON.parse(new TextDecoder().decode(await stored.arrayBuffer())), this.env.BACKUP_APP_KEY);
            if (contents.kind !== 'file' || contents.sha256 !== file.sha256 || typeof contents.contents !== 'string') throw new Error('Invalid staging checkpoint file contents');
            const bytes = Uint8Array.from(atob(contents.contents), c => c.charCodeAt(0));
            const sha256 = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), n => n.toString(16).padStart(2, '0')).join('');
            if (bytes.length !== file.bytes || sha256 !== file.sha256) throw new Error('Staging checkpoint file differs');
            return bytes;
        };
        for (const file of snapshot.files) {
            if (seenPaths.has(file.path)) throw new Error('Duplicate staging checkpoint file');
            seenPaths.add(file.path);
            await readFile(file);
        }
        if (!Array.isArray(snapshot.missing_files) || snapshot.missing_files.length > 2 || new Set(snapshot.missing_files.map(item => item?.id)).size !== snapshot.missing_files.length || snapshot.missing_files.some(item => !item || item.table !== 'report_artifacts' || ![11, 13].includes(item.id) || item.disk !== 'local' || typeof item.path !== 'string' || /(^\/|\.\.|\\)/.test(item.path) || seenPaths.has('private/' + item.path) || !(snapshot.tables.report_artifacts ?? []).some(row => row.id === item.id && row.disk === item.disk && row.path === item.path))) throw new Error('Invalid staging missing-file record');
        for (const name of ['report_artifacts', 'reconciliation_imports']) {
            if (!Array.isArray(snapshot.tables[name] ?? [])) throw new Error('Invalid staging file references');
            for (const row of snapshot.tables[name] ?? []) {
                if (!row || !['local', 'public'].includes(row.disk) || typeof row.path !== 'string' || /(^\/|\.\.|\\)/.test(row.path)) throw new Error('Invalid staging file reference');
                if (!seenPaths.has((row.disk === 'public' ? 'public/' : 'private/') + row.path) && !snapshot.missing_files.some(item => item.table === name && item.id === row.id && item.disk === row.disk && item.path === row.path)) throw new Error('Undocumented missing staging file');
            }
        }
        const names = Object.keys(snapshot.tables);
        const validName = name => typeof name === 'string' && /^[a-z_][a-z0-9_]*$/i.test(name);
        if (names.length < 40 || names.some(name => !validName(name) || !Array.isArray(snapshot.tables[name]))) throw new Error('Invalid staging checkpoint tables');
        const tableSchema = snapshot.schema.filter(item => item.type === 'table');
        if (tableSchema.length !== names.length || tableSchema.some(item => !names.includes(item.name)) || snapshot.schema.some(item => !validName(item.name) || typeof item.sql !== 'string' || !['table', 'index', 'trigger', 'view'].includes(item.type))) throw new Error('Invalid staging checkpoint schema');
        const statements = [this.env.RECOVERY_DB.prepare('pragma defer_foreign_keys=on')];
        for (const item of tableSchema) statements.push(this.env.RECOVERY_DB.prepare(item.sql));
        for (const name of names) {
            const rows = snapshot.tables[name];
            if (!rows.length) continue;
            const columns = Object.keys(rows[0]);
            if (!columns.length || columns.some(column => !validName(column)) || rows.some(row => !row || typeof row !== 'object' || Array.isArray(row) || Object.keys(row).length !== columns.length || columns.some(column => !Object.hasOwn(row, column) || !(row[column] === null || typeof row[column] === 'string' || (typeof row[column] === 'number' && Number.isFinite(row[column]) && (!Number.isInteger(row[column]) || Number.isSafeInteger(row[column]))))))) throw new Error('Unsupported staging checkpoint row');
            const sql = `insert into "${name}"(${columns.map(column => `"${column}"`).join(',')}) select ${columns.map((_column, index) => `json_extract(value,'$[${index}]')`).join(',')} from json_each(?)`;
            for (let i = 0; i < rows.length; i += 50) statements.push(this.env.RECOVERY_DB.prepare(sql).bind(JSON.stringify(rows.slice(i, i + 50).map(row => columns.map(column => row[column])))));
        }
        for (const row of snapshot.sequences) {
            if (!row || !validName(row.name) || !Number.isSafeInteger(row.seq) || row.seq < 0) throw new Error('Invalid staging checkpoint sequence');
            statements.push(this.env.RECOVERY_DB.prepare('delete from sqlite_sequence where name=?').bind(row.name), this.env.RECOVERY_DB.prepare('insert into sqlite_sequence(name,seq) values(?,?)').bind(row.name, row.seq));
        }
        for (const item of snapshot.schema.filter(item => item.type !== 'table')) statements.push(this.env.RECOVERY_DB.prepare(item.sql));
        statements.push(this.env.RECOVERY_DB.prepare('pragma foreign_key_check'), this.env.RECOVERY_DB.prepare('pragma quick_check'));
        if (statements.length > 900) throw new Error('Staging restore exceeds one atomic batch');
        const outcome = await this.env.RECOVERY_DB.batch(statements);
        if (outcome.at(-2).results.length || outcome.at(-1).results[0]?.quick_check !== 'ok') throw new Error('Staging restore integrity failed');
        const canonical = rows => JSON.stringify(rows.map(row => JSON.stringify(Object.keys(row).sort().map(key => [key, row[key]]))).sort());
        for (const name of names) {
            const rows = (await this.env.RECOVERY_DB.prepare(`select * from "${name}"`).all()).results;
            if (canonical(rows) !== canonical(snapshot.tables[name])) throw new Error('Staging restored table differs: ' + name);
        }
        const sequences = (await this.env.RECOVERY_DB.prepare('select name,seq from sqlite_sequence order by name').all()).results;
        if (canonical(sequences) !== canonical(snapshot.sequences)) throw new Error('Staging restored sequences differ');
        const schema = (await this.env.RECOVERY_DB.prepare("select type,name,tbl_name,sql from sqlite_master where sql is not null and name not glob 'sqlite_*' and (name not glob 'd1_*' or name glob 'd1_staging_*' or name glob 'd1_read_revision_*') and name not glob '_cf_*' order by type,name").all()).results;
        if (canonical(schema) !== canonical(snapshot.schema)) throw new Error('Staging restored schema differs');
        for (const file of snapshot.files) {
            const bytes = await readFile(file);
            const httpMetadata = {...file.http_metadata};
            if (httpMetadata.cacheExpiry) httpMetadata.cacheExpiry = new Date(httpMetadata.cacheExpiry);
            if (!await this.env.RECOVERY_FILES.put(file.path, bytes, {onlyIf: {etagDoesNotMatch: '*'}, httpMetadata, customMetadata: file.custom_metadata})) throw new Error('Staging recovery file already exists');
            const restored = await this.env.RECOVERY_FILES.get(file.path);
            if (!restored || restored.size !== file.bytes) throw new Error('Staging recovery file differs');
            const hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await restored.arrayBuffer())), n => n.toString(16).padStart(2, '0')).join('');
            if (hash !== file.sha256) throw new Error('Staging recovery file differs');
            const ordered = value => JSON.stringify(Object.entries(value).filter(([, item]) => item !== undefined).sort(([a], [b]) => a.localeCompare(b)));
            if (ordered(restored.httpMetadata ?? {}) !== ordered(file.http_metadata) || ordered(restored.customMetadata ?? {}) !== ordered(file.custom_metadata)) throw new Error('Staging recovery file metadata differs');
        }
        return {status: 'passed', tables: names.length, sequences: sequences.length, files: snapshot.files.length, missing_file_ids: snapshot.missing_files.map(item => item.id), integrity: 'ok'};
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
