export const PRODUCTION_CONTAINER_NAME = 'familyfunds-production-weur-v1';

export function getProductionContainer(env) {
    return env.PRODUCTION_CONTAINER.getByName(PRODUCTION_CONTAINER_NAME, {locationHint: 'weur'});
}
