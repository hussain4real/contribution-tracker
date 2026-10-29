<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1StagingStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
});

it('uses the standard S3 driver with private visibility for staging assets', function (): void {
    $configuration = D1StagingStorage::configuration('familyfunds-d1-staging-private', 'synthetic-access-key', 'synthetic-secret');
    $disk = app(FilesystemManager::class)->build($configuration);
    expect($disk)->toBeInstanceOf(FilesystemAdapter::class)
        ->and($disk->getConfig()['visibility'])->toBe('private')
        ->and($disk->getConfig()['root'])->toBe('private')
        ->and($disk->getConfig()['bucket'])->toBe('familyfunds-d1-staging-private');
});

it('refuses another bucket before creating a storage client', function (): void {
    expect(fn () => D1StagingStorage::configuration('production', 'synthetic-key', 'synthetic-secret'))
        ->toThrow(LogicException::class, 'isolated staging bucket');
});
