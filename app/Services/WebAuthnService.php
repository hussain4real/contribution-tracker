<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\User;
use CBOR\Decoder;
use CBOR\StringStream;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Key\Key;
use Illuminate\Support\Facades\Session;

class WebAuthnService
{
    /**
     * Generate registration options for creating a new passkey.
     *
     * @return array{
     *     challenge: string,
     *     rp: array{name: string, id: string},
     *     user: array{id: string, name: string, displayName: string},
     *     pubKeyCredParams: list<array{type: string, alg: int}>,
     *     authenticatorSelection: array{authenticatorAttachment: string, residentKey: string, requireResidentKey: bool, userVerification: string},
     *     timeout: int,
     *     attestation: string,
     *     excludeCredentials: list<array{id: string, type: string}>
     * }
     */
    public function generateRegistrationOptions(User $user): array
    {
        $challenge = random_bytes(32);

        Session::put('webauthn.challenge', base64_encode($challenge));
        Session::put('webauthn.user_id', $user->id);

        $excludeCredentials = $user->passkeys->map(fn (Passkey $passkey) => [
            'id' => $this->base64UrlEncode(base64_decode($passkey->credential_id)),
            'type' => 'public-key',
        ])->toArray();

        return [
            'challenge' => $this->base64UrlEncode($challenge),
            'rp' => [
                'name' => config('app.name'),
                'id' => $this->getRelyingPartyId(),
            ],
            'user' => [
                'id' => $this->base64UrlEncode($user->id),
                'name' => $user->email,
                'displayName' => $user->name,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => 'required',
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'excludeCredentials' => $excludeCredentials,
        ];
    }

    /**
     * Verify a registration response and store the credential.
     *
     * @param array{
     *     id: string,
     *     rawId: string,
     *     response: array{clientDataJSON: string, attestationObject: string},
     *     type: string,
     *     authenticatorAttachment?: string
     * } $credential
     */
    public function verifyRegistration(array $credential, string $passkeyName): Passkey
    {
        $challenge = Session::pull('webauthn.challenge');
        $userId = Session::pull('webauthn.user_id');

        if (! $challenge || ! $userId) {
            throw new \RuntimeException('No pending registration challenge found.');
        }

        $clientDataJSON = $this->base64UrlDecode($credential['response']['clientDataJSON']);
        $clientData = json_decode($clientDataJSON, true);

        $this->validateClientData($clientData, $challenge, 'webauthn.create');

        $attestationObject = $this->base64UrlDecode($credential['response']['attestationObject']);
        $attestation = $this->decodeAttestationObject($attestationObject);

        $authData = $this->parseAuthenticatorData($attestation['authData']);

        if (! ($authData['flags'] & 0x01)) {
            throw new \RuntimeException('User presence flag not set.');
        }

        if (! ($authData['flags'] & 0x04)) {
            throw new \RuntimeException('User verification flag not set.');
        }

        if (! isset($authData['attestedCredentialData'])) {
            throw new \RuntimeException('No attested credential data found.');
        }

        $credentialId = $authData['attestedCredentialData']['credentialId'];
        $publicKey = $authData['attestedCredentialData']['credentialPublicKey'];

        $user = User::findOrFail($userId);

        return $user->passkeys()->create([
            'name' => $passkeyName,
            'credential_id' => base64_encode($credentialId),
            'public_key' => base64_encode($publicKey),
            'aaguid' => $authData['attestedCredentialData']['aaguid'],
            'sign_count' => $authData['signCount'],
            'attachment_type' => $credential['authenticatorAttachment'] ?? null,
            'transports' => $credential['response']['transports'] ?? null,
        ]);
    }

    /**
     * Generate authentication options for passkey login.
     *
     * @return array{
     *     challenge: string,
     *     rpId: string,
     *     timeout: int,
     *     userVerification: string,
     *     allowCredentials: list<array{id: string, type: string}>
     * }
     */
    public function generateAuthenticationOptions(?User $user = null): array
    {
        $challenge = random_bytes(32);

        Session::put('webauthn.challenge', base64_encode($challenge));

        $allowCredentials = [];
        if ($user) {
            $allowCredentials = $user->passkeys->map(fn (Passkey $passkey) => [
                'id' => $this->base64UrlEncode(base64_decode($passkey->credential_id)),
                'type' => 'public-key',
                'transports' => $passkey->transports ?? [],
            ])->toArray();
        }

        return [
            'challenge' => $this->base64UrlEncode($challenge),
            'rpId' => $this->getRelyingPartyId(),
            'timeout' => 60000,
            'userVerification' => 'required',
            'allowCredentials' => $allowCredentials,
        ];
    }

    /**
     * Verify an authentication assertion and return the authenticated user.
     *
     * @param array{
     *     id: string,
     *     rawId: string,
     *     response: array{clientDataJSON: string, authenticatorData: string, signature: string, userHandle?: string},
     *     type: string
     * } $assertion
     */
    public function verifyAuthentication(array $assertion): User
    {
        $challenge = Session::pull('webauthn.challenge');

        if (! $challenge) {
            throw new \RuntimeException('No pending authentication challenge found.');
        }

        $credentialId = $this->base64UrlDecode($assertion['rawId']);

        $passkey = Passkey::where('credential_id', base64_encode($credentialId))->first();

        if (! $passkey) {
            throw new \RuntimeException('Passkey not found.');
        }

        $clientDataJSON = $this->base64UrlDecode($assertion['response']['clientDataJSON']);
        $clientData = json_decode($clientDataJSON, true);

        $this->validateClientData($clientData, $challenge, 'webauthn.get');

        $authenticatorData = $this->base64UrlDecode($assertion['response']['authenticatorData']);
        $authData = $this->parseAuthenticatorData($authenticatorData);

        if (! ($authData['flags'] & 0x01)) {
            throw new \RuntimeException('User presence flag not set.');
        }

        $signature = $this->base64UrlDecode($assertion['response']['signature']);

        $this->verifySignature(
            base64_decode($passkey->public_key),
            $authenticatorData,
            $clientDataJSON,
            $signature
        );

        if ($authData['signCount'] > 0 || $passkey->sign_count > 0) {
            if ($authData['signCount'] <= $passkey->sign_count) {
                throw new \RuntimeException('Possible cloned authenticator detected.');
            }
        }

        $passkey->update([
            'sign_count' => $authData['signCount'],
            'last_used_at' => now(),
        ]);

        return $passkey->user;
    }

    /**
     * Validate the client data from a WebAuthn response.
     *
     * @param  array<string, mixed>  $clientData
     */
    protected function validateClientData(array $clientData, string $challenge, string $expectedType): void
    {
        if ($clientData['type'] !== $expectedType) {
            throw new \RuntimeException('Invalid client data type.');
        }

        $expectedChallenge = $this->base64UrlEncode(base64_decode($challenge));
        if ($clientData['challenge'] !== $expectedChallenge) {
            throw new \RuntimeException('Challenge mismatch.');
        }

        $expectedOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
        if ($clientData['origin'] !== $expectedOrigin) {
            throw new \RuntimeException('Origin mismatch.');
        }
    }

    /**
     * Decode a CBOR-encoded attestation object.
     *
     * @return array{fmt: string, attStmt: array<string, mixed>, authData: string}
     */
    protected function decodeAttestationObject(string $attestationObject): array
    {
        $decoder = Decoder::create();
        $stream = StringStream::create($attestationObject);
        $decoded = $decoder->decode($stream);

        $map = $decoded->normalize();

        return [
            'fmt' => $map['fmt'],
            'attStmt' => $map['attStmt'] ?? [],
            'authData' => $map['authData'],
        ];
    }

    /**
     * Parse the authenticator data binary format.
     *
     * @return array{rpIdHash: string, flags: int, signCount: int, attestedCredentialData?: array{aaguid: string, credentialId: string, credentialPublicKey: string}}
     */
    protected function parseAuthenticatorData(string $authData): array
    {
        $rpIdHash = substr($authData, 0, 32);
        $flags = ord(substr($authData, 32, 1));
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        $result = [
            'rpIdHash' => bin2hex($rpIdHash),
            'flags' => $flags,
            'signCount' => $signCount,
        ];

        if ($flags & 0x40) {
            $aaguid = substr($authData, 37, 16);
            $credentialIdLength = unpack('n', substr($authData, 53, 2))[1];
            $credentialId = substr($authData, 55, $credentialIdLength);

            $coseKeyBytes = substr($authData, 55 + $credentialIdLength);

            $result['attestedCredentialData'] = [
                'aaguid' => $this->formatUuid($aaguid),
                'credentialId' => $credentialId,
                'credentialPublicKey' => $coseKeyBytes,
            ];
        }

        return $result;
    }

    /**
     * Verify the signature of a WebAuthn assertion.
     */
    protected function verifySignature(string $publicKeyBytes, string $authenticatorData, string $clientDataJSON, string $signature): void
    {
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedData = $authenticatorData.$clientDataHash;

        $decoder = Decoder::create();
        $stream = StringStream::create($publicKeyBytes);
        $coseKey = $decoder->decode($stream)->normalize();

        $keyType = (int) ($coseKey[1] ?? $coseKey['1'] ?? 0);
        $algorithm = (int) ($coseKey[3] ?? $coseKey['3'] ?? 0);

        if ($algorithm === -7 || $keyType === 2) {
            $this->verifyEcdsaSignature($coseKey, $signedData, $signature);
        } elseif ($algorithm === -257 || $keyType === 3) {
            $this->verifyRsaSignature($coseKey, $signedData, $signature);
        } else {
            throw new \RuntimeException('Unsupported key algorithm.');
        }
    }

    /**
     * Verify an ECDSA (ES256) signature.
     *
     * @param  array<int|string, mixed>  $coseKey
     */
    protected function verifyEcdsaSignature(array $coseKey, string $signedData, string $signature): void
    {
        $x = $coseKey[-2] ?? $coseKey['-2'];
        $y = $coseKey[-3] ?? $coseKey['-3'];

        $derPublicKey = $this->ecPublicKeyToDer($x, $y);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($derPublicKey), 64, "\n")."-----END PUBLIC KEY-----\n";

        $derSignature = $this->ecSignatureToDer($signature);

        $result = openssl_verify($signedData, $derSignature, $pem, OPENSSL_ALGO_SHA256);

        if ($result !== 1) {
            $opensslError = openssl_error_string() ?: 'none';

            throw new \RuntimeException("ECDSA signature verification failed (openssl_verify={$result}, sigLen=".strlen($signature).', derLen='.strlen($derSignature).", openssl_error={$opensslError}).");
        }
    }

