<?php
declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;
use ZipArchive;

/**
 * Minimal OOXML (.xlsx) writer built on ext-zip.
 *
 * Written by hand rather than pulling in PhpSpreadsheet because this system is
 * deployed on an isolated LAN where `composer install` may not be possible, and
 * a school's IT staff should be able to redeploy it from a USB stick. The
 * output is a valid single-sheet workbook with a frozen, styled header row —
 * which is the whole of what the reports need.
 */
final class XlsxWriter
{
    /**
     * @param  list<string>                  $headers
     * @param  list<array<string|int,mixed>> $rows
     * @return string raw .xlsx bytes
     */
    public static function build(array $headers, array $rows, string $sheetName = 'Sheet1', ?string $title = null): string
    {
        // An .xlsx file is a ZIP of XML parts, so this writer cannot work
        // without ext-zip. Saying so beats "Class ZipArchive not found" in a
        // log somewhere, which tells whoever clicked Export nothing at all.
        // PDF and CSV do not need the extension and remain available.
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Excel export needs the PHP "zip" extension, which is not enabled. '
                . 'Enable extension=zip in php.ini and restart the web server, '
                . 'or export as PDF or CSV instead.'
            );
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'lsiams_xlsx_');

        if ($tmpFile === false) {
            throw new RuntimeException('Could not create a temporary file for the workbook.');
        }

        $zip = new ZipArchive();

        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new RuntimeException('Could not create the workbook archive.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('docProps/app.xml', self::appProps());
        $zip->addFromString('docProps/core.xml', self::coreProps($title ?? $sheetName));
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($headers, $rows));

        $zip->close();

        $content = (string) file_get_contents($tmpFile);
        @unlink($tmpFile);

        return $content;
    }

    /**
     * @param list<string>                  $headers
     * @param list<array<string|int,mixed>> $rows
     */
    private static function sheet(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Freeze the header so a 500-row roster stays navigable.
        if ($headers !== []) {
            $xml .= '<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
                . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>';

            $xml .= '<cols>';
            foreach ($headers as $index => $header) {
                $width = max(12, min(45, mb_strlen($header) + 6));
                $xml  .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $index + 1, $width);
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        $rowNumber = 1;

        if ($headers !== []) {
            $xml .= self::row($rowNumber++, $headers, 1);
        }

        foreach ($rows as $row) {
            $xml .= self::row($rowNumber++, array_values($row), 0);
        }

        $xml .= '</sheetData>';

        if ($headers !== []) {
            $xml .= sprintf(
                '<autoFilter ref="A1:%s%d"/>',
                self::columnName(count($headers)),
                max(1, count($rows) + 1)
            );
        }

        return $xml . '</worksheet>';
    }

    /** @param list<mixed> $cells */
    private static function row(int $rowNumber, array $cells, int $styleIndex): string
    {
        $xml = sprintf('<row r="%d">', $rowNumber);

        foreach ($cells as $index => $value) {
            $reference = self::columnName($index + 1) . $rowNumber;

            if ($value === null || $value === '') {
                $xml .= sprintf('<c r="%s" s="%d"/>', $reference, $styleIndex);
                continue;
            }

            // Numeric cells are written as numbers so Excel can sum them, but a
            // leading zero (student numbers, room codes) must stay text or the
            // value is silently corrupted.
            if (is_int($value) || is_float($value)
                || (is_string($value) && is_numeric($value) && !str_starts_with($value, '0') && !str_contains($value, 'E'))
            ) {
                $xml .= sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $styleIndex, (string) $value);
                continue;
            }

            $xml .= sprintf(
                '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                $reference,
                $styleIndex,
                self::escape((string) $value)
            );
        }

        return $xml . '</row>';
    }

    private static function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name      = chr(65 + $remainder) . $name;
            $index     = intdiv($index - 1, 26);
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        // Strip control characters OOXML forbids; they make the file unopenable.
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles(): string
    {
        // Style 0 = body, style 1 = bold white on the L-SIAMS primary blue.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private static function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>L-SIAMS</Application></Properties>';
    }

    private static function coreProps(string $title): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::escape($title) . '</dc:title>'
            . '<dc:creator>L-SIAMS</dc:creator>'
            . '<cp:lastModifiedBy>L-SIAMS</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>';
    }

    /**
     * Read an .xlsx back into rows, for the student/device import preview.
     *
     * @return list<array<int,string>>
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The uploaded file is not a readable workbook.');
        }

        $sharedStrings = [];
        $sharedXml     = $zip->getFromName('xl/sharedStrings.xml');

        if (is_string($sharedXml) && $sharedXml !== '') {
            $doc = @simplexml_load_string($sharedXml);

            if ($doc !== false) {
                foreach ($doc->si as $item) {
                    // <si> may hold a single <t> or a run of <r><t> fragments.
                    $sharedStrings[] = isset($item->t) && count($item->r ?? []) === 0
                        ? (string) $item->t
                        : implode('', array_map(static fn ($r): string => (string) $r->t, iterator_to_array($item->r ?? [])));
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!is_string($sheetXml) || $sheetXml === '') {
            return [];
        }

        $doc = @simplexml_load_string($sheetXml);

        if ($doc === false) {
            throw new RuntimeException('The workbook could not be parsed.');
        }

        $rows = [];

        foreach ($doc->sheetData->row ?? [] as $row) {
            $cells = [];

            foreach ($row->c ?? [] as $cell) {
                $index = self::columnIndex((string) $cell['r']);
                $type  = (string) ($cell['t'] ?? '');

                $value = match ($type) {
                    's'         => $sharedStrings[(int) $cell->v] ?? '',
                    'inlineStr' => (string) ($cell->is->t ?? ''),
                    default     => (string) ($cell->v ?? ''),
                };

                $cells[$index] = trim($value);
            }

            if ($cells === []) {
                continue;
            }

            // Pad gaps so column positions stay aligned with the header row.
            $maxIndex = max(array_keys($cells));
            $ordered  = [];

            for ($i = 0; $i <= $maxIndex; $i++) {
                $ordered[$i] = $cells[$i] ?? '';
            }

            $rows[] = $ordered;
        }

        return $rows;
    }

    private static function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/', $reference, $matches);
        $letters = $matches[1] ?? 'A';
        $index   = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
