<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;

it('labels transaction statuses', function (TransactionStatus $status, string $label) {
    expect($status->label())->toBe($label);
})->with([
    'pending' => [TransactionStatus::Pending, 'Pending'],
    'success' => [TransactionStatus::Success, 'Successful'],
    'failed' => [TransactionStatus::Failed, 'Failed'],
    'abandoned' => [TransactionStatus::Abandoned, 'Abandoned'],
]);

it('labels transaction types', function (TransactionType $type, string $label) {
    expect($type->label())->toBe($label);
})->with([
    'contribution' => [TransactionType::Contribution, 'Contribution'],
    'subscription' => [TransactionType::Subscription, 'Subscription'],
    'one time' => [TransactionType::OneTime, 'One Time'],
    'refund' => [TransactionType::Refund, 'Refund'],
]);
