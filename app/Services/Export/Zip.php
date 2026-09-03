<?php
declare(strict_types=1);

namespace App\Services\Export;

use App\Core\Exceptions\HttpException;
use RuntimeException;

/**
 * ZIP container reader and writer, written in plain PHP.
 *
 * This exists because ext-zip is not compiled into PHP by default and is not
 * enabled in a stock XAMPP php.ini. Every .xlsx file is a ZIP of XML parts, so
 * without the extension the Excel export, the Excel import and the bulk device
 * provisioning bundle all failed — three working features taken out by one
 * commented-out line in a config file on a machine in a school office, with no
 * way to fix it from inside the application.
 *
 * The archives involved are small, single-purpose and written by this system or
 * by Excel: a handful of XML parts, no encryption, no spanning, well under the
 * 4 GB where ZIP64 begins. That is a narrow enough target to implement directly
 * (APPNOTE 6.3.x, sections 4.3.7, 4.3.12 and 4.3.16) rather than to depend on a
 * server build we do not control.
 *
 * Compression uses zlib's raw deflate, which is part of the PHP core build
 * rather than a loadable extension. If it is somehow absent the writer stores
 * entries uncompressed — a larger file that opens the same way — so writing
 * never fails for want of a library. Reading a deflated entry genuinely needs
 * gzinflate, and says so if it is missing.
 */
final class Zip
{
    private const LOCAL_HEADER   = "PK\x03\x04";
    private const CENTRAL_HEADER = "PK\x01\x02";
    private const END_OF_CENTRAL = "PK\x05\x06";

    /** Bit 11 of the general-purpose flags: entry names are UTF-8. */
    private const FLAG_UTF8 = 0x0800;

    private const METHOD_STORE   = 0;
    private const METHOD_DEFLATE = 8;

    /** The largest possible end-of-central-directory record: 22 bytes plus a 64 KB comment. */
    private const MAX_EOCD_SIZE = 65557;

    /** The value a 16- or 32-bit field carries when the real one lives in a ZIP64 record. */
    private const ZIP64_SENTINEL_16 = 0xFFFF;
    private const ZIP64_SENTINEL_32 = 0xFFFFFFFF;

    /**
     * Build an archive in memory.
     *
     * Entry order is preserved, which matters for OOXML: [Content_Types].xml is
     * expected first and some readers are unhappy when it is not.
     *
     * @param  array<string,string> $entries path inside the archive => contents
     * @return string raw .zip bytes
     */
    public static function create(array $entries): string
    {
        [$dosTime, $dosDate] = self::dosStamp();

        $canDeflate = function_exists('gzdeflate');
        $local      = '';
        $central    = '';
        $count      = 0;

        foreach ($entries as $name => $contents) {
            $name     = (string) $name;
            $contents = (string) $contents;

            if ($name === '' || str_contains($name, "\0")) {
                throw new RuntimeException('Invalid archive entry name.');
            }

            $uncompressed = strlen($contents);
            $crc          = crc32($contents);
            $method       = self::METHOD_STORE;
            $payload      = $contents;

            if ($canDeflate && $uncompressed > 0) {
                $deflated = @gzdeflate($contents, 6);

                // Deflating XML wins by a wide margin; deflating an already
                // compressed byte string can lose, and storing is then both
                // smaller and faster to read back.
                if (is_string($deflated) && strlen($deflated) < $uncompressed) {
                    $method  = self::METHOD_DEFLATE;
                    $payload = $deflated;
                }
            }

            $compressed = strlen($payload);
            $offset     = strlen($local);

            $local .= self::LOCAL_HEADER
                . pack('v', 20)                 // version needed to extract: 2.0
                . pack('v', self::FLAG_UTF8)
                . pack('v', $method)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $compressed)
                . pack('V', $uncompressed)
                . pack('v', strlen($name))
                . pack('v', 0)                  // extra field length
                . $name
                . $payload;

            $central .= self::CENTRAL_HEADER
                . pack('v', 20)                 // version made by
                . pack('v', 20)                 // version needed to extract
                . pack('v', self::FLAG_UTF8)
                . pack('v', $method)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $compressed)
                . pack('V', $uncompressed)
                . pack('v', strlen($name))
                . pack('v', 0)                  // extra field length
                . pack('v', 0)                  // file comment length
                . pack('v', 0)                  // disk number where the entry starts
                . pack('v', 0)                  // internal attributes
                . pack('V', 0)                  // external attributes
                . pack('V', $offset)
                . $name;

