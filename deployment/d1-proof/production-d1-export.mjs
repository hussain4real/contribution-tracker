export async function exportD1Continuously(request, {accountId, databaseId, timeoutMs = 120000, now = Date.now}) {
    if (!/^[a-f0-9]{32}$/.test(accountId) || !/^[a-f0-9-]{36}$/.test(databaseId)
        || !Number.isInteger(timeoutMs) || timeoutMs < 1 || timeoutMs > 180000) throw new Error('Invalid bounded D1 export target');
    const started = now();
    const trace = [];
    let bookmark;
    const fail = message => {
        const error = new Error(message);
        error.trace = trace;
        throw error;
    };
    while (true) {
        if (now() - started >= timeoutMs) fail('D1 export deadline exceeded');
        const body = {output_format: 'polling', ...(bookmark ? {current_bookmark: bookmark} : {})};
        let response;
        try {
            response = await request({method: 'POST', path: `/accounts/${accountId}/d1/database/${databaseId}/export`, body});
        } catch {
            fail('D1 export transport failed');
        }
        const result = response.result;
        trace.push({request: trace.length + 1, elapsed_ms: now() - started, http: response.status,
            status: result?.status ?? null, bookmark: result?.at_bookmark ?? null,
            success: response.success === true && result?.success === true});
        if (response.success !== true || result?.success !== true) fail(result?.error === 'Not currently exporting anything.'
            ? 'D1 export cancelled: Not currently exporting anything.' : 'D1 export API rejected the operation');
        if (typeof result.at_bookmark !== 'string' || !result.at_bookmark
            || (bookmark && result.at_bookmark !== bookmark)) fail('D1 export bookmark missing or changed');
        if (result.status === 'complete') {
            let url;
            try { url = new URL(result.result?.signed_url); } catch { fail('D1 export completed without a download URL'); }
            if (url.protocol !== 'https:' || !url.hostname.endsWith('.r2.cloudflarestorage.com')
                || typeof result.result.filename !== 'string' || !result.result.filename) fail('D1 export returned an invalid download');
            return {completion: result, trace};
        }
        if (result.status !== 'active') fail('D1 export returned an unsuccessful state');
        bookmark = result.at_bookmark;
    }
}
