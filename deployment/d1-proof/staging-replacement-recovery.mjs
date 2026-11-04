import {WorkerEntrypoint} from 'cloudflare:workers';
import {buildReplacementPhase01Batch} from './staging-phase01-migration.mjs';

export {StagingBackupOnly, StagingRecoveryOnly} from './maintenance-worker.mjs';

export class ReplacementMigrationPreview extends WorkerEntrypoint {
    async render(capture) {
        if (this.env.STAGING_BACKUP_ONLY_ENABLED !== 'false' || this.env.STAGING_RESTORE_ONLY_ENABLED !== 'false') {
            throw new Error('Disable checkpoint operations before migration preview');
        }
        return buildReplacementPhase01Batch(capture, this.env);
    }
}

export default {
    fetch() {
        return new Response('Not found', {status: 404});
    },
};