            $count++;
        }

        return $local
            . $central
            . self::END_OF_CENTRAL
            . pack('v', 0)                      // this disk
            . pack('v', 0)                      // disk holding the central directory
            . pack('v', $count)                 // entries on this disk
            . pack('v', $count)                 // entries in total
            . pack('V', strlen($central))
            . pack('V', strlen($local))         // central directory offset
            . pack('v', 0);                     // archive comment length
    }

    /**
     * List the entry names an archive holds, in central-directory order.
     *
     * @return list<string>
     */
    public static function names(string $path): array
    {
        return array_keys(self::index($path));
    }

    /**
     * Read named entries out of an archive on disk.
     *
     * Only the requested entries are decompressed — a workbook's images and
     * calculation chain are never touched. A name that is not in the archive is
     * simply absent from the result rather than an error, because callers ask
     * for parts that are legitimately optional (sharedStrings.xml is only
     * present when the workbook has shared strings).
     *
     * @param  list<string>          $names
     * @return array<string,string>  name => contents, for those that were found
     */
    public static function extract(string $path, array $names): array
    {
        $index = self::index($path);
        $wanted = array_values(array_filter($names, static fn (string $n): bool => isset($index[$n])));

        if ($wanted === []) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        try {
            $out = [];

            foreach ($wanted as $name) {
                $out[$name] = self::readEntry($handle, $index[$name], $name);
            }

            return $out;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse the central directory into name => entry metadata.
     *
     * The central directory is the authority on sizes, not the local header: an
     * archiver that streams its output writes zeros into the local header and
     * puts the real values in a trailing data descriptor. Reading the sizes
     * from here means both shapes work.
     *
     * @return array<string,array{method:int,compressed:int,uncompressed:int,crc:int,offset:int}>
     */
    private static function index(string $path): array
    {
        $size = @filesize($path);

        if ($size === false || $size < 22) {
            throw new RuntimeException('The uploaded file is not a readable workbook.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        try {
            $tailLength = min($size, self::MAX_EOCD_SIZE);
            fseek($handle, $size - $tailLength);
            $tail = self::readExactly($handle, $tailLength);

            $eocd = strrpos($tail, self::END_OF_CENTRAL);

            if ($eocd === false) {
                throw new RuntimeException('The uploaded file is not a ZIP archive.');
            }

            $record = substr($tail, $eocd, 22);

            if (strlen($record) < 22) {
                throw new RuntimeException('The archive directory is truncated.');
            }

            $total       = self::uint16($record, 10);
            $centralSize = self::uint32($record, 12);
            $centralAt   = self::uint32($record, 16);

            if ($total === self::ZIP64_SENTINEL_16
                || $centralSize === self::ZIP64_SENTINEL_32
                || $centralAt === self::ZIP64_SENTINEL_32
            ) {
                throw new HttpException(
                    422,
                    'ZIP64_UNSUPPORTED',
                    'This spreadsheet is stored in the ZIP64 format, which usually means it is '
                    . 'very large. Open it in Excel, delete the rows you are not importing, save '
                    . 'it again and retry.'
                );
            }

            if ($centralAt + $centralSize > $size) {
                throw new RuntimeException('The archive directory points past the end of the file.');
            }

            fseek($handle, $centralAt);
            $directory = self::readExactly($handle, $centralSize);
        } finally {
            fclose($handle);
        }

        $entries = [];
        $cursor  = 0;

        for ($i = 0; $i < $total; $i++) {
            if (substr($directory, $cursor, 4) !== self::CENTRAL_HEADER) {
                throw new RuntimeException('The archive directory is malformed.');
            }

            if ($cursor + 46 > strlen($directory)) {
                throw new RuntimeException('The archive directory is truncated.');
            }

            $nameLength    = self::uint16($directory, $cursor + 28);
            $extraLength   = self::uint16($directory, $cursor + 30);
            $commentLength = self::uint16($directory, $cursor + 32);

            $entries[substr($directory, $cursor + 46, $nameLength)] = [
                'method'       => self::uint16($directory, $cursor + 10),
                'crc'          => self::uint32($directory, $cursor + 16),
                'compressed'   => self::uint32($directory, $cursor + 20),
                'uncompressed' => self::uint32($directory, $cursor + 24),
                'offset'       => self::uint32($directory, $cursor + 42),
            ];

            $cursor += 46 + $nameLength + $extraLength + $commentLength;
        }

        return $entries;
    }

    /**
     * @param resource                                                                   $handle
     * @param array{method:int,compressed:int,uncompressed:int,crc:int,offset:int} $entry
     */
    private static function readEntry($handle, array $entry, string $name): string
    {
        fseek($handle, $entry['offset']);
        $header = self::readExactly($handle, 30);

        if (strlen($header) < 30 || substr($header, 0, 4) !== self::LOCAL_HEADER) {
            throw new RuntimeException(sprintf('The archive entry "%s" is unreadable.', $name));
        }

        // The local header carries its own name and extra-field lengths, and
        // they may differ from the central directory's — the extra field in
        // particular is routinely padded differently in the two places.
        $dataAt = $entry['offset'] + 30 + self::uint16($header, 26) + self::uint16($header, 28);

        fseek($handle, $dataAt);
        $payload = self::readExactly($handle, $entry['compressed']);

        if (strlen($payload) !== $entry['compressed']) {
            throw new RuntimeException(sprintf('The archive entry "%s" is truncated.', $name));
        }

        $contents = match ($entry['method']) {
            self::METHOD_STORE   => $payload,
            self::METHOD_DEFLATE => self::inflate($payload, $name),
            default              => throw new HttpException(
                422,
                'ZIP_METHOD_UNSUPPORTED',
                'This spreadsheet uses a compression method L-SIAMS cannot read. Open it in '
                . 'Excel and use File → Save As to save a fresh copy, then import that.'
            ),
        };

        // Two independent checks that the bytes are the ones that were written.
        // Cheap here, and the alternative is an XML parse error on a file the
        // user has no way to diagnose.
        if (strlen($contents) !== $entry['uncompressed'] || crc32($contents) !== $entry['crc']) {
            throw new RuntimeException(sprintf('The archive entry "%s" is corrupt.', $name));
        }

        return $contents;
    }

    private static function inflate(string $payload, string $name): string
    {
        if (!function_exists('gzinflate')) {
            throw new HttpException(
                503,
                'ZLIB_UNAVAILABLE',
                'Reading spreadsheets needs PHP\'s zlib support, which this server was built '
                . 'without. Save the file as CSV and import that instead.'
            );
        }

        $inflated = @gzinflate($payload);

        if (!is_string($inflated)) {
            throw new RuntimeException(sprintf('The archive entry "%s" could not be decompressed.', $name));
        }

        return $inflated;
    }

    /**
     * Read exactly $length bytes, or as many as the file has left.
     *
     * fread() is documented to return *up to* the requested length; on a plain
     * local file it usually returns all of it, and a reader that assumes so
     * works everywhere until the one deployment where it does not. The callers
     * compare what they got against what the directory promised, so a short
     * file still fails — it just fails as "truncated" rather than as a corrupt
     * archive.
     *
     * @param resource $handle
     */
    private static function readExactly($handle, int $length): string
    {
        $buffer = '';

        while ($length > 0 && !feof($handle)) {
            $chunk = fread($handle, $length);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
            $length -= strlen($chunk);
        }

        return $buffer;
    }

    private static function uint16(string $buffer, int $offset): int
    {
        /** @var array{1:int} $parts */
        $parts = unpack('v', substr($buffer, $offset, 2));

        return $parts[1];
    }

    private static function uint32(string $buffer, int $offset): int
    {
        /** @var array{1:int} $parts */
        $parts = unpack('V', substr($buffer, $offset, 4));

        return $parts[1];
    }

    /**
     * MS-DOS packed date and time, which is what a ZIP header stores.
     *
     * Seconds have one bit less than they need, so the format keeps them in
     * two-second steps; dates before 1980 are not representable and clamp to
     * the epoch of the format rather than wrapping into a nonsense year.
     *
     * @return array{0:int,1:int} time, date
     */
    private static function dosStamp(): array
    {
        $now  = getdate();
        $year = max(1980, (int) $now['year']);

        return [
            ((int) $now['hours'] << 11) | ((int) $now['minutes'] << 5) | ((int) $now['seconds'] >> 1),
            (($year - 1980) << 9) | ((int) $now['mon'] << 5) | (int) $now['mday'],
        ];
    }
}
