<?php
declare(strict_types=1);

namespace App\Services\Export;

/**
 * Dependency-free PDF writer for tabular reports.
 *
 * Emits a valid PDF 1.4 document using the base-14 Helvetica fonts, which every
 * reader has built in — no font embedding, no external library, no
 * `composer install` on an air-gapped server. It handles the layout this system
 * actually needs: a titled, branded header block, a repeating table header
 * across pages, zebra striping, and page numbering.
 */
final class PdfWriter
{
    private const PAGE_WIDTH  = 841.89;  // A4 landscape, points
    private const PAGE_HEIGHT = 595.28;
    private const MARGIN      = 32.0;
    private const ROW_HEIGHT  = 16.0;
    private const HEADER_SIZE = 8.5;
    private const BODY_SIZE   = 8.0;

    /** @var list<string> */
    private array $pages = [];
    private string $currentPage = '';
    private float $cursorY = 0.0;
    private int $pageNumber = 0;

    /** @var list<string> */
    private array $headers = [];
    /** @var list<float> */
    private array $columnWidths = [];

    private string $title;
    private string $subtitle;
    /** @var array<string,string> */
    private array $meta;
    private string $schoolName;

    /** @param array<string,string> $meta */
    private function __construct(string $title, string $subtitle, array $meta, string $schoolName)
    {
        $this->title      = $title;
        $this->subtitle   = $subtitle;
        $this->meta       = $meta;
        $this->schoolName = $schoolName;
    }

    /**
     * @param list<string>                  $headers
     * @param list<array<string|int,mixed>> $rows
     * @param array<string,string>          $meta       filter summary shown under the title
     * @param array<string,string|int|float> $statistics summary chips above the table
     */
    public static function table(
        string $title,
        array $headers,
        array $rows,
        string $subtitle = '',
        array $meta = [],
        array $statistics = [],
        string $schoolName = 'L-SIAMS'
    ): string {
        $writer = new self($title, $subtitle, $meta, $schoolName);
        $writer->setHeaders($headers);
        $writer->startPage();

        if ($statistics !== []) {
            $writer->drawStatistics($statistics);
        }

        $writer->drawTableHeader();

        $striped = false;

        foreach ($rows as $row) {
            if ($writer->cursorY < self::MARGIN + 40) {
                $writer->finishPage();
                $writer->startPage();
                $writer->drawTableHeader();
            }

            $writer->drawRow(array_values($row), $striped);
            $striped = !$striped;
        }

        if ($rows === []) {
            $writer->drawEmptyState();
        }

        $writer->finishPage();

        return $writer->render();
    }

    /** @param list<string> $headers */
    private function setHeaders(array $headers): void
    {
        $this->headers = $headers;
        $available     = self::PAGE_WIDTH - (self::MARGIN * 2);
        $count         = max(1, count($headers));

        // Weight each column by its header length so "Student Name" gets more
        // room than "Status", rather than dividing the page evenly.
        $weights = array_map(static fn (string $h): float => max(6.0, min(22.0, (float) mb_strlen($h))), $headers);
        $sum     = array_sum($weights) ?: (float) $count;

        $this->columnWidths = array_map(
            static fn (float $w): float => $available * ($w / $sum),
            $weights
        );
    }

    private function startPage(): void
    {
        $this->pageNumber++;
        $this->currentPage = '';
        $this->cursorY     = self::PAGE_HEIGHT - self::MARGIN;

        $this->drawDocumentHeader();
    }

    private function finishPage(): void
    {
        $this->drawFooter();
        $this->pages[] = $this->currentPage;
    }

