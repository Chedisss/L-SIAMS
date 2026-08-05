<?php
declare(strict_types=1);

namespace App\Services\Export;

/**
 * CSV writer.
 *
 * Two things worth noting. First, a UTF-8 BOM is emitted so Excel opens
 * Filipino names with diacritics correctly instead of mojibake. Second, cells
 * beginning with =, +, - or @ are prefixed with a single quote: without that, a
 * student's "name" could execute as a formula when the export is opened, which
 * is a real injection path in any system that lets users type free text.
 */
final class CsvWriter
{
    /**
     * @param list<string>                $headers
     * @param list<array<string|int,mixed>> $rows
     */
    public static function build(array $headers, array $rows, bool $withBom = true): string
    {
        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            return '';
        }

        if ($withBom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        if ($headers !== []) {
            fputcsv($handle, array_map([self::class, 'sanitize'], $headers), ',', '"', '\\');
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'sanitize'], array_values($row)), ',', '"', '\\');
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /** Neutralises spreadsheet formula injection while leaving the text readable. */
    public static function sanitize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $string = (string) $value;

        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $string;
        }

        return $string;
    }

    /**
     * @param  list<string> $headers
     * @return list<array<int,string>>
     */
    public static function parse(string $content, bool &$hasHeaders = true): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $handle  = fopen('php://temp', 'r+b');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $content);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $rows[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
        }

        fclose($handle);

        return $rows;
    }
}
