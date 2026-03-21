<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Passkey;
use CBOR\Decoder;
use CBOR\StringStream;

$p = Passkey::first();
if (! $p) {
    echo "No passkeys found\n";
    exit;
}

echo 'credential_id length: '.strlen(base64_decode($p->credential_id))."\n";
echo 'public_key base64 length: '.strlen($p->public_key)."\n";

$pkBytes = base64_decode($p->public_key);
echo 'public_key raw length: '.strlen($pkBytes)."\n";

$decoder = Decoder::create();
$stream = StringStream::create($pkBytes);
$coseKey = $decoder->decode($stream)->normalize();

echo 'COSE key type: '.gettype($coseKey)."\n";
echo 'COSE key count: '.count($coseKey)."\n";
echo 'Keys: '.json_encode(array_keys($coseKey))."\n";

foreach ($coseKey as $k => $v) {
    $kType = gettype($k);
    if (is_string($v) && strlen($v) > 0 && ! ctype_print($v)) {
        echo "Key $k ($kType): bytes[".strlen($v).'] = '.bin2hex($v)."\n";
    } else {
        $vType = gettype($v);
        echo "Key $k ($kType): $v ($vType)\n";
    }
}

// Test EC key generation
$x = $coseKey[-2] ?? $coseKey['-2'] ?? null;
$y = $coseKey[-3] ?? $coseKey['-3'] ?? null;

if ($x === null || $y === null) {
    echo "\nERROR: Could not extract x or y coordinates\n";
    echo "Trying string keys...\n";
    $x = $coseKey['-2'] ?? null;
    $y = $coseKey['-3'] ?? null;
    echo 'x from string key: '.($x !== null ? 'found ('.strlen($x).' bytes)' : 'NOT FOUND')."\n";
    echo 'y from string key: '.($y !== null ? 'found ('.strlen($y).' bytes)' : 'NOT FOUND')."\n";
    exit(1);
}

echo "\nx length: ".strlen($x)."\n";
echo 'y length: '.strlen($y)."\n";

// Build PEM
$uncompressedPoint = "\x04".$x.$y;
$oidP256 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
$oidEcPublicKey = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
$algorithmIdentifier = "\x30".chr(strlen($oidEcPublicKey) + strlen($oidP256)).$oidEcPublicKey.$oidP256;
$bitString = "\x03".chr(strlen($uncompressedPoint) + 1)."\x00".$uncompressedPoint;
$derPublicKey = "\x30".chr(strlen($algorithmIdentifier) + strlen($bitString)).$algorithmIdentifier.$bitString;
$pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($derPublicKey), 64, "\n")."-----END PUBLIC KEY-----\n";

echo "\nGenerated PEM:\n$pem\n";

// Try loading the PEM key with OpenSSL
$key = openssl_pkey_get_public($pem);
if ($key === false) {
    echo "ERROR: OpenSSL could not parse the PEM key\n";
    while ($msg = openssl_error_string()) {
        echo "OpenSSL error: $msg\n";
    }
} else {
    $details = openssl_pkey_get_details($key);
    echo 'OpenSSL key type: '.$details['type']."\n";
    echo 'OpenSSL key bits: '.$details['bits']."\n";
    echo "Key loaded successfully!\n";
}

// Round-trip test: generate a key, sign, verify through our pipeline
echo "\n=== Round-trip test ===\n";

