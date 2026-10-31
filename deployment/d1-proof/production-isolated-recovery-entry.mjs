import {WorkerEntrypoint} from 'cloudflare:workers';
import {restoreProductionCheckpoint} from './production-isolated-restore.mjs';

export class ProductionIsolatedRecovery extends WorkerEntrypoint {
    async restore(checkpoint) {
        return restoreProductionCheckpoint(this.env, checkpoint);
    }
}

export default {
    async fetch() {
        return new Response('Not found', {status: 404, headers: {'Cache-Control': 'no-store'}});
    },
};
