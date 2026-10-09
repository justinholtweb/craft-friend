<?php

namespace justinholtweb\friend\helpers;

use InvalidArgumentException;

/**
 * Reading and writing the pins CSV.
 *
 * A CSV of redirects is untrusted input twice over: on the way in, because it came out of another
 * plugin or a spreadsheet nobody has read line by line; and on the way out, because the first
 * thing anyone does with an export is open it in Excel, which runs a cell starting with `=` as a
 * formula. So the reader is capped, and the writer defuses formula cells.
 */
abstract class Csv
{
    /** The largest file the importer will read. A redirect map is text; 2 MB is tens of thousands of rows. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** The most data rows one import will take, so a request can't be held for minutes. */
    public const MAX_ROWS = 10000;

    /** Characters that make a spreadsheet treat a cell as a formula (or a DDE call). */
    private const FORMULA_LEADERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Read a CSV file into rows, keyed by their line number in the file.
     *
     * The delimiter is whichever of comma, semicolon or tab the first line uses most — Excel in
     * much of Europe saves "CSV" with semicolons. A UTF-8 byte-order mark is dropped, and a file
     * that is not UTF-8 is read as Windows-1252, which is what Excel writes when it isn't.
     *
     * @return array<int, string[]>
     * @throws InvalidArgumentException when the file is unreadable, too big or has too many rows
     */
    public static function read(string $path, int $maxBytes = self::MAX_BYTES, int $maxRows = self::MAX_ROWS): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The file could not be read.');
        }

        $size = filesize($path);

        if ($size === false || $size > $maxBytes) {
            throw new InvalidArgumentException(sprintf('The file is larger than %d KB.', intdiv($maxBytes, 1024)));
        }

        $content = (string)file_get_contents($path);

        return self::parse($content, $maxRows);
    }

    /**
     * @return array<int, string[]>
     */
    public static function parse(string $content, int $maxRows = self::MAX_ROWS): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = (string)array_key_first($counts);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new InvalidArgumentException('The file could not be read.');
        }

        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }

            if (count($rows) >= $maxRows + 1) {
                fclose($handle);

                throw new InvalidArgumentException(sprintf('The file has more than %d rows. Split it and import the parts.', $maxRows));
            }

            $rows[$line] = array_map(static fn($cell) => self::unguard(trim((string)$cell)), $cells);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Build a CSV document, defusing every cell a spreadsheet would run.
     *
     * @param string[] $header
     * @param array<int, array<int, string|int|null>> $rows
     */
    public static function write(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_map([self::class, 'guard'], $header), ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn($cell) => self::guard((string)$cell), $row), ',', '"', '');
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Prefix a formula-looking cell with an apostrophe, the convention every spreadsheet reads as
     * "this is text".
     */
    public static function guard(string $cell): string
    {
        if ($cell !== '' && in_array($cell[0], self::FORMULA_LEADERS, true)) {
            return "'" . $cell;
        }

        return $cell;
    }

    /**
     * Undo `guard()`, so an export imports back to exactly what it was.
     */
    public static function unguard(string $cell): string
    {
        if (strlen($cell) > 1 && $cell[0] === "'" && in_array($cell[1], self::FORMULA_LEADERS, true)) {
            return substr($cell, 1);
        }

        return $cell;
    }
}