// Generate a test EC P-256 key
$config = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
$testKey = openssl_pkey_new($config);
$testDetails = openssl_pkey_get_details($testKey);
$testX = str_pad($testDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$testY = str_pad($testDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

echo 'Test key x length: '.strlen($testX)."\n";
echo 'Test key y length: '.strlen($testY)."\n";

// Build PEM from x,y using our function
$testUncompressed = "\x04".$testX.$testY;
$testAlgId = "\x30".chr(strlen($oidEcPublicKey) + strlen($oidP256)).$oidEcPublicKey.$oidP256;
$testBitStr = "\x03".chr(strlen($testUncompressed) + 1)."\x00".$testUncompressed;
$testDer = "\x30".chr(strlen($testAlgId) + strlen($testBitStr)).$testAlgId.$testBitStr;
$testPem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($testDer), 64, "\n")."-----END PUBLIC KEY-----\n";

// Sign some test data
$testData = random_bytes(64);
openssl_sign($testData, $testSig, $testKey, OPENSSL_ALGO_SHA256);
echo 'OpenSSL DER signature length: '.strlen($testSig)."\n";

// Verify with our generated PEM
$verifyResult = openssl_verify($testData, $testSig, $testPem, OPENSSL_ALGO_SHA256);
echo "Direct DER verify result: $verifyResult\n";

// Convert DER sig to raw, then back to DER (simulating WebAuthn flow)
// Parse DER to get r,s
$pos = 2; // skip SEQUENCE tag + length
$rLen = ord($testSig[$pos + 1]);
$rBytes = substr($testSig, $pos + 2, $rLen);
$pos = $pos + 2 + $rLen;
$sLen = ord($testSig[$pos + 1]);
$sBytes = substr($testSig, $pos + 2, $sLen);

// Strip leading zeros and pad to 32 bytes (raw format)
$rRaw = str_pad(ltrim($rBytes, "\x00"), 32, "\x00", STR_PAD_LEFT);
$sRaw = str_pad(ltrim($sBytes, "\x00"), 32, "\x00", STR_PAD_LEFT);
$rawSig = $rRaw.$sRaw;
echo 'Raw signature length: '.strlen($rawSig)."\n";

// Convert raw back to DER using our ecSignatureToDer logic
$halfLen = intdiv(strlen($rawSig), 2);
$r = substr($rawSig, 0, $halfLen);
$s = substr($rawSig, $halfLen);
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
$reconvertedDer = "\x30".chr(strlen($r) + strlen($s)).$r.$s;

echo 'Reconverted DER length: '.strlen($reconvertedDer)."\n";
echo 'DER signatures match: '.($testSig === $reconvertedDer ? 'YES' : 'NO')."\n";
if ($testSig !== $reconvertedDer) {
    echo 'Original DER: '.bin2hex($testSig)."\n";
    echo 'Reconverted:  '.bin2hex($reconvertedDer)."\n";
}

$roundTripResult = openssl_verify($testData, $reconvertedDer, $testPem, OPENSSL_ALGO_SHA256);
echo "Round-trip verify result: $roundTripResult\n";

// Now test: what if signature is already DER and we try to "convert" it as raw?
echo "\n=== Testing DER mis-detection ===\n";
echo 'DER sig length: '.strlen($testSig).' > 70: '.(strlen($testSig) > 70 ? 'true' : 'false')."\n";
echo 'First byte is 0x30: '.(ord($testSig[0]) === 0x30 ? 'true' : 'false')."\n";
$wouldDetectAsDer = strlen($testSig) > 70 && ord($testSig[0]) === 0x30;
echo 'Would detect as DER (current code): '.($wouldDetectAsDer ? 'YES' : 'NO - BUG!')."\n";

if (! $wouldDetectAsDer && ord($testSig[0]) === 0x30) {
    // The code would incorrectly try to convert DER as raw
    echo 'CONFIRMED BUG: DER signature of length '.strlen($testSig)." would be treated as raw!\n";

    // Show what would happen
    $badHalfLen = intdiv(strlen($testSig), 2);
    $badR = substr($testSig, 0, $badHalfLen);
    $badS = substr($testSig, $badHalfLen);
    $badR = ltrim($badR, "\x00");
    $badS = ltrim($badS, "\x00");
    if (ord($badR[0]) > 0x7F) {
        $badR = "\x00".$badR;
    }
    if (ord($badS[0]) > 0x7F) {
        $badS = "\x00".$badS;
    }
    $badR = "\x02".chr(strlen($badR)).$badR;
    $badS = "\x02".chr(strlen($badS)).$badS;
    $badDer = "\x30".chr(strlen($badR) + strlen($badS)).$badR.$badS;

    $badResult = openssl_verify($testData, $badDer, $testPem, OPENSSL_ALGO_SHA256);
    echo "Verify with corrupted DER: $badResult (expected 0 or -1)\n";
}
