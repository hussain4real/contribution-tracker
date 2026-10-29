export default {
    async fetch(request) {
        const url = new URL(request.url);
        const target = {
            'www.familyfunds.app': 'familyfunds.app',
            'staging.familyfunds.app': 'd1-staging.familyfunds.app',
        }[url.hostname];
        if (!target) return new Response('Not found', {status: 404});
        if (!['GET', 'HEAD'].includes(request.method)) return new Response('Use the canonical application hostname', {status: 421});
        url.protocol = 'https:';
        url.hostname = target;
        url.port = '';
        return Response.redirect(url.toString(), url.hostname === 'familyfunds.app' ? 308 : 307);
    },
};
