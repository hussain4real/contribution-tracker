<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1ProductionMaintenance;

require_once __DIR__.'/../../deployment/d1-proof/autoload.php';

it('accepts a JSON maintenance task with string keys on PHP 8.5', function (): void {
    $task = ['id' => 'generate:2026-10:2', 'kind' => 'generate', 'family_id' => 2, 'cursor' => 0, 'at' => '2026-10-01T00:00:00.000Z', 'lease_token' => 'synthetic-lease'];

    expect(D1ProductionMaintenance::decodeTask(json_encode($task, JSON_THROW_ON_ERROR)))->toBe($task);
});

it('rejects non-object task fields and oversized input before task execution', function (string $json): void {
    expect(fn (): array => D1ProductionMaintenance::decodeTask($json))->toThrow(RuntimeException::class);
})->with(['null', 'true', '"text"', '[1,2]', '{"0":"numeric-key"}', str_repeat(' ', 8193)]);

it('rejects malformed maintenance JSON', function (): void {
    expect(fn (): array => D1ProductionMaintenance::decodeTask('{'))->toThrow(JsonException::class);
});
