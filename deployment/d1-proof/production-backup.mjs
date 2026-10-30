const DATABASE = "familyfunds-production";
export const BACKUP_PREFIX = "private/production-backups/";
const encoder = new TextEncoder();
const DAY = 86_400_000;

function base64(bytes) {
    let text = "";
    for (let i = 0; i < bytes.length; i += 16384) text += String.fromCharCode(...bytes.subarray(i, i + 16384));
    return btoa(text);
}

async function digest(bytes) {
    return Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)), n => n.toString(16).padStart(2, "0")).join("");
}

async function key(secret) {
    if (typeof secret !== "string" || !secret.startsWith("base64:")) throw new Error("Missing production backup key");
    const bytes = Uint8Array.from(atob(secret.slice(7)), char => char.charCodeAt(0));
    if (bytes.length !== 32) throw new Error("Invalid production backup key");
    const source = await crypto.subtle.importKey("raw", bytes, "HKDF", false, ["deriveKey"]);
    return crypto.subtle.deriveKey({name: "HKDF", hash: "SHA-256", salt: encoder.encode(DATABASE), info: encoder.encode("FamilyFunds production backup v1")}, source, {name: "AES-GCM", length: 256}, false, ["encrypt", "decrypt"]);
}

export async function sealProductionBackup(bytes, secret, kind) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ciphertext = await crypto.subtle.encrypt({name: "AES-GCM", iv, additionalData: encoder.encode(`FamilyFunds production backup v1:${DATABASE}:${kind}`)}, await key(secret), bytes);
    return {version: 1, database: DATABASE, kind, iv: base64(iv), ciphertext: base64(new Uint8Array(ciphertext)), sha256: await digest(bytes)};
}

export async function openProductionBackup(envelope, secret) {
    if (envelope?.version !== 1 || envelope.database !== DATABASE || !/^(database|file:[a-f0-9]{64})$/.test(envelope.kind)) throw new Error("Wrong production backup identity");
    const iv = Uint8Array.from(atob(envelope.iv), char => char.charCodeAt(0));
    const ciphertext = Uint8Array.from(atob(envelope.ciphertext), char => char.charCodeAt(0));
    const bytes = new Uint8Array(await crypto.subtle.decrypt({name: "AES-GCM", iv, additionalData: encoder.encode(`FamilyFunds production backup v1:${DATABASE}:${envelope.kind}`)}, await key(secret), ciphertext));
    if (await digest(bytes) !== envelope.sha256) throw new Error("Production backup checksum mismatch");
    return bytes;
}

export function productionBackupSlot(at) {
    const local = new Date(at.getTime() + 3 * 3600000);
    const due = new Date(local);
    due.setUTCHours(2, 30, 0, 0);
    if (due > local) due.setUTCDate(due.getUTCDate() - 1);
    return due.toISOString().slice(0, 10);
}

export async function listProductionObjects(bucket, prefix) {
    const objects = [];
    const cursors = new Set();
    let cursor;
    do {
        const page = await bucket.list({prefix, limit: 1000, cursor});
        objects.push(...page.objects);
        if (page.truncated && (!page.cursor || cursors.has(page.cursor))) throw new Error('Incomplete production object listing');
        if (page.truncated) cursors.add(page.cursor);
        cursor = page.truncated ? page.cursor : undefined;
    } while (cursor);
    return objects;
}

function week(at) {
    const date = new Date(at);
    date.setUTCDate(date.getUTCDate() - ((date.getUTCDay() + 6) % 7));
    return date.toISOString().slice(0, 10);
}

