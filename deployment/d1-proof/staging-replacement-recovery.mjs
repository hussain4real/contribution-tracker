export {StagingBackupOnly, StagingRecoveryOnly} from './maintenance-worker.mjs';

export default {
    fetch() {
        return new Response('Not found', {status: 404});
    },
};
