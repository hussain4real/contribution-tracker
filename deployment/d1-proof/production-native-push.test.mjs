import assert from 'node:assert/strict';
import test from 'node:test';
import {receiveNativePush} from './production-native-push.mjs';

const origin = 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev';
const encode = bytes => Buffer.from(bytes).toString('base64url');
async function fixture() {
    const pair = await crypto.subtle.generateKey({name: 'ECDSA', namedCurve: 'P-256'}, true, ['sign', 'verify']);
    const publicKey = encode(await crypto.subtle.exportKey('raw', pair.publicKey));
    const received = [];
    const env = {NATIVE_PUSH_ENABLED: 'true', NATIVE_VAPID_PUBLIC_KEY: publicKey, NATIVE_PROOF: {receivePush: async body => received.push(body)}};
    const request = async (claims = {}, corrupt = false, size = 200) => {
        const header = encode(JSON.stringify({alg: 'ES256', typ: 'JWT'}));
        const payload = encode(JSON.stringify({aud: origin, exp: Math.floor(Date.now() / 1000) + 3600, sub: 'mailto:candidate@example.invalid', ...claims}));
        const signature = new Uint8Array(await crypto.subtle.sign({name: 'ECDSA', hash: 'SHA-256'}, pair.privateKey, new TextEncoder().encode(header + '.' + payload)));
        if (corrupt) signature[0] ^= 1;
        return new Request(origin + '/__native/push', {method: 'POST', headers: {Authorization: `vapid t=${header}.${payload}.${encode(signature)}, k=${publicKey}`, 'Content-Encoding': 'aes128gcm'}, body: new Uint8Array(size)});
    };
    return {env, received, request};
}

test('the isolated push receiver verifies the candidate VAPID signature before retaining only the encrypted payload', async () => {
    const f = await fixture();
    assert.equal((await receiveNativePush(await f.request(), f.env)).status, 201);
    assert.equal(f.received.length, 1); assert.equal(f.received[0].vapid_verified, true);
    assert.equal(Buffer.from(f.received[0].body, 'base64').length, 200);
});

test('forged, expired, wrong-audience and oversized push requests never reach candidate storage', async () => {
    const f = await fixture();
    for (const request of [await f.request({}, true), await f.request({exp: 1}), await f.request({aud: 'https://familyfunds.app'}), new Request(origin + '/__native/push', {method: 'POST', body: 'forged'})]) {
        assert.equal((await receiveNativePush(request, f.env)).status, 401);
    }
    assert.equal((await receiveNativePush(await f.request({}, false, 8193), f.env)).status, 413);
    assert.equal(f.received.length, 0);
    f.env.NATIVE_PUSH_ENABLED = 'false';
    assert.equal((await receiveNativePush(await f.request(), f.env)).status, 404);
});
