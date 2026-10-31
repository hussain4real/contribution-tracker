import {listProductionObjects, openProductionBackup, pendingProductionWebhookReferences, productionBackupPrefix} from './production-backup.mjs';

const digest = async bytes => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), byte => byte.toString(16).padStart(2, '0')).join('');
const canonical = rows => JSON.stringify(rows.map(row => JSON.stringify(Object.keys(row).sort().map(key => [key, row[key]]))).sort());
const validName = value => typeof value === 'string' && /^[a-z_][a-z0-9_]*$/i.test(value);
const ordered = value => JSON.stringify(Object.entries(value).filter(([, item]) => item !== undefined).sort(([a], [b]) => a.localeCompare(b)));

async function objectBytes(bucket, key, limit) {
    const object = await bucket.get(key);
    if (!object || object.size > limit) throw new Error('Missing or excessive checkpoint object');
    return {object, bytes: new Uint8Array(await object.arrayBuffer())};
}

export async function restoreProductionCheckpoint(env, checkpoint) {
    if (env.PRODUCTION_ISOLATED_RESTORE_ENABLED !== 'true' || env.RECOVERY_DATABASE_NAME !== 'familyfunds-production-isolated-recovery'
        || env.RECOVERY_BUCKET_NAME !== 'familyfunds-production-isolated-recovery-files' || !env.RECOVERY_DB || !env.RECOVERY_FILES || !env.BACKUP_FILES) throw new Error('Isolated production recovery is disabled or misconfigured');
    if (typeof checkpoint !== 'string') throw new Error('Explicit checkpoint required');
    const prefix = productionBackupPrefix(checkpoint);
    const existing = await env.RECOVERY_DB.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*' and name not glob '_cf_*'").first();
    const objects = await env.RECOVERY_FILES.list({limit: 1});
    if (existing.n !== 0 || objects.objects.length || objects.truncated) throw new Error('Recovery targets must be empty');
    const snapshots = await listProductionObjects(env.BACKUP_FILES, prefix + 'd1/');
    if (snapshots.length !== 1 || !/^\d{4}-\d{2}-\d{2}\.json\.aes-gcm$/.test(snapshots[0].key.slice((prefix + 'd1/').length))) throw new Error('Checkpoint must contain exactly one database snapshot');
    const databaseObject = await objectBytes(env.BACKUP_FILES, snapshots[0].key, 12_000_000);
    const envelope = JSON.parse(new TextDecoder().decode(databaseObject.bytes));
    if (envelope.kind !== 'database') throw new Error('Invalid database checkpoint identity');
    const snapshot = JSON.parse(new TextDecoder().decode(await openProductionBackup(envelope, env.BACKUP_KEY)));
    if (snapshot.version !== 1 || snapshot.database !== 'familyfunds-production' || !Array.isArray(snapshot.schema) || !Array.isArray(snapshot.sequences)
        || !Array.isArray(snapshot.files) || !snapshot.tables || typeof snapshot.tables !== 'object' || Array.isArray(snapshot.tables)) throw new Error('Invalid production checkpoint');
    const names = Object.keys(snapshot.tables), schemaTables = snapshot.schema.filter(item => item.type === 'table');
    if (names.length < 40 || names.some(name => !validName(name) || !Array.isArray(snapshot.tables[name])) || schemaTables.length !== names.length
        || new Set(schemaTables.map(item => item.name)).size !== names.length || schemaTables.some(item => !names.includes(item.name))
        || snapshot.schema.some(item => !item || !validName(item.name) || typeof item.sql !== 'string' || !['table', 'index', 'trigger', 'view'].includes(item.type))) throw new Error('Invalid checkpoint schema');
    if (snapshot.files.length > 2000) throw new Error('Excessive checkpoint files');
    const paths = new Set();
    const readFile = async file => {
        if (!file || typeof file.path !== 'string' || !/^(private|public)\//.test(file.path) || /\.\.|\\/.test(file.path)
            || file.path.startsWith('private/production-backups/') || typeof file.sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(file.sha256)
            || !Number.isSafeInteger(file.bytes) || file.bytes < 0 || file.bytes > 16 * 1024 * 1024
            || file.backup_key !== prefix + 'files/' + file.sha256 + '.json.aes-gcm') throw new Error('Invalid checkpoint file');
        const http = file.http_metadata, custom = file.custom_metadata;
        if (!http || typeof http !== 'object' || Array.isArray(http) || !custom || typeof custom !== 'object' || Array.isArray(custom)
            || Object.values(custom).some(value => typeof value !== 'string')
            || Object.entries(http).some(([key, value]) => !['contentType', 'contentLanguage', 'contentDisposition', 'contentEncoding', 'cacheControl', 'cacheExpiry'].includes(key)
                || typeof value !== 'string' || (key === 'cacheExpiry' && !Number.isFinite(Date.parse(value))))) throw new Error('Invalid checkpoint file metadata');
        const stored = await objectBytes(env.BACKUP_FILES, file.backup_key, 24_000_000);
        const fileEnvelope = JSON.parse(new TextDecoder().decode(stored.bytes));
        if (fileEnvelope.kind !== 'file:' + file.sha256) throw new Error('Invalid checkpoint file identity');
        const bytes = await openProductionBackup(fileEnvelope, env.BACKUP_KEY);
        if (bytes.length !== file.bytes || await digest(bytes) !== file.sha256) throw new Error('Checkpoint file differs');
        return bytes;
    };
    for (const file of snapshot.files) {
        if (paths.has(file?.path)) throw new Error('Duplicate checkpoint file');
        await readFile(file);
        paths.add(file.path);
    }
    for (const table of ['report_artifacts', 'reconciliation_imports']) {
        if (!Array.isArray(snapshot.tables[table])) throw new Error('Missing checkpoint file references');
        for (const row of snapshot.tables[table]) {
            if (!row || !['local', 'public'].includes(row.disk) || typeof row.path !== 'string' || /(^\/|\.\.|\\)/.test(row.path)
                || !paths.has((row.disk === 'public' ? 'public/' : 'private/') + row.path)) throw new Error('Missing checkpoint referenced file');
        }
    }
    const webhooks = pendingProductionWebhookReferences(snapshot.tables.d1_runtime_webhook_inbox);
    if (webhooks.some(reference => !paths.has(reference.key))) throw new Error('Missing pending webhook file');
    for (const reference of webhooks) {
        const file = snapshot.files.find(item => item.path === reference.key);
        let record;
        try {
            record = JSON.parse(new TextDecoder('utf-8', {fatal: true}).decode(await readFile(file)));
        } catch {
            throw new Error('Invalid pending webhook payload');
        }
        if (!record || record.provider !== reference.provider || typeof record.raw !== 'string' || typeof record.signature !== 'string'
            || await digest(new TextEncoder().encode(record.raw)) !== reference.sha256) throw new Error('Invalid pending webhook payload');
    }
    const statements = [env.RECOVERY_DB.prepare('pragma defer_foreign_keys=on')];
    for (const item of schemaTables) statements.push(env.RECOVERY_DB.prepare(item.sql));
    for (const name of names) {
        const rows = snapshot.tables[name];
        if (!rows.length) continue;
        const columns = Object.keys(rows[0]);
        if (!columns.length || columns.some(column => !validName(column)) || rows.some(row => !row || typeof row !== 'object' || Array.isArray(row)
            || Object.keys(row).length !== columns.length || columns.some(column => !Object.hasOwn(row, column)
                || !(row[column] === null || typeof row[column] === 'string' || (typeof row[column] === 'number' && Number.isFinite(row[column]) && (!Number.isInteger(row[column]) || Number.isSafeInteger(row[column]))))))) throw new Error('Unsupported checkpoint row');
        const sql = `insert into "${name}"(${columns.map(column => `"${column}"`).join(',')}) select ${columns.map((_column, index) => `json_extract(value,'$[${index}]')`).join(',')} from json_each(?)`;
        for (let i = 0; i < rows.length; i += 50) statements.push(env.RECOVERY_DB.prepare(sql).bind(JSON.stringify(rows.slice(i, i + 50).map(row => columns.map(column => row[column])))));
    }
    for (const row of snapshot.sequences) {
        if (!row || !validName(row.name) || !Number.isSafeInteger(row.seq) || row.seq < 0) throw new Error('Invalid checkpoint sequence');
        statements.push(env.RECOVERY_DB.prepare('delete from sqlite_sequence where name=?').bind(row.name), env.RECOVERY_DB.prepare('insert into sqlite_sequence(name,seq) values(?,?)').bind(row.name, row.seq));
    }
    for (const item of snapshot.schema.filter(item => item.type !== 'table')) statements.push(env.RECOVERY_DB.prepare(item.sql));
    statements.push(env.RECOVERY_DB.prepare('pragma foreign_key_check'), env.RECOVERY_DB.prepare('pragma quick_check'));
    if (statements.length > 900) throw new Error('Restore exceeds one atomic batch');
    const result = await env.RECOVERY_DB.batch(statements);
    if (result.at(-2).results.length || result.at(-1).results[0]?.quick_check !== 'ok') throw new Error('Restored database integrity failed');
    for (const name of names) {
        if (canonical((await env.RECOVERY_DB.prepare(`select * from "${name}"`).all()).results) !== canonical(snapshot.tables[name])) throw new Error('Restored table differs: ' + name);
    }
    const sequences = (await env.RECOVERY_DB.prepare('select name,seq from sqlite_sequence order by name').all()).results;
    if (canonical(sequences) !== canonical(snapshot.sequences)) throw new Error('Restored sequences differ');
    const schema = (await env.RECOVERY_DB.prepare("select type,name,tbl_name,sql from sqlite_master where sql is not null and name not glob 'sqlite_*' and name not glob '_cf_*' order by case type when 'table' then 0 when 'index' then 1 else 2 end,name").all()).results;
    if (canonical(schema) !== canonical(snapshot.schema)) throw new Error('Restored schema differs');
    for (const file of snapshot.files) {
        const bytes = await readFile(file), httpMetadata = {...file.http_metadata};
        if (httpMetadata.cacheExpiry) httpMetadata.cacheExpiry = new Date(httpMetadata.cacheExpiry);
        if (!await env.RECOVERY_FILES.put(file.path, bytes, {onlyIf: {etagDoesNotMatch: '*'}, httpMetadata, customMetadata: file.custom_metadata})) throw new Error('Recovery file already exists');
        const restored = await objectBytes(env.RECOVERY_FILES, file.path, 16 * 1024 * 1024);
        if (await digest(restored.bytes) !== file.sha256 || restored.bytes.length !== file.bytes
            || ordered(restored.object.httpMetadata ?? {}) !== ordered(file.http_metadata) || ordered(restored.object.customMetadata ?? {}) !== ordered(file.custom_metadata)) throw new Error('Restored file differs');
    }
    return {status: 'passed', tables: names.length, files: snapshot.files.length, sequences: sequences.length, pending_webhooks: webhooks.length, integrity: 'ok'};
}
