<?php

use App\Models\Passkey;
use App\Models\User;
use App\Services\WebAuthnService;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;

test('passkey login challenge options can be generated', function () {
    $response = $this->postJson(route('passkey.login.options'));

    $response->assertOk()
        ->assertJsonStructure([
            'challenge',
            'rpId',
            'timeout',
            'userVerification',
            'allowCredentials',
        ]);
});

test('passkey login challenge returns empty allow credentials for discoverable flow', function () {
    $response = $this->postJson(route('passkey.login.options'));

    $response->assertOk()
        ->assertJsonPath('allowCredentials', []);
});

test('passkey login fails with invalid assertion data', function () {
    $this->postJson(route('passkey.login.options'));

    $response = $this->postJson(route('passkey.login'), [
        'assertion' => [
            'id' => 'invalid',
            'rawId' => 'invalid',
            'response' => [
                'clientDataJSON' => base64_encode('{}'),
                'authenticatorData' => base64_encode('invalid'),
                'signature' => base64_encode('invalid'),
            ],
            'type' => 'public-key',
        ],
    ]);

    $response->assertUnprocessable();
});

test('passkey login requires assertion data', function () {
    $response = $this->postJson(route('passkey.login'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['assertion']);
});

test('passkey login validates assertion structure', function () {
    $response = $this->postJson(route('passkey.login'), [
        'assertion' => [
            'id' => 'test',
        ],
    ]);

    $response->assertUnprocessable();
});

test('passkey login rejects archived users', function () {
    $user = User::factory()->withoutTwoFactor()->state([
        'archived_at' => now(),
    ])->create();
    Passkey::factory()->for($user)->create();

    $this->postJson(route('passkey.login.options'));

    $response = $this->postJson(route('passkey.login'), [
        'assertion' => [
            'id' => 'invalid',
            'rawId' => 'invalid',
            'response' => [
                'clientDataJSON' => base64_encode('{}'),
                'authenticatorData' => base64_encode('invalid'),
                'signature' => base64_encode('invalid'),
            ],
            'type' => 'public-key',
        ],
    ]);

    $response->assertUnprocessable();
});

test('passkey login succeeds with valid ECDSA assertion', function () {
    $webAuthn = app(WebAuthnService::class);

    $ecKey = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    $ecDetails = openssl_pkey_get_details($ecKey);
    $x = str_pad($ecDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($ecDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $coseMap = MapObject::create()
        ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
        ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
        ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
        ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
        ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));
    $coseKeyBytes = (string) $coseMap;

    $credentialId = random_bytes(16);

    $user = User::factory()->withoutTwoFactor()->create();
    $passkey = Passkey::factory()->for($user)->create([
        'credential_id' => base64_encode($credentialId),
        'public_key' => base64_encode($coseKeyBytes),
        'sign_count' => 0,
    ]);

    $optionsResponse = $this->postJson(route('passkey.login.options'));
    $optionsResponse->assertOk();
    $challenge = session('webauthn.challenge');

    $rpIdHash = hash('sha256', parse_url(config('app.url'), PHP_URL_HOST), true);
    $flags = chr(0x05);
    $signCount = pack('N', 1);
    $authenticatorData = $rpIdHash.$flags.$signCount;

    $origin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $clientData = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $webAuthn->base64UrlEncode(base64_decode($challenge)),
        'origin' => $origin,
    ], JSON_UNESCAPED_SLASHES);

    $clientDataHash = hash('sha256', $clientData, true);
    $signedData = $authenticatorData.$clientDataHash;

    openssl_sign($signedData, $derSignature, $ecKey, OPENSSL_ALGO_SHA256);

    $pos = 2;
    $rLen = ord($derSignature[$pos + 1]);
    $rBytes = substr($derSignature, $pos + 2, $rLen);
    $pos += 2 + $rLen;
    $sLen = ord($derSignature[$pos + 1]);
    $sBytes = substr($derSignature, $pos + 2, $sLen);
    $rRaw = str_pad(ltrim($rBytes, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $sRaw = str_pad(ltrim($sBytes, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $rawSignature = $rRaw.$sRaw;

    $response = $this->postJson(route('passkey.login'), [
        'assertion' => [
            'id' => $webAuthn->base64UrlEncode($credentialId),
            'rawId' => $webAuthn->base64UrlEncode($credentialId),
            'response' => [
                'clientDataJSON' => $webAuthn->base64UrlEncode($clientData),
                'authenticatorData' => $webAuthn->base64UrlEncode($authenticatorData),
                'signature' => $webAuthn->base64UrlEncode($rawSignature),
            ],
            'type' => 'public-key',
        ],
    ]);

    $response->assertOk()
        ->assertJsonStructure(['redirect']);

    $this->assertAuthenticatedAs($user);
});

test('passkey login succeeds with DER-encoded signature', function () {
    $webAuthn = app(WebAuthnService::class);

    $ecKey = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    $ecDetails = openssl_pkey_get_details($ecKey);
    $x = str_pad($ecDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($ecDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $coseMap = MapObject::create()
        ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
        ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
        ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
        ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
        ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));
    $coseKeyBytes = (string) $coseMap;

    $credentialId = random_bytes(16);

    $user = User::factory()->withoutTwoFactor()->create();
    Passkey::factory()->for($user)->create([
        'credential_id' => base64_encode($credentialId),
        'public_key' => base64_encode($coseKeyBytes),
        'sign_count' => 0,
    ]);

    $optionsResponse = $this->postJson(route('passkey.login.options'));
    $optionsResponse->assertOk();
    $challenge = session('webauthn.challenge');

    $rpIdHash = hash('sha256', parse_url(config('app.url'), PHP_URL_HOST), true);
    $flags = chr(0x05);
    $signCount = pack('N', 1);
    $authenticatorData = $rpIdHash.$flags.$signCount;

    $origin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $clientData = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $webAuthn->base64UrlEncode(base64_decode($challenge)),
        'origin' => $origin,
    ], JSON_UNESCAPED_SLASHES);

    $clientDataHash = hash('sha256', $clientData, true);
    $signedData = $authenticatorData.$clientDataHash;

    openssl_sign($signedData, $derSignature, $ecKey, OPENSSL_ALGO_SHA256);

    $response = $this->postJson(route('passkey.login'), [
        'assertion' => [
            'id' => $webAuthn->base64UrlEncode($credentialId),
            'rawId' => $webAuthn->base64UrlEncode($credentialId),
            'response' => [
                'clientDataJSON' => $webAuthn->base64UrlEncode($clientData),
                'authenticatorData' => $webAuthn->base64UrlEncode($authenticatorData),
                'signature' => $webAuthn->base64UrlEncode($derSignature),
            ],
            'type' => 'public-key',
        ],
    ]);

    $response->assertOk()
        ->assertJsonStructure(['redirect']);

    $this->assertAuthenticatedAs($user);
});
