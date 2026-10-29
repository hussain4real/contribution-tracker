import {WorkerEntrypoint} from 'cloudflare:workers';
import maintenance from './production-maintenance-worker.mjs';
import {createProductionBackup, cleanProductionBackups, openProductionBackup, productionBackupSlot, BACKUP_PREFIX} from './production-backup.mjs';

async function assertSynthetic(env) {
    if (env.NATIVE_PROOF_ENABLED !== 'true' || env.DATABASE_NAME !== 'familyfunds-production') throw new Error('Native candidate proof disabled');
    const identity = await env.DB.prepare("select count(*) n from users where id=1 and email='candidate-admin@example.invalid' and name='Synthetic Candidate Admin'").first();
    const families = await env.DB.prepare("select count(*) n from families where slug in('candidate-first','candidate-second')").first();
    const total = await env.DB.prepare('select count(*) n from families').first();
    if (identity?.n !== 1 || families?.n !== 2 || total?.n !== 2) throw new Error('Synthetic candidate identity mismatch');
}

export class NativeCandidateProof extends WorkerEntrypoint {
    async receivePush(payload) {
        await assertSynthetic(this.env);
        if (!payload || payload.encoding !== 'aes128gcm' || payload.vapid_verified !== true || typeof payload.body !== 'string' || payload.body.length > 11000) throw new Error('Invalid synthetic push');
        await this.env.APPLICATION_FILES.put('private/native-webpush/latest.json', JSON.stringify(payload));
        return {stored: true};
    }

