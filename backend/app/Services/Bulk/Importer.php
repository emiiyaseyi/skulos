<?php

namespace App\Services\Bulk;

use App\Services\Auditing\Recorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Base class every module's bulk importer extends (tracker §4.3: bulk
 * import with row-level validation, not "Import Failed").
 *
 * Modes:
 *  strict  — nothing is written unless every row validates (default; the
 *            whole batch runs in one transaction)
 *  partial — valid rows are written, invalid rows are reported back
 *
 * Subclasses declare columns(), rules(), persist(), and optionally
 * uniqueBy() for in-file duplicate detection (e.g. the same admission
 * number appearing twice in one upload).
 */
abstract class Importer
{
    public const MODE_STRICT = 'strict';
    public const MODE_PARTIAL = 'partial';

    /** Spreadsheet row number of the first data row (row 1 is the header). */
    protected const FIRST_DATA_ROW = 2;

    /**
     * Column name => human label, in template order.
     *
     * @return array<string, string>
     */
    abstract public function columns(): array;

    /**
     * Laravel validation rules for one row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    abstract protected function rules(array $row): array;

    /**
     * Write one validated row.
     *
     * @param array<string, mixed> $row
     */
    abstract protected function persist(array $row): void;

    /**
     * Columns that must not repeat within the uploaded file itself.
     *
     * @return list<string>
     */
    protected function uniqueBy(): array
    {
        return [];
    }

    /**
     * Which database the import writes to ("tenant" for school data).
     */
    protected function connection(): string
    {
        return config('tenancy.connection');
    }

    /**
     * Short name used in audit log actions, e.g. "students".
     */
    abstract protected function auditName(): string;

    /**
     * Downloadable CSV template: the header row plus nothing else.
     */
    public function template(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($this->columns()));
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function import(array $rows, string $mode = self::MODE_STRICT): ImportResult
    {
        if (! in_array($mode, [self::MODE_STRICT, self::MODE_PARTIAL], true)) {
            throw new InvalidArgumentException("Invalid import mode [{$mode}].");
        }

        $errors = [];
        $valid = [];

        $duplicates = $this->findInFileDuplicates($rows);

        foreach ($rows as $index => $row) {
            $rowNumber = $index + self::FIRST_DATA_ROW;
            $rowErrors = [];

            $validator = Validator::make($row, $this->rules($row));
            if ($validator->fails()) {
                $rowErrors = $validator->errors()->toArray();
            }

            foreach ($this->uniqueBy() as $column) {
                if (isset($duplicates[$column][$index])) {
                    $rowErrors[$column][] = ucfirst(str_replace('_', ' ', $column))
                        .' appears more than once in this file.';
                }
            }

            if ($rowErrors !== []) {
                $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors];
            } else {
                $valid[$index] = $row;
            }
        }

        $imported = 0;

        if ($mode === self::MODE_STRICT && $errors !== []) {
            // Nothing is written; the caller gets every row-level error.
            $result = new ImportResult(count($rows), 0, $errors, $mode);
        } else {
            DB::connection($this->connection())->transaction(function () use ($valid, &$imported) {
                foreach ($valid as $row) {
                    $this->persist($row);
                    $imported++;
                }
            });

            $result = new ImportResult(count($rows), $imported, $errors, $mode);
        }

        app(Recorder::class)->record(
            "bulk.import.{$this->auditName()}",
            null,
            [],
            $result->toArray()['errors'] === [] ? ['imported' => $result->imported, 'total' => $result->total]
                : ['imported' => $result->imported, 'total' => $result->total, 'failed' => $result->failed()],
            $this->connection(),
        );

        return $result;
    }

    /**
     * Map of column => [row index => true] for values appearing 2+ times.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<int, bool>>
     */
    protected function findInFileDuplicates(array $rows): array
    {
        $flagged = [];

        foreach ($this->uniqueBy() as $column) {
            $seen = [];

            foreach ($rows as $index => $row) {
                $value = $row[$column] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                $key = mb_strtolower((string) $value);
                $seen[$key][] = $index;
            }

            foreach ($seen as $indices) {
                if (count($indices) > 1) {
                    foreach ($indices as $index) {
                        $flagged[$column][$index] = true;
                    }
                }
            }
        }

        return $flagged;
    }
}
