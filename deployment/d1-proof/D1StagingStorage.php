<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use LogicException;

final class D1StagingStorage
{
    /** @return array<string, mixed> */
    public static function configuration(string $bucket, string $key, string $secret, string $prefix = 'private'): array
    {
        if (D1RuntimeProfile::production()) {
            D1RuntimeProfile::assertEnabled();
        }
        $expected = D1RuntimeProfile::production() ? D1RuntimeProfile::PRODUCTION_BUCKET : 'familyfunds-d1-staging-private';
        if ($bucket !== $expected || $key === '' || $secret === '' || ! in_array($prefix, ['private', 'public'], true)) {
            throw new LogicException('R2 storage requires the isolated staging bucket and credentials.');
        }

        return self::build($bucket, $key, $secret, $prefix);
    }

    /** @return array<string, mixed> */
    private static function build(string $bucket, string $key, string $secret, string $prefix): array
    {

        return [
            'driver' => 's3', 'key' => $key, 'secret' => $secret,
            'region' => 'auto', 'bucket' => $bucket, 'root' => $prefix,
            'endpoint' => 'https://40344948b73fa9da3008958fdc3faf12.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true, 'visibility' => 'private',
            'throw' => true, 'report' => false,
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ];
    }
}
