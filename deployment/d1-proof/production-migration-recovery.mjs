import {WorkerEntrypoint} from 'cloudflare:workers';
import {createProductionBackup, openProductionBackup, productionBackupSlot, productionBackupPrefix, BACKUP_PREFIX, listProductionObjects} from './production-backup.mjs';

const ARCHIVE = 'migration-archive/synthetic-20261027/';
const RECOVERY = 'migration-recovery/actual-20261027/';
const encoder = new TextEncoder();
const digest = async bytes => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), b => b.toString(16).padStart(2, '0')).join('');

export async function assertMigrationRecovery(env) {
    if (env.MIGRATION_RECOVERY_ENABLED !== 'true' || env.DATABASE_NAME !== 'familyfunds-production'
        || env.ENABLED !== 'false' || env.BACKUPS_ENABLED !== 'false' || env.OFFSITE_ENABLED !== 'false') throw new Error('Isolated actual-data recovery is disabled');
    const row = await env.DB.prepare('select (select count(*) from families) families,(select count(*) from users) users,(select count(*) from jobs) jobs').first();
    if (row.families !== 44 || row.users !== 66 || row.jobs !== 0) throw new Error('Actual snapshot identity changed; repeat review');
}

async function boundedObject(bucket, path) {
    const object = await bucket.get(path);
    if (!object || object.size > 16_000_000) throw new Error('Missing or excessive bounded recovery object');
    return {object, bytes: new Uint8Array(await object.arrayBuffer())};
}

export class ProductionMigrationRecovery extends WorkerEntrypoint {
    async run(operation, checkpoint = null) {
        await assertMigrationRecovery(this.env);
        if (operation === 'status') return {release: 'production-recovery-v17-explicit-checkpoint'};
        if (['backup', 'restore', 'offsite'].includes(operation)) {
            if (typeof checkpoint !== 'string') throw new Error('Explicit checkpoint required');
            productionBackupPrefix(checkpoint);
        }
        if (operation === 'archive-synthetic') return this.archiveSynthetic();
        if (operation === 'backup') {
            if (!await this.env.BACKUP_FILES.head(ARCHIVE + 'completed.json')) throw new Error('Archive synthetic backup history first');
            return createProductionBackup({...this.env, BACKUPS_ENABLED: 'true'}, new Date(), checkpoint);
        }
        if (operation === 'restore') return this.restore(checkpoint);
        if (operation === 'offsite') {
            const day = new Date().toISOString().slice(0, 10);
            return this.env.OFFSITE_CONTAINER.getByName('familyfunds-production-offsite').copy({id: 'backup-sync:' + day, kind: 'backup-sync', family_id: 0, cursor: 0, at: day + 'T00:30:00.000Z', checkpoint});
        }
        throw new Error('Unknown isolated recovery operation');
    }

    async archiveSynthetic() {
        const existing = await this.env.BACKUP_FILES.get(ARCHIVE + 'completed.json');
        if (existing) {
            if (existing.size > 100000) throw new Error('Excessive archive marker');
            return {...JSON.parse(await existing.text()), already_archived: true};
        }
        const references = new Set();
        for (const table of ['report_artifacts', 'reconciliation_imports']) {
            for (const row of (await this.env.DB.prepare('select disk,path from ' + table).all()).results) {
                references.add((row.disk === 'public' ? 'public/' : 'private/') + row.path);
            }
        }
        const archived = [];
        for (const [name, bucket, prefixes] of [['backups', this.env.BACKUP_FILES, [BACKUP_PREFIX]], ['application', this.env.APPLICATION_FILES, ['private/', 'public/']]]) {
            for (const prefix of prefixes) {
                const objects = await listProductionObjects(bucket, prefix);
                if (objects.length > 2000) throw new Error('Excessive synthetic archive');
                for (const item of objects) {
                    if (name === 'application' && (references.has(item.key) || item.key.startsWith('private/webhook-inbox/'))) continue;
                    const destination = ARCHIVE + name + '/' + item.key;
                    const {object, bytes} = await boundedObject(bucket, item.key);
                    const sha = await digest(bytes);
                    const previous = await bucket.head(destination);
                    if (!previous) await bucket.put(destination, bytes, {onlyIf: {etagDoesNotMatch: '*'}, httpMetadata: object.httpMetadata, customMetadata: object.customMetadata});
                    const copy = await boundedObject(bucket, destination);
                    if (await digest(copy.bytes) !== sha || copy.bytes.length !== bytes.length) throw new Error('Synthetic archive checksum mismatch');
                    archived.push({bucket: name, key: item.key, destination, bytes: bytes.length, sha256: sha});
                }
            }
        }
        for (const item of archived) {
            const bucket = item.bucket === 'backups' ? this.env.BACKUP_FILES : this.env.APPLICATION_FILES;
            const current = await boundedObject(bucket, item.key);
            if (await digest(current.bytes) !== item.sha256) throw new Error('Synthetic source changed before archive removal');
        }
        for (const item of archived) await (item.bucket === 'backups' ? this.env.BACKUP_FILES : this.env.APPLICATION_FILES).delete(item.key);
        const result = {archived, verified: true, originals_removed_after_verification: true, actual_database_references_preserved: references.size};
        await this.env.BACKUP_FILES.put(ARCHIVE + 'completed.json', JSON.stringify(result), {onlyIf: {etagDoesNotMatch: '*'}});
        return result;
    }

