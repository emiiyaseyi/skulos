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
     * Tidies a row before anything looks at it (2026-08-16). Real
     * spreadsheets arrive with trailing spaces, doubled spaces inside
     * names, non-breaking spaces pasted from a website and invisible
     * zero-width characters from Word. Each turns an otherwise good row
     * into a mysterious failure, or worse imports a student whose name
     * silently contains a character nobody can type when searching for
     * them later.
     *
     * Deliberately conservative: whitespace and invisible characters
     * only. Nothing here changes capitalisation or spelling, because
     * "correcting" a name is how McDonald becomes Mcdonald against a
     * family's wishes.
     */
    public function normalise(array $row): array
    {
        foreach ($row as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $clean = preg_replace('/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $value) ?? $value;
            $clean = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $clean) ?? $clean;
            $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

            $row[$key] = trim($clean);
        }

        return $row;
    }

    /**
     * A dry run: says exactly what WOULD happen without writing
     * anything, so an admin sees the good and bad rows before deciding
     * (direct instruction 2026-08-16).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function preview(array $rows): array
    {
        $duplicates = $this->findInFileDuplicates($rows);
        $good = [];
        $bad = [];

        foreach ($rows as $index => $original) {
            $row = $this->normalise($original);
            $rowNumber = $index + self::FIRST_DATA_ROW;

            $validator = Validator::make($row, $this->rules($row));
            $errors = $validator->fails() ? $validator->errors()->toArray() : [];

            $duplicateOf = [];
            foreach ($this->uniqueBy() as $column) {
                if (isset($duplicates[$column][$index])) {
                    $duplicateOf[] = str_replace('_', ' ', $column).' appears more than once in this file';
                }
            }

            // Flagged apart from a plain validation failure: "already on
            // record" is a different decision for an admin than "this
            // row is malformed".
            $existing = $this->existingConflicts($row);

            $entry = [
                'row' => $rowNumber,
                'values' => $row,
                'changed' => $this->changedKeys($original, $row),
                'errors' => $errors,
                'duplicate_in_file' => $duplicateOf,
                'already_on_record' => $existing,
            ];

            if ($errors === [] && $duplicateOf === [] && $existing === []) {
                $good[] = $entry;
            } else {
                $bad[] = $entry;
            }
        }

        return ['good' => $good, 'bad' => $bad, 'total' => count($rows)];
    }

    /** Which fields normalising actually touched, so the preview can say so honestly. */
    protected function changedKeys(array $original, array $normalised): array
    {
        $changed = [];
        foreach ($normalised as $key => $value) {
            if (isset($original[$key]) && is_string($original[$key]) && $original[$key] !== $value) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /** Rows clashing with something already imported, from the importer's own unique keys. */
    protected function existingConflicts(array $row): array
    {
        $conflicts = [];
        foreach ($this->uniqueBy() as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '' && $this->existsAlready($column, $value)) {
                $conflicts[] = str_replace('_', ' ', $column).' "'.$value.'" is already on record';
            }
        }

        return $conflicts;
    }

    /** Subclasses with a model to check against override this. */
    protected function existsAlready(string $column, string $value): bool
    {
        return false;
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
            // The same tidy-up the preview showed, so what is imported
            // is exactly what the admin approved, not the raw row.
            $row = $this->normalise($row);
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
