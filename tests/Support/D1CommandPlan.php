<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/** A bounded, optimistic command; never an interactive D1 transaction. */
final readonly class D1CommandPlan
{
    /**
     * @param  list<array{sql: string, params: list<scalar|null>}>  $queries
     * @param  class-string<Model>|null  $modelClass
     */
    public function __construct(
        public int $revision,
        public array $queries,
        public ?string $modelClass,
        public int|string|null $modelId,
        public ?Throwable $error = null,
    ) {}
}