    async restore(checkpoint) {
        if (typeof checkpoint !== 'string') throw new Error('Explicit checkpoint required');
        if (this.env.RECOVERY_NAME !== 'familyfunds-production-migration-recovery') throw new Error('Wrong actual recovery target');
        const existing = await this.env.RECOVERY_DB.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*' and name not glob '_cf_*'").first();
        if (existing.n !== 0) throw new Error('Actual recovery target must be empty; never replay restore');
        const stored = await boundedObject(this.env.BACKUP_FILES, productionBackupPrefix(checkpoint) + 'd1/' + productionBackupSlot(new Date()) + '.json.aes-gcm');
        const envelope = JSON.parse(new TextDecoder().decode(stored.bytes));
        const snapshot = JSON.parse(new TextDecoder().decode(await openProductionBackup(envelope, this.env.BACKUP_KEY)));
        if (snapshot.tables.users?.length !== 66 || snapshot.tables.families?.length !== 44 || snapshot.files.length !== 7) throw new Error('Unexpected actual recovery snapshot');
        const names = Object.keys(snapshot.tables);
        if (names.length !== 55 || names.some(name => !/^[a-z_][a-z0-9_]*$/.test(name))) throw new Error('Unexpected recovery tables');
        const statements = [this.env.RECOVERY_DB.prepare('pragma defer_foreign_keys=on')];
        for (const item of snapshot.schema.filter(item => item.type === 'table')) statements.push(this.env.RECOVERY_DB.prepare(item.sql));
        for (const name of names) {
            const rows = snapshot.tables[name];
            if (!rows.length) continue;
            const columns = Object.keys(rows[0]);
            if (columns.some(column => !/^[a-z_][a-z0-9_]*$/.test(column))) throw new Error('Unexpected recovery columns');
            const sql = `insert into "${name}"(${columns.map(c => `"${c}"`).join(',')}) select ${columns.map((_c, i) => `json_extract(value,'$[${i}]')`).join(',')} from json_each(?)`;
            for (let i = 0; i < rows.length; i += 50) statements.push(this.env.RECOVERY_DB.prepare(sql).bind(JSON.stringify(rows.slice(i, i + 50).map(row => columns.map(column => row[column])))));
        }
        for (const row of snapshot.sequences) {
            statements.push(this.env.RECOVERY_DB.prepare('delete from sqlite_sequence where name=?').bind(row.name));
            statements.push(this.env.RECOVERY_DB.prepare('insert into sqlite_sequence(name,seq) values(?,?)').bind(row.name, row.seq));
        }
        for (const item of snapshot.schema.filter(item => item.type !== 'table')) statements.push(this.env.RECOVERY_DB.prepare(item.sql));
        statements.push(this.env.RECOVERY_DB.prepare('pragma foreign_key_check'), this.env.RECOVERY_DB.prepare('pragma quick_check'));
        if (statements.length > 900) throw new Error('Actual restore exceeds one atomic batch');
        const outcome = await this.env.RECOVERY_DB.batch(statements);
        if (outcome.at(-2).results.length || outcome.at(-1).results[0]?.quick_check !== 'ok') throw new Error('Actual restore integrity failed');
        const canonical = rows => JSON.stringify(rows.map(row => JSON.stringify(Object.keys(row).sort().map(key => [key, row[key]]))).sort());
        const tables = [];
        for (const name of names) {
            const actual = (await this.env.RECOVERY_DB.prepare('select * from "' + name + '"').all()).results;
            const expected = await digest(encoder.encode(canonical(snapshot.tables[name])));
            const sha = await digest(encoder.encode(canonical(actual)));
            if (sha !== expected) throw new Error('Restored actual table differs: ' + name);
            tables.push({table: name, rows: actual.length, sha256: sha});
        }
        const sequences = (await this.env.RECOVERY_DB.prepare('select name,seq from sqlite_sequence order by name').all()).results;
        if (canonical(sequences) !== canonical(snapshot.sequences)) throw new Error('Restored sequence high-water marks differ');
        const files = [];
        for (const file of snapshot.files) {
            const sealed = await boundedObject(this.env.BACKUP_FILES, file.backup_key);
            const bytes = await openProductionBackup(JSON.parse(new TextDecoder().decode(sealed.bytes)), this.env.BACKUP_KEY);
            if (bytes.length !== file.bytes || await digest(bytes) !== file.sha256) throw new Error('Decrypted actual file differs');
            const path = RECOVERY + file.sha256;
            await this.env.APPLICATION_FILES.put(path, bytes);
            const restored = await boundedObject(this.env.APPLICATION_FILES, path);
            if (await digest(restored.bytes) !== file.sha256) throw new Error('Restored actual file differs');
            await this.env.APPLICATION_FILES.delete(path);
            files.push({bytes: bytes.length, sha256: file.sha256});
        }
        let tamperRejected = false;
        const altered = {...envelope, ciphertext: (envelope.ciphertext[0] === 'A' ? 'B' : 'A') + envelope.ciphertext.slice(1)};
        try { await openProductionBackup(altered, this.env.BACKUP_KEY); } catch { tamperRejected = true; }
        if (!tamperRejected) throw new Error('Backup tamper rejection failed');
        return {status: 'passed', tables, sequences: sequences.length, files, foreign_key_violations: 0, integrity: 'ok', tamper_rejected: true,
            backup_key_fingerprint: await digest(encoder.encode(this.env.BACKUP_KEY)), source_written: false, application_files_unchanged: true};
    }
}