    private function drawDocumentHeader(): void
    {
        // Brand bar in the L-SIAMS primary blue (#2563EB).
        $this->rect(0, self::PAGE_HEIGHT - 46, self::PAGE_WIDTH, 46, 0.145, 0.388, 0.922);

        $this->text(self::MARGIN, self::PAGE_HEIGHT - 24, $this->schoolName, 13, true, 1, 1, 1);
        $this->text(self::MARGIN, self::PAGE_HEIGHT - 38, 'L-SIAMS · Attendance Monitoring System', 7.5, false, 1, 1, 1);

        $generated = 'Generated ' . date('M j, Y g:i A');
        $this->text(
            self::PAGE_WIDTH - self::MARGIN - $this->textWidth($generated, 7.5),
            self::PAGE_HEIGHT - 38,
            $generated,
            7.5,
            false,
            1,
            1,
            1
        );

        $this->cursorY = self::PAGE_HEIGHT - 64;

        $this->text(self::MARGIN, $this->cursorY, $this->title, 14, true, 0.12, 0.16, 0.23);
        $this->cursorY -= 15;

        if ($this->subtitle !== '') {
            $this->text(self::MARGIN, $this->cursorY, $this->subtitle, 9, false, 0.42, 0.45, 0.50);
            $this->cursorY -= 13;
        }

        if ($this->meta !== []) {
            $parts = [];

            foreach ($this->meta as $label => $value) {
                if ($value !== '') {
                    $parts[] = $label . ': ' . $value;
                }
            }

            if ($parts !== []) {
                foreach (self::wrap(implode('   ·   ', $parts), 150) as $line) {
                    $this->text(self::MARGIN, $this->cursorY, $line, 8, false, 0.42, 0.45, 0.50);
                    $this->cursorY -= 11;
                }
            }
        }

        $this->cursorY -= 6;
    }

    /** @param array<string,string|int|float> $statistics */
    private function drawStatistics(array $statistics): void
    {
        $x        = self::MARGIN;
        $boxWidth = 108.0;
        $boxHeight = 34.0;
        $top      = $this->cursorY;

        foreach ($statistics as $label => $value) {
            if ($x + $boxWidth > self::PAGE_WIDTH - self::MARGIN) {
                break;
            }

            $this->rect($x, $top - $boxHeight, $boxWidth, $boxHeight, 0.949, 0.965, 0.984);
            $this->text($x + 8, $top - 13, mb_strtoupper((string) $label), 6.5, false, 0.42, 0.45, 0.50);
            $this->text($x + 8, $top - 27, (string) $value, 12, true, 0.12, 0.16, 0.23);

            $x += $boxWidth + 8;
        }

        $this->cursorY = $top - $boxHeight - 14;
    }

    private function drawTableHeader(): void
    {
        $this->rect(
            self::MARGIN,
            $this->cursorY - self::ROW_HEIGHT + 4,
            self::PAGE_WIDTH - (self::MARGIN * 2),
            self::ROW_HEIGHT,
            0.117,
            0.161,
            0.231
        );

        $x = self::MARGIN;

        foreach ($this->headers as $index => $header) {
            $width = $this->columnWidths[$index] ?? 60.0;
            $this->text(
                $x + 4,
                $this->cursorY - 8,
                $this->truncate($header, $width - 8, self::HEADER_SIZE),
                self::HEADER_SIZE,
                true,
                1,
                1,
                1
            );
            $x += $width;
        }

        $this->cursorY -= self::ROW_HEIGHT + 2;
    }

    /** @param list<mixed> $cells */
    private function drawRow(array $cells, bool $striped): void
    {
        if ($striped) {
            $this->rect(
                self::MARGIN,
                $this->cursorY - self::ROW_HEIGHT + 5,
                self::PAGE_WIDTH - (self::MARGIN * 2),
                self::ROW_HEIGHT,
                0.973,
                0.980,
                0.988
            );
        }

        $x = self::MARGIN;

        foreach ($this->headers as $index => $_) {
            $width = $this->columnWidths[$index] ?? 60.0;
            $value = $cells[$index] ?? '';

            $this->text(
                $x + 4,
                $this->cursorY - 7,
                $this->truncate(self::stringify($value), $width - 8, self::BODY_SIZE),
                self::BODY_SIZE,
                false,
                0.12,
                0.16,
                0.23
            );

            $x += $width;
        }

        $this->cursorY -= self::ROW_HEIGHT;
    }

