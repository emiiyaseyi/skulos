<?php

namespace App\Services\Bulk;

/**
 * Outcome of a bulk import. Row numbers are reported as the user sees
 * them in Excel/Sheets: the header is row 1, so the first data row is 2.
 * That makes "Row 15: Admission Number already exists" point at the row
 * the user can actually click.
 */
class ImportResult
{
    /**
     * @param list<array{row: int, errors: array<string, list<string>>}> $errors
     */
    public function __construct(
        public readonly int $total,
        public readonly int $imported,
        public readonly array $errors,
        public readonly string $mode,
    ) {
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    public function failed(): int
    {
        return count($this->errors);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->total,
            'imported' => $this->imported,
            'failed' => $this->failed(),
            'mode' => $this->mode,
            'errors' => $this->errors,
        ];
    }
}
