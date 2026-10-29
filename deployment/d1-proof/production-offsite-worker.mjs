import {DurableObject} from 'cloudflare:workers';
import runner from './production-offsite-cli.php';
import copier from './D1ProductionBackupSync.php';

const IMAGE = 'registry.cloudflare.com/40344948b73fa9da3008958fdc3faf12/familyfunds-production@sha256:ccf5f0f3cc2b0935e7edea5354ab431cc35e57c77f31f46b2ffe8b9c8c960a2c';

export class FamilyFundsOffsiteContainer extends DurableObject {
    async alarm() {
        await this.ctx.container.destroy();
    }

    async copy(task) {
        if (this.env.OFFSITE_ENABLED !== 'true' || this.env.DATABASE_NAME !== 'familyfunds-production') throw new Error('Offsite runner is disabled');
        if (!task || task.kind !== 'backup-sync' || task.family_id !== 0 || task.cursor !== 0
            || !/^backup-sync:\d{4}-\d{2}-\d{2}$/.test(task.id) || !Number.isFinite(Date.parse(task.at))
            || task.id !== 'backup-sync:' + task.at.slice(0, 10) || Date.parse(task.at) > Date.now() + 5000) throw new Error('Invalid offsite task');
        if (task.checkpoint !== undefined && (typeof task.checkpoint !== 'string' || !/^cutover-[0-9]{8}t[0-9]{6}z$/.test(task.checkpoint))) throw new Error('Invalid offsite checkpoint');
        if (this.copying) throw new Error('Offsite copy already running');
        this.copying = true;
        try {
            return await this.run(task.checkpoint ?? null);
        } finally {
            this.copying = false;
        }
    }

    async output(command, options, milliseconds) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), milliseconds);
        try {
            const process = await this.ctx.container.exec(command, {...options, signal: controller.signal, stdout: 'pipe', stderr: 'ignore'});
            return await process.output();
        } finally {
            clearTimeout(timer);
        }
    }

    async run(checkpoint) {
        const env = {};
        env.BACKUP_CHECKPOINT = checkpoint ?? '';
        for (const name of ['FF_RCLONE_CONFIG', 'BACKUP_R2_ACCESS_KEY_ID', 'BACKUP_R2_SECRET_ACCESS_KEY']) {
            const value = this.env[name];
            if (typeof value !== 'string' || value.length < 10 || new TextEncoder().encode(value).length > 16000 || value.includes('\0')) throw new Error('Missing isolated offsite credential');
            env[name] = value;
        }
        const container = this.ctx.container;
        if (!container || container.images?.base !== IMAGE || this.env.CONTAINER_IMAGE !== IMAGE) throw new Error('Offsite image mismatch: v17-quota-diagnostics');
        await this.ctx.storage.setAlarm(Date.now() + 210000);
        try {
            if (container.running) await container.destroy();
            container.start({image: IMAGE, enableInternet: true, instance: 'lite', env: {},
                entrypoint: ['/bin/sh', '-c', 'trap "exit 0" TERM INT; while :; do sleep 3600; done']});
            const install = await this.output(['/usr/local/bin/php', '-r',
                'umask(0077);foreach(["RUNNER"=>"/run/familyfunds-offsite.php","COPIER"=>"/run/D1ProductionBackupSync.php"] as $key=>$path){$source=base64_decode(getenv($key),true);if($source===false||file_put_contents($path,$source)!==strlen($source)){exit(1);}chmod($path,0600);}'],
            {env: {RUNNER: btoa(runner), COPIER: btoa(copier)}}, 40000);
            if (install.exitCode !== 0) throw new Error('Offsite runner installation failed');
            const image = (await container.inspect())?.image;
            if (image !== IMAGE) throw new Error('Running offsite image mismatch');
            const result = await this.output(['/usr/local/bin/php', '-d', 'auto_prepend_file=', '/run/familyfunds-offsite.php'], {env}, 160000);
            if (result.stdout.byteLength > 4096) throw new Error('Isolated offsite copy failed: invalid bounded response');
            const copied = JSON.parse(new TextDecoder().decode(result.stdout));
            if (result.exitCode !== 0) {
                const d = copied?.diagnostics;
                const stages = ['configuration', 'r2-stage', 'drive-copy', 'drive-check'];
                const codes = ['unknown', 'process_timeout', 'transfer_deadline', 'backup_set', 'staging', 'size_budget', 'envelope', 'missing_snapshot', 'transfer'];
                const categories = ['immutable', 'different', 'invalid_grant', 'permission', 'unauthorized', 'not_found', 'rate_limit', 'timeout', 'copy_error', 'query_quota', 'storage_quota', 'disk_quota', 'api_quota'];
                const words = ['error', 'unknown', 'flag', 'invalid', 'failed', 'create', 'filesystem', 'drive', 'oauth', 'token', 'authorization', 'expired', 'refresh', 'revoked', 'scope', 'quota', 'mismatch', 'immutable', 'different', 'copy', 'transfer', 'corrupted', 'not', 'found', 'config', 'directory', 'cannot', 'unrecognized', 'unexpected', 'permission', 'timed', 'timeout', 'parse', 'retries', 'level', 'retry', 'connection', 'network', 'protocol', 'tls', 'certificate', 'remote', 'local', 'command', 'server', 'client', 'http', 'file', 'hash', 'checksum', 'update', 'size', 'length', 'path', 'sleep', 'read', 'write', 'digest', 'access', 'denied', 'credentials', 'endpoint', 'context', 'deadline', 'exceeded', 'fatal'];
                const safe = {stage: stages.includes(d?.stage) ? d.stage : 'unknown', code: codes.includes(d?.error_code) ? d.error_code : 'unknown',
                    exit_code: Number.isInteger(d?.process?.exit_code) && d.process.exit_code >= 0 && d.process.exit_code <= 255 ? d.process.exit_code : null,
                    seconds: Number.isFinite(d?.process?.seconds) && d.process.seconds >= 0 && d.process.seconds <= 300 ? d.process.seconds : null,
                    categories: Array.isArray(d?.process?.categories) ? d.process.categories.filter(c => categories.includes(c)).slice(0, 10) : [],
                    words: Array.isArray(d?.process?.words) ? d.process.words.filter(w => words.includes(w)).slice(0, 30) : []};
                throw new Error('Isolated offsite copy failed: ' + JSON.stringify(safe));
            }
            if (!Number.isSafeInteger(copied.files) || copied.files < 1 || !Number.isSafeInteger(copied.bytes) || copied.bytes < 1
                || copied.encrypted_only !== true || copied.checksum_verified !== true || copied.checkpoint !== checkpoint) throw new Error('Invalid offsite verification result');
            const phases = Array.isArray(copied.phases) ? copied.phases.slice(0, 5).filter(p => ['r2-stage', 'copy', 'check'].includes(p?.action)
                && Number.isFinite(p.seconds) && p.seconds >= 0 && p.seconds <= 300).map(p => ({action: p.action, seconds: p.seconds})) : [];
            return {exit_code: 0, complete: true, cursor: 0, checkpoint, files: copied.files, bytes: copied.bytes,
                encrypted_only: true, checksum_verified: true, phases, image};
        } finally {
            await container.destroy();
            await this.ctx.storage.deleteAlarm();
        }
    }
}

export default {async fetch() { return new Response('Not found', {status: 404}); }};