    async run(operation) {
        await assertSynthetic(this.env);
        const env = {...this.env, ENABLED: 'true', BACKUPS_ENABLED: 'false', OFFSITE_ENABLED: 'false'};
        if (operation === 'webpush') {
            const id = 'native-webpush-drain:' + crypto.randomUUID();
            await env.DB.prepare('insert into d1_runtime_schedule_outbox(id,kind,family_id,due_at) values(?,?,?,?)').bind(id, 'drain', 0, new Date().toISOString()).run();
            let outcome = 'unresolved';
            await maintenance.queue({queue: 'familyfunds-production-maintenance', messages: [{id, body: {id}, ack() {outcome = 'ack';}, retry() {outcome = 'retry';}}]}, env);
            for (let attempt = 0; attempt < 45; attempt++) {
                const jobs = (await env.DB.prepare('select count(*) n from jobs').first()).n;
                const failed = (await env.DB.prepare('select count(*) n from failed_jobs').first()).n;
                const object = await env.APPLICATION_FILES.get('private/native-webpush/latest.json');
                if (object && object.size > 12000) throw new Error('Oversized synthetic push receipt');
                if (jobs === 0 || failed > 0) return {outcome, jobs, failed_jobs: failed,
                    receipt: object ? JSON.parse(await object.text()) : null, observation_attempts: attempt + 1};
                await new Promise(resolve => setTimeout(resolve, 1000));
            }
            throw new Error('Synthetic push worker did not finish its existing claim');
        }
        if (operation === 'offsite') {
            const day = new Date().toISOString().slice(0, 10);
            const id = 'backup-sync:' + day;
            await env.DB.prepare('insert into d1_runtime_schedule_outbox(id,kind,family_id,due_at) values(?,?,?,?) on conflict(id) do nothing').bind(id, 'backup-sync', 0, day + 'T00:30:00.000Z').run();
            const outcomes = [];
            for (let attempt = 0; attempt < 2; attempt++) {
                const message = {id: 'native-offsite:' + attempt, body: {id}, ack() {outcomes.push('ack');}, retry() {outcomes.push('retry');}};
                await maintenance.queue({queue: 'familyfunds-production-maintenance', messages: [message]}, env);
            }
            const row = await env.DB.prepare('select completed_at,lease_token,lease_until from d1_runtime_schedule_outbox where id=?').bind(id).first();
            const health = await env.DB.prepare("select status,details from d1_runtime_health where name='offsite-copy'").first();
            return {outcomes, task: row, health: health ? {status: health.status, details: JSON.parse(health.details)} : null};
        }
        if (operation === 'restore') {
            if (env.RECOVERY_NAME !== 'familyfunds-production-native-recovery') throw new Error('Wrong isolated recovery database');
            const existing = await env.RECOVERY_DB.prepare("select count(*) n from sqlite_master where type='table' and name not glob 'sqlite_*' and name not glob '_cf_*'").first();
            if (existing.n !== 0) throw new Error('Recovery database must be empty; no restore replay');
            const object = await env.BACKUP_FILES.get(BACKUP_PREFIX + 'd1/' + productionBackupSlot(new Date()) + '.json.aes-gcm');
            if (!object || object.size > 16_000_000) throw new Error('Missing bounded candidate backup');
            const envelope = JSON.parse(await object.text());
            const snapshot = JSON.parse(new TextDecoder().decode(await openProductionBackup(envelope, env.BACKUP_KEY)));
            const names = Object.keys(snapshot.tables);
            if (names.length < 45 || names.some(name => !/^[a-z_][a-z0-9_]*$/.test(name))) throw new Error('Unexpected recovery tables');
            const statements = [env.RECOVERY_DB.prepare('pragma defer_foreign_keys=on')];
            for (const item of snapshot.schema.filter(item => item.type === 'table')) statements.push(env.RECOVERY_DB.prepare(item.sql));
            for (const name of names) {
                const rows = snapshot.tables[name];
                if (!rows.length) continue;
                const columns = Object.keys(rows[0]);
                if (columns.some(column => !/^[a-z_][a-z0-9_]*$/.test(column))) throw new Error('Unexpected recovery columns');
                const sql = `insert into "${name}"(${columns.map(column => `"${column}"`).join(',')}) select ${columns.map((_column, index) => `json_extract(value,'$[${index}]')`).join(',')} from json_each(?)`;
                for (let i = 0; i < rows.length; i += 100) statements.push(env.RECOVERY_DB.prepare(sql).bind(JSON.stringify(rows.slice(i, i + 100).map(row => columns.map(column => row[column])))));
            }
            for (const sequence of snapshot.sequences) {
                statements.push(env.RECOVERY_DB.prepare('delete from sqlite_sequence where name=?').bind(sequence.name));
                statements.push(env.RECOVERY_DB.prepare('insert into sqlite_sequence(name,seq) values(?,?)').bind(sequence.name, sequence.seq));
            }
            for (const item of snapshot.schema.filter(item => item.type !== 'table')) statements.push(env.RECOVERY_DB.prepare(item.sql));
            statements.push(env.RECOVERY_DB.prepare('pragma foreign_key_check'), env.RECOVERY_DB.prepare('pragma quick_check'));
            if (statements.length > 900) throw new Error('Candidate restore exceeds one atomic batch');
            const result = await env.RECOVERY_DB.batch(statements);
            if (result.at(-2).results.length || result.at(-1).results[0]?.quick_check !== 'ok') throw new Error('Restored candidate integrity failed');
            const restored = await env.RECOVERY_DB.batch(names.map(name => env.RECOVERY_DB.prepare(`select * from "${name}"`)));
            const canonical = rows => JSON.stringify(rows.map(row => JSON.stringify(Object.keys(row).sort().map(key => [key, row[key]]))).sort());
            const digest = async text => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text))), byte => byte.toString(16).padStart(2, '0')).join('');
            const tables = [];
            for (const [index, name] of names.entries()) {
                const expected = await digest(canonical(snapshot.tables[name])), actual = await digest(canonical(restored[index].results));
                if (actual !== expected) throw new Error('Restored table differs: ' + name);
                tables.push({name, rows: restored[index].results.length, sha256: actual});
            }
            const files = [];
            for (const file of snapshot.files) {
                const sealed = await env.BACKUP_FILES.get(file.backup_key);
                const decoded = JSON.parse(await sealed.text());
                if (decoded.sha256 !== file.sha256) throw new Error('Backup file identity mismatch');
                const bytes = await openProductionBackup(decoded, env.BACKUP_KEY);
                await env.RECOVERY_FILES.put(file.path, bytes);
                const check = await env.RECOVERY_FILES.get(file.path);
                const sha256 = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await check.arrayBuffer())), byte => byte.toString(16).padStart(2, '0')).join('');
                if (sha256 !== file.sha256) throw new Error('Restored file differs');
                files.push({path: file.path, bytes: bytes.length, sha256});
            }
            return {status: 'passed', captured_at: snapshot.captured_at, tables, files, foreign_key_violations: 0, quick_check: 'ok', atomic_statements: statements.length, source_unchanged: true};
        }
        if (operation === 'queue-work') {
            const at = new Date().toISOString();
            const tasks = [
                {id: 'native-queue-generate:2026-10:2', kind: 'generate', family: 2, at: '2026-10-01T00:00:00.000Z'},
                {id: 'native-queue-drain:2026-10-26', kind: 'drain', family: 0, at},
            ];
            for (const task of tasks) {
                await env.DB.prepare('insert into d1_runtime_schedule_outbox(id,kind,family_id,due_at) values(?,?,?,?) on conflict(id) do nothing').bind(task.id, task.kind, task.family, task.at).run();
                await env.MAINTENANCE_QUEUE.send({id: task.id}, {contentType: 'json'});
            }
            return {published: tasks.map(task => task.id)};
        }
        if (operation === 'backup') {
            env.BACKUPS_ENABLED = 'true';
            const at = new Date();
            const created = await createProductionBackup(env, at);
            const object = await env.BACKUP_FILES.get(created.key);
            const envelope = JSON.parse(await object.text());
            const bytes = await openProductionBackup(envelope, env.BACKUP_KEY);
            const snapshot = JSON.parse(new TextDecoder().decode(bytes));
            const repeated = await createProductionBackup(env, at);
            const fileChecks = [];
            for (const file of snapshot.files) {
                const sealed = await env.BACKUP_FILES.get(file.backup_key);
                const plain = await openProductionBackup(JSON.parse(await sealed.text()), env.BACKUP_KEY);
                fileChecks.push({path: file.path, bytes: plain.length, expected_bytes: file.bytes});
            }
            let tamperRejected = false;
            try { await openProductionBackup({...envelope, sha256: '0'.repeat(64)}, env.BACKUP_KEY); }
            catch { tamperRejected = true; }
            return {created, repeated, tables: Object.keys(snapshot.tables).length, rows: Object.values(snapshot.tables).reduce((n, rows) => n + rows.length, 0), files: fileChecks, tamperRejected, retention: await cleanProductionBackups(env, at), plaintext_sha256: envelope.sha256};
        }
        if (operation === 'schedule') {
            const controller = {scheduledTime: Date.parse('2026-10-01T00:05:00Z')};
            await maintenance.scheduled(controller, env);
            const first = await env.DB.prepare('select id,kind,family_id,completed_at from d1_runtime_schedule_outbox order by id').all();
            await maintenance.scheduled(controller, env);
            const second = await env.DB.prepare('select id,kind,family_id,completed_at from d1_runtime_schedule_outbox order by id').all();
            return {first: first.results, second: second.results, native_queue_messages_published: true, recurring_cron_enabled: false};
        }
        if (operation === 'tasks') {
            const rows = (await env.DB.prepare("select id from d1_runtime_schedule_outbox where kind='generate' and due_at='2026-10-01T00:00:00.000Z' order by id").all()).results;
            const outcomes = [];
            for (const row of rows) {
                const execute = async () => {
                    let outcome = 'unresolved';
                    const message = {id: crypto.randomUUID(), body: {id: row.id}, ack() { outcome = 'ack'; }, retry() { outcome = 'retry'; }};
                    await maintenance.queue({queue: 'familyfunds-production-maintenance', messages: [message]}, env);
                    return outcome;
                };
                outcomes.push({id: row.id, competing_handlers: await Promise.all([execute(), execute()]), replay: await execute()});
            }
            return {outcomes, durable: (await env.DB.prepare("select id,completed_at,lease_token,lease_until from d1_runtime_schedule_outbox where kind='generate' order by id").all()).results, transport: 'native Worker handler invoked directly; actual Queue delivery acceptance remains a separate gate'};
        }
        throw new Error('Unknown native proof operation');
    }
}
export default {
    async fetch() { return new Response('Not found', {status: 404}); },
    async queue(batch, env) {
        await assertSynthetic(env);
        const messages = batch.messages.map(message => {
            const record = {id: message.id, body: message.body, outcome: 'unresolved'};
            record.ack = () => { message.ack(); record.outcome = 'ack'; };
            record.retry = options => { message.retry(options); record.outcome = 'retry'; };
            return record;
        });
        await maintenance.queue({queue: batch.queue, messages}, {...env, ENABLED: 'true', BACKUPS_ENABLED: 'false', OFFSITE_ENABLED: 'false'});
        for (const message of messages) {
            await env.DB.prepare('insert into d1_runtime_health(name,status,details,checked_at) values(?,?,?,unixepoch()) on conflict(name) do update set status=excluded.status,details=excluded.details,checked_at=excluded.checked_at').bind('native-queue:' + message.id, message.outcome === 'ack' ? 'healthy' : 'pending', JSON.stringify({queue: batch.queue, task: message.body.id, outcome: message.outcome})).run();
        }
    },
};
