<?php

namespace App\Services\Bulk;

use InvalidArgumentException;

/**
 * Parses CSV content into rows keyed by the header line. No dependency on
 * an external package; handles UTF-8 BOMs, trims cells, and skips fully
 * blank lines so hand-edited Excel exports import cleanly.
 */
class CsvReader
{
    /**
     * @return list<array<string, string|null>>
     */
    public function rows(string $csv): array
    {
        $csv = $this->stripBom($csv);
        $lines = preg_split("/\r\n|\n|\r/", $csv) ?: [];

        $header = null;
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map(
                fn ($cell) => ($cell = trim((string) $cell)) === '' ? null : $cell,
                str_getcsv($line),
            );

            if ($header === null) {
                $header = array_map(
                    fn ($cell) => strtolower(trim((string) $cell)),
                    $cells,
                );

                if (count(array_filter($header)) === 0) {
                    throw new InvalidArgumentException('The file has no header row.');
                }

                continue;
            }

            // Ignore trailing blank columns Excel likes to add.
            $cells = array_slice($cells, 0, count($header));
            $cells = array_pad($cells, count($header), null);

            if (count(array_filter($cells, fn ($c) => $c !== null)) === 0) {
                continue;
            }

            $rows[] = array_combine($header, $cells);
        }

        if ($header === null) {
            throw new InvalidArgumentException('The file is empty.');
        }

        return $rows;
    }

    protected function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }
}
