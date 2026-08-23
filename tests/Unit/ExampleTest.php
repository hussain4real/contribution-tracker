<?php

declare(strict_types=1);

test('the test runtime meets Pest 5 requirements', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80400);
});