    /**
     * Verify an RSA (RS256) signature.
     *
     * @param  array<int|string, mixed>  $coseKey
     */
    protected function verifyRsaSignature(array $coseKey, string $signedData, string $signature): void
    {
        $n = $coseKey[-1] ?? $coseKey['-1'];
        $e = $coseKey[-2] ?? $coseKey['-2'];

        $derPublicKey = $this->rsaPublicKeyToDer($n, $e);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($derPublicKey), 64, "\n")."-----END PUBLIC KEY-----\n";

        $result = openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256);

        if ($result !== 1) {
            throw new \RuntimeException('RSA signature verification failed.');
        }
    }

    /**
     * Convert EC public key coordinates to DER format.
     */
    protected function ecPublicKeyToDer(string $x, string $y): string
    {
        $uncompressedPoint = "\x04".$x.$y;

        $oidP256 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $oidEcPublicKey = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";

        $algorithmIdentifier = "\x30".chr(strlen($oidEcPublicKey) + strlen($oidP256)).$oidEcPublicKey.$oidP256;
        $bitString = "\x03".chr(strlen($uncompressedPoint) + 1)."\x00".$uncompressedPoint;

        return "\x30".chr(strlen($algorithmIdentifier) + strlen($bitString)).$algorithmIdentifier.$bitString;
    }

    /**
     * Convert WebAuthn EC signature to DER format.
     */
    protected function ecSignatureToDer(string $signature): string
    {
        if (strlen($signature) === 64) {
            return $this->rawEcSignatureToDer($signature);
        }

        if (ord($signature[0]) === 0x30) {
            return $signature;
        }

        throw new \RuntimeException('Unexpected ECDSA signature format (length='.strlen($signature).').');
    }

    /**
     * Convert a raw EC signature (r || s concatenation) to DER format.
     */
    protected function rawEcSignatureToDer(string $signature): string
    {
        $r = substr($signature, 0, 32);
        $s = substr($signature, 32, 32);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if (strlen($r) === 0 || ord($r[0]) > 0x7F) {
            $r = "\x00".$r;
        }
        if (strlen($s) === 0 || ord($s[0]) > 0x7F) {
            $s = "\x00".$s;
        }

        $r = "\x02".chr(strlen($r)).$r;
        $s = "\x02".chr(strlen($s)).$s;

        return "\x30".chr(strlen($r) + strlen($s)).$r.$s;
    }

    /**
     * Convert RSA public key components to DER format.
     */
    protected function rsaPublicKeyToDer(string $n, string $e): string
    {
        $nEncoded = $this->derEncodeInteger($n);
        $eEncoded = $this->derEncodeInteger($e);

        $rsaPublicKey = $nEncoded.$eEncoded;
        $rsaPublicKey = "\x30".$this->derEncodeLength(strlen($rsaPublicKey)).$rsaPublicKey;

        $bitString = "\x00".$rsaPublicKey;
        $bitString = "\x03".$this->derEncodeLength(strlen($bitString)).$bitString;

        $oidRsa = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        $outer = $oidRsa.$bitString;

        return "\x30".$this->derEncodeLength(strlen($outer)).$outer;
    }

    /**
     * DER-encode an integer value.
     */
    protected function derEncodeInteger(string $value): string
    {
        if (ord($value[0]) > 0x7F) {
            $value = "\x00".$value;
        }

        return "\x02".$this->derEncodeLength(strlen($value)).$value;
    }

    /**
     * DER-encode a length value.
     */
    protected function derEncodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF).$bytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /**
     * Get the relying party ID (domain) for WebAuthn.
     */
    protected function getRelyingPartyId(): string
    {
        return parse_url(config('app.url'), PHP_URL_HOST);
    }

    /**
     * Format raw bytes as a UUID string.
     */
    protected function formatUuid(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Base64URL encode.
     */
    public function base64UrlEncode(string|int $data): string
    {
        if (is_int($data)) {
            $data = pack('P', $data);
        }

        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decode.
     */
    public function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