export function productionRetention(objects, at) {
    const newestFirst = [...objects].sort((a, b) => b.key.localeCompare(a.key));
    const allAfter = at.getTime() - 14 * DAY;
    const weeksAfter = allAfter - 8 * 7 * DAY;
    const monthsAfter = new Date(weeksAfter);
    monthsAfter.setUTCMonth(monthsAfter.getUTCMonth() - 6);
    const keep = new Set(), periods = new Set();
    for (const [index, object] of newestFirst.entries()) {
        const match = /\/d1\/(\d{4}-\d{2}-\d{2})\.json\.aes-gcm$/.exec(object.key);
        if (!match) throw new Error("Unexpected production backup key");
        const date = new Date(match[1] + "T00:00:00Z");
        const period = date.getTime() >= allAfter ? "daily:" + match[1]
            : date.getTime() >= weeksAfter ? "weekly:" + week(date)
                : date >= monthsAfter ? "monthly:" + match[1].slice(0, 7) : null;
        if (index === 0 || (period !== null && !periods.has(period))) { keep.add(object.key); if (period) periods.add(period); }
    }
    let bytes = newestFirst.filter(object => keep.has(object.key)).reduce((sum, object) => sum + object.size, 0);
    for (const object of [...newestFirst].reverse()) {
        if (bytes <= 50_000 * 1024 * 1024) break;
        if (object === newestFirst[0] || !keep.has(object.key)) continue;
        keep.delete(object.key); bytes -= object.size;
    }
    return {keep: [...keep], remove: newestFirst.filter(object => !keep.has(object.key)).map(object => object.key)};
}

async function snapshot(env) {
    const schemaSql = "select type,name,tbl_name,sql from sqlite_master where sql is not null and name not glob 'sqlite_*' and name not glob '_cf_*' order by case type when 'table' then 0 when 'index' then 1 else 2 end,name";
    const schema = (await env.DB.prepare(schemaSql).all()).results;
    const tables = schema.filter(item => item.type === "table").map(item => item.name);
    if (tables.length < 40 || tables.some(name => !/^[a-z_][a-z0-9_]*$/i.test(name))) throw new Error("Unexpected production backup schema");
    const result = await env.DB.batch([
        ...tables.map(name => env.DB.prepare(`select * from "${name}"`)), env.DB.prepare("select name,seq from sqlite_sequence order by name"),
        env.DB.prepare("pragma foreign_key_check"), env.DB.prepare("pragma quick_check"), env.DB.prepare(schemaSql),
    ]);
    if (JSON.stringify(schema) !== JSON.stringify(result[tables.length + 3].results) || result[tables.length + 1].results.length || result[tables.length + 2].results[0]?.quick_check !== "ok") throw new Error("Production backup integrity check failed");
    return {version: 1, database: DATABASE, captured_at: new Date().toISOString(), schema, tables: Object.fromEntries(tables.map((name, index) => [name, result[index].results])), sequences: result[tables.length].results, files: []};
}

export function productionBackupPrefix(checkpoint = null) {
    if (checkpoint === null) return BACKUP_PREFIX;
    if (typeof checkpoint !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(checkpoint)) throw new Error('Invalid production checkpoint');
    return BACKUP_PREFIX + 'checkpoints/' + checkpoint + '/';
}

