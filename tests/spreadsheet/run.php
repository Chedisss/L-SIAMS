#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ZIP container and .xlsx workbook tests.
 *
 *   php tests/spreadsheet/run.php
 *
 * No database and no network. Everything is written to a temporary directory
 * and removed afterwards.
 *
 * L-SIAMS writes and reads the ZIP container itself rather than through
 * ext-zip, because that extension is commented out in a stock XAMPP php.ini
 * and its absence took out Excel export, Excel import and the device
 * provisioning bundle at once. That is a file format implemented by hand, and
 * a file format implemented by hand fails in a specific way: the archive looks
 * fine to the code that wrote it and is refused by Excel, on a machine in a
 * school office, with nothing to go on. So the important assertions here are
 * the ones that cross the boundary — an archive this code wrote, read back by
 * ext-zip; an archive ext-zip wrote, read back by this code. Agreeing with
 * itself proves nothing.
 *
 * When ext-zip is not installed the cross-checks are skipped rather than
 * failed, and the run says so. The rest still runs, which is the point: this
 * suite is meant to pass on a server with no zip extension at all.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\Export\XlsxWriter;
use App\Services\Export\Zip;

$passed   = 0;
$failed   = 0;
$skipped  = 0;
$failures = [];

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        printf("  \033[32m✓\033[0m %s\n", $name);

        return;
    }

    $failed++;
    $failures[] = $name . ($detail === '' ? '' : ' — ' . $detail);
    printf("  \033[31m✗ %s%s\033[0m\n", $name, $detail === '' ? '' : ' — ' . $detail);
}

function skip(string $name, string $why): void
{
    global $skipped;

    $skipped++;
    printf("  \033[33m•\033[0m %s \033[2m(skipped: %s)\033[0m\n", $name, $why);
}

function group(string $name): void
{
    printf("\n\033[1m%s\033[0m\n", $name);
}

/** Everything is written here, so nothing outside the sandbox is touched. */
$sandbox = sys_get_temp_dir() . '/lsiams-xlsx-test-' . bin2hex(random_bytes(4));
mkdir($sandbox, 0700, true);

