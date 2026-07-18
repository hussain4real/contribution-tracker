<?php

declare(strict_types=1);

namespace App\Support;

class CsvCellSanitizer
{
    public function sanitize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $cell = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

        if (preg_match('/^[=+\-@\t\r]/u', $cell) === 1) {
            return "'{$cell}";
        }

        return $cell;
    }
}