export async function createProductionBackup(env, at, checkpoint = null) {
    if (env.DATABASE_NAME !== DATABASE || env.BACKUPS_ENABLED !== "true") throw new Error("Production backups are disabled");
    const prefix = productionBackupPrefix(checkpoint);
    const objectKey = prefix + "d1/" + productionBackupSlot(at) + ".json.aes-gcm";
    if (await env.BACKUP_FILES.head(objectKey)) return {status: "already_present", key: objectKey};
    const captured = await snapshot(env);
    if (checkpoint !== null && encoder.encode(JSON.stringify(captured)).length > 8_000_000) throw new Error("Production database backup exceeds memory budget");
    const files = [...await listProductionObjects(env.APPLICATION_FILES, "private/"), ...await listProductionObjects(env.APPLICATION_FILES, "public/")]
        .filter(object => !object.key.startsWith(BACKUP_PREFIX) && !object.key.startsWith("private/webhook-inbox/"));
    for (const file of files) {
        if (file.size > 16 * 1024 * 1024) throw new Error("Application file exceeds production backup size budget");
        const current = await env.APPLICATION_FILES.get(file.key, {onlyIf: {etagMatches: file.etag}});
        if (!current || !("body" in current) || current.etag !== file.etag) throw new Error("Application file changed during backup");
        const bytes = new Uint8Array(await current.arrayBuffer());
        const sha256 = await digest(bytes);
        const blobKey = prefix + "files/" + sha256 + ".json.aes-gcm";
        if (!await env.BACKUP_FILES.head(blobKey)) {
            const envelope = await sealProductionBackup(bytes, env.BACKUP_KEY, "file:" + sha256);
            await env.BACKUP_FILES.put(blobKey, JSON.stringify(envelope), {onlyIf: {etagDoesNotMatch: "*"}, httpMetadata: {contentType: "application/octet-stream"}});
        }
        captured.files.push({path: file.key, bytes: bytes.length, sha256, backup_key: blobKey});
    }
    for (const table of ["report_artifacts", "reconciliation_imports"]) {
        for (const row of captured.tables[table] ?? []) {
            if (!["local", "public"].includes(row.disk) || typeof row.path !== "string" || /(^\/|\.\.|\\)/.test(row.path)) throw new Error("Unknown application file mapping");
            const path = (row.disk === "public" ? "public/" : "private/") + row.path;
            if (!captured.files.some(file => file.path === path)) throw new Error("A database-referenced file is missing from the backup");
        }
    }
    if (checkpoint !== null) {
        const currentFiles = [...await listProductionObjects(env.APPLICATION_FILES, "private/"), ...await listProductionObjects(env.APPLICATION_FILES, "public/")]
            .filter(object => !object.key.startsWith(BACKUP_PREFIX) && !object.key.startsWith("private/webhook-inbox/"));
        const inventory = values => JSON.stringify(values.map(item => [item.key, item.etag, item.size]).sort());
        if (inventory(files) !== inventory(currentFiles)) throw new Error('Production file inventory changed during checkpoint');
        const current = await snapshot(env);
        const canonical = rows => JSON.stringify(rows.map(row => JSON.stringify(Object.keys(row).sort().map(key => [key, row[key]]))).sort());
        if (JSON.stringify(captured.schema) !== JSON.stringify(current.schema)
            || canonical(captured.sequences) !== canonical(current.sequences)
            || Object.keys(captured.tables).some(name => !current.tables[name] || canonical(captured.tables[name]) !== canonical(current.tables[name]))) throw new Error('Production database changed during checkpoint');
    }
    const bytes = encoder.encode(JSON.stringify(captured));
    if (bytes.length > 8_000_000) throw new Error("Production database backup exceeds memory budget");
    const envelope = await sealProductionBackup(bytes, env.BACKUP_KEY, "database");
    const verified = await openProductionBackup(envelope, env.BACKUP_KEY);
    if (await digest(verified) !== await digest(bytes)) throw new Error("Production backup verification failed");
    const stored = await env.BACKUP_FILES.put(objectKey, JSON.stringify(envelope), {onlyIf: {etagDoesNotMatch: "*"}, httpMetadata: {contentType: "application/octet-stream"}, customMetadata: {captured_at: captured.captured_at, plaintext_sha256: envelope.sha256}});
    return {status: stored ? "created" : "already_present", key: objectKey, tables: Object.keys(captured.tables).length, files: captured.files.length};
}

export async function cleanProductionBackups(env, at) {
    if (env.DATABASE_NAME !== DATABASE || env.BACKUPS_ENABLED !== "true") throw new Error("Production backups are disabled");
    const objects = await listProductionObjects(env.BACKUP_FILES, BACKUP_PREFIX + "d1/");
    const retained = productionRetention(objects, at);
    const referenced = new Set();
    for (const objectKey of retained.keep) {
        const object = await env.BACKUP_FILES.get(objectKey);
        if (!object || object.size > 16_000_000) throw new Error("Retained production backup is unavailable");
        const snapshot = JSON.parse(new TextDecoder().decode(await openProductionBackup(JSON.parse(await object.text()), env.BACKUP_KEY)));
        if (!Array.isArray(snapshot.files)) throw new Error("Missing production file manifest");
        for (const file of snapshot.files) {
            if (!file.backup_key?.startsWith(BACKUP_PREFIX + "files/") || !await env.BACKUP_FILES.head(file.backup_key)) throw new Error("A retained backup file is unavailable");
            referenced.add(file.backup_key);
        }
    }
    for (const objectKey of retained.remove) await env.BACKUP_FILES.delete(objectKey);
    const blobs = await listProductionObjects(env.BACKUP_FILES, BACKUP_PREFIX + "files/");
    for (const blob of blobs) {
        if (!referenced.has(blob.key) && blob.uploaded.getTime() < at.getTime() - 2 * DAY) await env.BACKUP_FILES.delete(blob.key);
    }
    return {retained: retained.keep.length, removed: retained.remove.length};
}
