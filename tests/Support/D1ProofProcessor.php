<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\SQLiteProcessor;

class D1ProofProcessor extends SQLiteProcessor
{
    /** @param list<mixed> $values */
    public function processInsertGetId(Builder $query, mixed $sql, mixed $values, mixed $sequence = null): int
    {
        $column = $query->getGrammar()->wrap($sequence ?? 'id');
        $row = $query->getConnection()->selectOne($sql.' returning '.$column, $values);

        $id = is_object($row) ? ($row->{$sequence ?? 'id'} ?? null) : null;
        if (! is_int($id)) {
            throw new \RuntimeException('D1 insert did not return an integer identifier.');
        }

        return $id;
    }
}
