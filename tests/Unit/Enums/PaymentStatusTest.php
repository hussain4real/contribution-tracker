<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;

it('exposes payment status presentation metadata', function (
    PaymentStatus $status,
    string $label,
    string $color,
    string $badgeClasses,
    bool $isComplete,
    bool $requiresAttention,
) {
    expect($status->label())->toBe($label)
        ->and($status->color())->toBe($color)
        ->and($status->badgeClasses())->toBe($badgeClasses)
        ->and($status->isComplete())->toBe($isComplete)
        ->and($status->requiresAttention())->toBe($requiresAttention);
})->with([
    'paid' => [
        PaymentStatus::Paid,
        'Paid',
        'green',
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        true,
        false,
    ],
    'partial' => [
        PaymentStatus::Partial,
        'Partial',
        'yellow',
        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        false,
        true,
    ],
    'unpaid' => [
        PaymentStatus::Unpaid,
        'Unpaid',
        'gray',
        'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
        false,
        false,
    ],
    'overdue' => [
        PaymentStatus::Overdue,
        'Overdue',
        'red',
        'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        false,
        true,
    ],
]);
