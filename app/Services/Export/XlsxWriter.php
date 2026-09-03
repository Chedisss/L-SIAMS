<?php
declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;

/**
 * Minimal OOXML (.xlsx) writer.
 *
 * Written by hand rather than pulling in PhpSpreadsheet because this system is
 * deployed on an isolated LAN where `composer install` may not be possible, and
 * a school's IT staff should be able to redeploy it from a USB stick. The
 * output is a valid single-sheet workbook with a frozen, styled header row —
 * which is the whole of what the reports need.
 *
 * The ZIP container underneath is written by App\Services\Export\Zip rather
 * than by ext-zip, so Excel export and import work on a stock XAMPP install
 * with no php.ini change.
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
        // [Content_Types].xml goes in first. The OPC specification does not
        // strictly require it, but a reader that scans rather than reads the
        // directory expects to meet it before the parts it describes.
        return Zip::create([
            '[Content_Types].xml'         => self::contentTypes(),
            '_rels/.rels'                 => self::rootRels(),
            'docProps/app.xml'            => self::appProps(),
            'docProps/core.xml'           => self::coreProps($title ?? $sheetName),
            'xl/workbook.xml'             => self::workbook($sheetName),
            'xl/_rels/workbook.xml.rels'  => self::workbookRels(),
            'xl/styles.xml'               => self::styles(),
            'xl/worksheets/sheet1.xml'    => self::sheet($headers, $rows),
        ]);
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
            // The Normal style. Optional as far as Excel is concerned, but its
            // absence makes stricter readers substitute a default and say so,
            // and a warning on opening an attendance register is a warning
            // somebody has to decide whether to worry about.
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
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
        $sheetPart = self::firstWorksheet($path);

        $parts = Zip::extract($path, ['xl/sharedStrings.xml', $sheetPart]);

        $sharedStrings = [];
        $sharedXml     = $parts['xl/sharedStrings.xml'] ?? '';

        if ($sharedXml !== '') {
            $doc = @simplexml_load_string($sharedXml);

            if ($doc !== false) {
                foreach ($doc->si as $item) {
                    // A shared string is either one <t>, or a sequence of <r>
                    // runs each holding its own <t> — which is what Excel
                    // writes as soon as one word in a cell is formatted
                    // differently from the rest.
                    //
                    // The runs are concatenated with a plain loop on purpose:
                    // iterator_to_array() over $item->r preserves keys, and
                    // every run is keyed "r", so all but the last were
                    // silently dropped. "Grade 3 - Gold" arrived as "Gold",
                    // and an import matched it against nothing.
                    if (isset($item->t)) {
                        $sharedStrings[] = (string) $item->t;
                        continue;
                    }

                    $text = '';

                    foreach ($item->r as $run) {
                        $text .= (string) $run->t;
                    }

                    $sharedStrings[] = $text;
                }
            }
        }

        $sheetXml = $parts[$sheetPart] ?? '';

        if ($sheetXml === '') {
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

    /**
     * Work out which part inside the workbook holds the first sheet.
     *
     * Reading xl/worksheets/sheet1.xml is right for the files this class
     * writes and for most of what Excel writes, but not for all of them: the
     * part names are relationship targets, not positions, so a workbook whose
     * first tab was added after the others — or one saved by Google Sheets or
     * LibreOffice — can perfectly legitimately open on sheet3.xml. Importing
     * the wrong tab is worse than failing, because the header row matches and
     * nothing looks wrong until the roster is already in the database.
     */
    private static function firstWorksheet(string $path): string
    {
        $parts = Zip::extract($path, ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels']);

        $workbook = @simplexml_load_string($parts['xl/workbook.xml'] ?? '');
        $rels     = @simplexml_load_string($parts['xl/_rels/workbook.xml.rels'] ?? '');

        if ($workbook !== false && $rels !== false) {
            $targets = [];

            foreach ($rels->Relationship ?? [] as $relationship) {
                $targets[(string) $relationship['Id']] = ltrim((string) $relationship['Target'], '/');
            }

            $namespaces = $workbook->getNamespaces(true);
            $sheets     = $workbook->sheets->sheet ?? [];

            foreach ($sheets as $sheet) {
                $id = (string) ($sheet->attributes($namespaces['r'] ?? '')['id'] ?? '');

                if (isset($targets[$id])) {
                    // Targets are relative to xl/, the folder workbook.xml is in.
                    return str_starts_with($targets[$id], 'xl/') ? $targets[$id] : 'xl/' . $targets[$id];
                }
            }
        }

        // A workbook we could not read the relationships of. Fall back to the
        // conventional name, then to whichever worksheet part exists.
        $names = Zip::names($path);

        if (in_array('xl/worksheets/sheet1.xml', $names, true)) {
            return 'xl/worksheets/sheet1.xml';
        }

        $worksheets = array_values(array_filter(
            $names,
            static fn (string $name): bool => str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')
        ));

        sort($worksheets, SORT_NATURAL);

        return $worksheets[0] ?? 'xl/worksheets/sheet1.xml';
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