    private function drawEmptyState(): void
    {
        $message = 'No records match the selected filters.';
        $this->text(
            (self::PAGE_WIDTH - $this->textWidth($message, 10)) / 2,
            $this->cursorY - 30,
            $message,
            10,
            false,
            0.55,
            0.58,
            0.62
        );
        $this->cursorY -= 50;
    }

    private function drawFooter(): void
    {
        $this->line(self::MARGIN, self::MARGIN + 16, self::PAGE_WIDTH - self::MARGIN, self::MARGIN + 16, 0.85, 0.87, 0.90);

        $left = 'L-SIAMS — confidential attendance record';
        $this->text(self::MARGIN, self::MARGIN + 5, $left, 7, false, 0.55, 0.58, 0.62);

        $right = 'Page ' . $this->pageNumber;
        $this->text(
            self::PAGE_WIDTH - self::MARGIN - $this->textWidth($right, 7),
            self::MARGIN + 5,
            $right,
            7,
            false,
            0.55,
            0.58,
            0.62
        );
    }

    // ------------------------------------------------------- primitives --

    private function text(float $x, float $y, string $value, float $size, bool $bold, float $r, float $g, float $b): void
    {
        $this->currentPage .= sprintf(
            "BT /%s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            $size,
            $r,
            $g,
            $b,
            $x,
            $y,
            self::escape($value)
        );
    }

    private function rect(float $x, float $y, float $width, float $height, float $r, float $g, float $b): void
    {
        $this->currentPage .= sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $r,
            $g,
            $b,
            $x,
            $y,
            $width,
            $height
        );
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $r, float $g, float $b): void
    {
        $this->currentPage .= sprintf(
            "%.3f %.3f %.3f RG 0.5 w %.2f %.2f m %.2f %.2f l S\n",
            $r,
            $g,
            $b,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    /** Helvetica averages ~0.5 em per character — close enough for column fitting. */
    private function textWidth(string $value, float $size): float
    {
        return mb_strlen($value) * $size * 0.5;
    }

    private function truncate(string $value, float $maxWidth, float $size): string
    {
        $value    = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $maxChars = (int) floor($maxWidth / ($size * 0.5));

        if ($maxChars < 1) {
            return '';
        }

        return mb_strlen($value) <= $maxChars ? $value : mb_substr($value, 0, max(1, $maxChars - 1)) . '…';
    }

    /** @return list<string> */
    private static function wrap(string $value, int $width): array
    {
        return explode("\n", wordwrap($value, $width, "\n", true));
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }

    /**
     * PDF string escaping. Non-ASCII is transliterated because the base-14
     * fonts use WinAnsi encoding; embedding a Unicode font would triple the
     * file size for no practical gain on these reports.
     */
    private static function escape(string $value): string
    {
        $value = str_replace(
            ['—', '–', '·', '“', '”', '‘', '’', '…', '•', '≥', '≤'],
            ['-', '-', '-', '"', '"', "'", "'", '...', '*', '>=', '<='],
            $value
        );

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
        $value     = $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '' : $converted;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
    }

    private function render(): string
    {
        $objects   = [];
        $pageCount = count($this->pages);

        // 1: Catalog, 2: Pages, 3: F1, 4: F2, then page + content pairs.
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = sprintf('%d 0 R', 5 + ($i * 2));
        }

        $objects[2] = sprintf(
            '<< /Type /Pages /Count %d /Kids [%s] >>',
            $pageCount,
            implode(' ', $kids)
        );

        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $index => $content) {
            $pageObject    = 5 + ($index * 2);
            $contentObject = $pageObject + 1;

            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentObject
            );

            $objects[$contentObject] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );
        }

        ksort($objects);

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
        }

        $xrefOffset = strlen($pdf);
        $maxObject  = max(array_keys($objects));

        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $maxObject + 1);

        for ($i = 1; $i <= $maxObject; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF",
            $maxObject + 1,
            $xrefOffset
        );

        return $pdf;
    }
}
