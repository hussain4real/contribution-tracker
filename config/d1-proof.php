<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('D1_PROOF_ENABLED', false),
    'url' => env('D1_PROOF_URL', ''),
    'token' => env('D1_PROOF_TOKEN', ''),
];