register_shutdown_function(static function () use ($sandbox): void {
    foreach (glob($sandbox . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($sandbox);
});

$hasExtZip = class_exists(\ZipArchive::class);

/**
 * Entries chosen for the ways a ZIP writer goes wrong: an empty member (zero
 * CRC, zero length), one that compresses well, one that does not compress at
 * all, a nested path, a non-ASCII name, and bytes that are not text.
 *
 * @var array<string,string> $entries
 */
$entries = [
    'small.txt'    => 'hi',
    'empty.txt'    => '',
    'repeat.txt'   => str_repeat('L-SIAMS attendance ', 4000),
    'binary.bin'   => random_bytes(5000),
    'nested/a/b.x' => "line\nline\r\n\x00\xff",
    'utf8-ñ.txt'   => 'Sección Ñ — Grade 3 Gold',
];

$ours = $sandbox . '/ours.zip';
file_put_contents($ours, Zip::create($entries));

// ---------------------------------------------------------------------------
group('An archive we wrote, read by ext-zip');

if ($hasExtZip) {
    $zip = new \ZipArchive();

    // CHECKCONS makes libzip verify that the local headers and the central
    // directory agree, which is exactly the class of mistake a hand-written
    // writer makes.
    check('ext-zip opens it with consistency checking on', $zip->open($ours, \ZipArchive::CHECKCONS) === true);
    check('every entry is present', $zip->numFiles === count($entries), (string) $zip->numFiles);
    check('entry order is preserved', $zip->getNameIndex(0) === 'small.txt', (string) $zip->getNameIndex(0));

    foreach ($entries as $name => $expected) {
        check(
            sprintf('%s reads back byte for byte (%d bytes)', $name, strlen($expected)),
            $zip->getFromName($name) === $expected
        );
    }

    $zip->close();
} else {
    skip('cross-check against ext-zip', 'the zip extension is not installed');
}

// ---------------------------------------------------------------------------
group('An archive ext-zip wrote, read by us');

if ($hasExtZip) {
    $theirs = $sandbox . '/theirs.zip';
    $zip    = new \ZipArchive();
    $zip->open($theirs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();

    $read = Zip::extract($theirs, array_keys($entries));

    foreach ($entries as $name => $expected) {
        check(sprintf('%s reads back byte for byte', $name), ($read[$name] ?? null) === $expected);
    }

    check('names() lists every entry in order', Zip::names($theirs) === array_keys($entries));
    check('asking for an entry that is not there is not an error', Zip::extract($theirs, ['absent.txt']) === []);

    // A stored entry exercises the other branch of the read path.
    $stored = $sandbox . '/stored.zip';
    $zip    = new \ZipArchive();
    $zip->open($stored, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('plain.txt', str_repeat('A', 1000));
    $zip->setCompressionName('plain.txt', \ZipArchive::CM_STORE);
    $zip->close();

    check(
        'an uncompressed entry reads back',
        Zip::extract($stored, ['plain.txt'])['plain.txt'] === str_repeat('A', 1000)
    );

    // An archive comment pushes the end-of-central-directory record away from
    // the last byte of the file, which is where a naive reader looks for it.
    $commented = $sandbox . '/commented.zip';
    $zip       = new \ZipArchive();
    $zip->open($commented, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('a.txt', 'value');
    $zip->setArchiveComment(str_repeat('c', 300));
    $zip->close();

    check('the directory is found past a 300-byte archive comment', Zip::extract($commented, ['a.txt'])['a.txt'] === 'value');
} else {
    skip('cross-check against ext-zip', 'the zip extension is not installed');
}

// ---------------------------------------------------------------------------
group('Damaged archives are refused, not passed on');

// Flip a byte inside the first entry's compressed payload. Both the stored
// length and the stored CRC should catch it; either alone is enough.
$corrupt = Zip::create(['x.txt' => str_repeat('payload ', 500)]);
$offset  = 60;
$corrupt[$offset] = chr(ord($corrupt[$offset]) ^ 0xFF);
file_put_contents($sandbox . '/corrupt.zip', $corrupt);

$caught = false;

try {
    Zip::extract($sandbox . '/corrupt.zip', ['x.txt']);
} catch (\Throwable) {
    $caught = true;
}

check('a flipped byte in the payload is detected', $caught);

file_put_contents($sandbox . '/notazip.bin', str_repeat('not an archive ', 100));

$message = '';

try {
    Zip::extract($sandbox . '/notazip.bin', ['x']);
} catch (\Throwable $e) {
    $message = $e->getMessage();
}

check('a file that is not an archive is named as such', str_contains($message, 'not a ZIP'), $message);

// ---------------------------------------------------------------------------
group('Workbooks round-trip through the writer and the reader');

$headers = ['Student No.', 'Last Name', 'Grade', 'Attendance %', 'Remarks'];
$rows    = [
    ['0231', 'Dela Cruz', 3, 96.5, 'Sección Ñ, "Gold"'],
    ['0232', 'Reyes', 3, 100, "Tab\there & <angle>"],
    ['1000', 'Santos', 6, 0, ''],
];

$book = $sandbox . '/book.xlsx';
file_put_contents($book, XlsxWriter::build($headers, $rows, 'Register', 'Attendance Register'));

if ($hasExtZip) {
    check('the workbook is a consistent archive', (new \ZipArchive())->open($book, \ZipArchive::CHECKCONS) === true);
} else {
    skip('the workbook is a consistent archive', 'the zip extension is not installed');
}

$back = XlsxWriter::read($book);

check('the header row survives', ($back[0] ?? []) === $headers, json_encode($back[0] ?? []));

// A student number with a leading zero must come back as text. Written as a
// number it becomes 231, and nothing in the file records that it was wrong.
check(
    'a leading zero is kept as text',
    ($back[1] ?? []) === ['0231', 'Dela Cruz', '3', '96.5', 'Sección Ñ, "Gold"'],
    json_encode($back[1] ?? [])
);
check(
    'escaped characters come back unescaped',
    ($back[2] ?? []) === ['0232', 'Reyes', '3', '100', "Tab\there & <angle>"],
    json_encode($back[2] ?? [])
);
check(
    'a zero and a blank stay distinct',
    ($back[3] ?? []) === ['1000', 'Santos', '6', '0', ''],
    json_encode($back[3] ?? [])
);
check('no phantom rows', count($back) === 4, (string) count($back));

// ---------------------------------------------------------------------------
group('Workbooks written by something else');

/** @return array<string,string> */
$partsOf = static function (string $path): array {
    $parts = [];

    foreach (Zip::names($path) as $name) {
        $parts[$name] = Zip::extract($path, [$name])[$name];
    }

    return $parts;
};

// The first sheet is a relationship target, not a filename. A workbook whose
// first tab lives in sheet7.xml is legal, and importing whichever sheet
// happens to be called sheet1.xml would silently load the wrong tab.
$parts = $partsOf($book);
$sheet = $parts['xl/worksheets/sheet1.xml'];
unset($parts['xl/worksheets/sheet1.xml']);
$parts['xl/worksheets/sheet7.xml']   = $sheet;
$parts['xl/_rels/workbook.xml.rels'] = str_replace(
    'Target="worksheets/sheet1.xml"',
    'Target="worksheets/sheet7.xml"',
    $parts['xl/_rels/workbook.xml.rels']
);

$renamed = $sandbox . '/renamed.xlsx';
file_put_contents($renamed, Zip::create($parts));

check('the first sheet is resolved through the relationships', XlsxWriter::read($renamed)[0] === $headers);

// Excel stores cell text in a shared string table, and splits a cell into
// formatting runs the moment one word in it is styled differently.
$parts = $partsOf($book);
$parts['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="2" uniqueCount="2">'
    . '<si><t>Shared A</t></si>'
    . '<si><r><t>Grade 3 </t></r><r><t>- Gold</t></r></si>'
    . '</sst>';
$parts['xl/worksheets/sheet1.xml'] = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
    . '<row r="2"><c r="A2"><v>42</v></c><c r="C2" t="inlineStr"><is><t>gap</t></is></c></row>'
    . '</sheetData></worksheet>';

$sharedBook = $sandbox . '/shared.xlsx';
file_put_contents($sharedBook, Zip::create($parts));

$sharedRows = XlsxWriter::read($sharedBook);

check('shared strings resolve', ($sharedRows[0] ?? []) === ['Shared A', 'Grade 3 - Gold'], json_encode($sharedRows[0] ?? []));
check('a skipped column pads rather than shifting', ($sharedRows[1] ?? []) === ['42', '', 'gap'], json_encode($sharedRows[1] ?? []));

// ---------------------------------------------------------------------------
printf("\n%s\n", str_repeat('─', 60));

if (!$hasExtZip) {
    printf("\033[33mext-zip is not installed; %d cross-checks were skipped.\033[0m\n", $skipped);
}

if ($failed === 0) {
    printf("\033[32m✓ %d assertions passed.\033[0m\n", $passed);
    exit(0);
}

printf("\033[31m✗ %d of %d assertions failed:\033[0m\n", $failed, $passed + $failed);

foreach ($failures as $failure) {
    printf("    %s\n", $failure);
}

exit(1);
