<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\Export\CsvWriter;
use App\Services\Export\XlsxWriter;
use Throwable;

/**
 * Bulk import of students and devices (Part 2 and Part 19.7).
 *
 * Two-phase by design: `preview()` validates every row and reports what would
 * happen without touching the database, then `commit()` applies the whole file
 * in one transaction. Any invalid row aborts everything with a clear report —
 * a half-imported roster is worse than no import, because nobody can tell which
 * half landed.
 */
final class ImportService
{
    private const STUDENT_COLUMNS = [
        'student_number', 'first_name', 'middle_name', 'last_name', 'suffix',
        'gender', 'birthdate', 'email', 'address',
        'guardian_name', 'guardian_contact', 'guardian_email',
        'grade_level_code', 'section_code', 'card_uid', 'status',
    ];

    private const DEVICE_COLUMNS = [
        'device_name', 'device_id', 'mac_address', 'classroom_code', 'device_role', 'serial_number',
    ];

    /**
     * Parse an uploaded file into rows keyed by the header names.
     *
     * @param  array<string,mixed> $file $_FILES entry
     * @return array{headers:list<string>,rows:list<array<string,string>>}
     */
    public static function parseUpload(array $file, string $kind = 'students'): array
    {
        self::assertSafeUpload($file);

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if ($extension === 'xlsx' && !XlsxWriter::isSupported()) {
            throw new ValidationException(['file' => [
                "Excel files cannot be read because PHP's zip extension is not enabled. "
                . 'Save the sheet as .csv and upload that instead.',
            ]]);
        }

        $raw = match ($extension) {
            'csv', 'txt' => CsvWriter::parse((string) file_get_contents((string) $file['tmp_name'])),
            'xlsx'       => XlsxWriter::read((string) $file['tmp_name']),
            default      => throw new ValidationException(['file' => ['Upload a .csv or .xlsx file.']]),
        };

        if ($raw === []) {
            throw new ValidationException(['file' => ['The file is empty.']]);
        }

        $headerRow = array_map(
            static fn (string $h): string => strtolower(trim((string) preg_replace('/[^A-Za-z0-9_]/', '_', $h))),
            $raw[0]
        );

        $expected = $kind === 'devices' ? self::DEVICE_COLUMNS : self::STUDENT_COLUMNS;
        $known    = array_intersect($headerRow, $expected);

        if ($known === []) {
            throw new ValidationException(['file' => [sprintf(
                'No recognised column headers. Expected at least some of: %s. Download the template to get the right layout.',
                implode(', ', $expected)
            )]]);
        }

        $rows = [];

        foreach (array_slice($raw, 1) as $line) {
            $record  = [];
            $isEmpty = true;

            foreach ($headerRow as $index => $column) {
                $value           = trim((string) ($line[$index] ?? ''));
                $record[$column] = $value;

                if ($value !== '') {
                    $isEmpty = false;
                }
            }

            if (!$isEmpty) {
                $rows[] = $record;
            }
        }

        if (count($rows) > 5000) {
            throw new ValidationException(['file' => ['Import files are limited to 5,000 rows. Split the file and try again.']]);
        }

        return ['headers' => $headerRow, 'rows' => $rows];
    }

    /**
     * Validate without writing.
     *
     * @param  list<array<string,string>> $rows
     * @return array{valid:list<array<string,mixed>>,invalid:list<array<string,mixed>>,summary:array<string,int>}
     */
    public static function previewStudents(array $rows): array
    {
        $db      = Database::instance();
        $valid   = [];
        $invalid = [];

        // Seen-in-this-file tracking catches duplicates within the upload
        // itself, which a database check alone would miss until commit time.
        $seenNumbers = [];
        $seenUids    = [];

        foreach ($rows as $index => $row) {
            $lineNumber = $index + 2; // +1 for the header, +1 for 1-based lines
            $errors     = [];

            $studentNumber = trim((string) ($row['student_number'] ?? ''));
            $firstName     = trim((string) ($row['first_name'] ?? ''));
            $lastName      = trim((string) ($row['last_name'] ?? ''));
            $sectionCode   = strtoupper(trim((string) ($row['section_code'] ?? '')));
            $gradeCode     = strtoupper(trim((string) ($row['grade_level_code'] ?? '')));
            $cardUid       = RfidService::normalise((string) ($row['card_uid'] ?? ''));

            if ($studentNumber === '') {
                $errors[] = 'Student number is required.';
            } elseif (isset($seenNumbers[$studentNumber])) {
                $errors[] = sprintf('Duplicate student number within this file (also on line %d).', $seenNumbers[$studentNumber]);
            } elseif ($db->scalar('SELECT 1 FROM students WHERE student_number = :n LIMIT 1', ['n' => $studentNumber]) !== null) {
                $errors[] = 'Student number already exists in the system.';
            } else {
                $seenNumbers[$studentNumber] = $lineNumber;
            }

            if ($firstName === '') {
                $errors[] = 'First name is required.';
            }
            if ($lastName === '') {
                $errors[] = 'Last name is required.';
            }
            if (trim((string) ($row['guardian_name'] ?? '')) === '') {
                $errors[] = 'Guardian name is required.';
            }
            if (trim((string) ($row['guardian_contact'] ?? '')) === '') {
                $errors[] = 'Guardian contact is required.';
            }

            $section = null;

            if ($sectionCode === '') {
                $errors[] = 'Section code is required — a student cannot be enrolled without a section.';
            } else {
                $section = $db->selectOne(
                    "SELECT sec.section_id, sec.capacity, sec.enrolled_count, sec.status,
                            gl.grade_level_code
                       FROM sections sec
                       JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
                      WHERE sec.section_code = :code AND sec.deleted_at IS NULL",
                    ['code' => $sectionCode]
                );

                if ($section === null) {
                    $errors[] = sprintf('Section "%s" does not exist.', $sectionCode);
                } elseif ((string) $section['status'] !== 'active') {
                    $errors[] = sprintf('Section "%s" is not active.', $sectionCode);
                } else {
                    // The grade level column is advisory; the section is
                    // authoritative. A mismatch is a mistake worth surfacing.
                    if ($gradeCode !== '' && $gradeCode !== (string) $section['grade_level_code']) {
                        $errors[] = sprintf(
                            'Section "%s" belongs to %s, not %s.',
                            $sectionCode,
                            $section['grade_level_code'],
                            $gradeCode
                        );
                    }

                    $pendingForSection = count(array_filter(
                        $valid,
                        static fn (array $v): bool => $v['section_id'] === (int) $section['section_id']
                    ));

                    if ((int) $section['enrolled_count'] + $pendingForSection >= (int) $section['capacity']) {
                        $errors[] = sprintf(
                            'Section "%s" would exceed its capacity of %d.',
                            $sectionCode,
                            (int) $section['capacity']
                        );
                    }
                }
            }

            if ($cardUid !== '') {
                if (preg_match('/^[0-9A-F]{8,32}$/', $cardUid) !== 1) {
                    $errors[] = 'Card UID must be 8–32 hexadecimal characters.';
                } elseif (isset($seenUids[$cardUid])) {
                    $errors[] = sprintf('Duplicate card UID within this file (also on line %d).', $seenUids[$cardUid]);
                } elseif ($db->scalar('SELECT 1 FROM rfid_cards WHERE card_uid = :u LIMIT 1', ['u' => $cardUid]) !== null) {
                    $errors[] = 'Card UID is already registered.';
                } else {
                    $seenUids[$cardUid] = $lineNumber;
                }
            }

            $birthdate = trim((string) ($row['birthdate'] ?? ''));

            if ($birthdate !== '' && !self::isValidDate($birthdate)) {
                $errors[] = 'Birthdate must be in YYYY-MM-DD format.';
            }

            $email = trim((string) ($row['email'] ?? ''));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Email address is not valid.';
            }

            $record = [
                'line'             => $lineNumber,
                'student_number'   => $studentNumber,
                'first_name'       => $firstName,
                'middle_name'      => trim((string) ($row['middle_name'] ?? '')) ?: null,
                'last_name'        => $lastName,
                'suffix'           => trim((string) ($row['suffix'] ?? '')) ?: null,
                'gender'           => self::normaliseGender((string) ($row['gender'] ?? '')),
                'birthdate'        => $birthdate ?: null,
                'email'            => $email ?: null,
                'address'          => trim((string) ($row['address'] ?? '')) ?: null,
                'guardian_name'    => trim((string) ($row['guardian_name'] ?? '')),
                'guardian_contact' => trim((string) ($row['guardian_contact'] ?? '')),
                'guardian_email'   => trim((string) ($row['guardian_email'] ?? '')) ?: null,
                'section_id'       => $section === null ? 0 : (int) $section['section_id'],
                'section_code'     => $sectionCode,
                'card_uid'         => $cardUid ?: null,
                'status'           => in_array(strtolower((string) ($row['status'] ?? 'active')), ['active', 'inactive'], true)
                    ? strtolower((string) ($row['status'] ?? 'active'))
                    : 'active',
            ];

            if ($errors === []) {
                $valid[] = $record;
            } else {
                $invalid[] = $record + ['errors' => $errors];
            }
        }

        return [
            'valid'   => $valid,
            'invalid' => $invalid,
            'summary' => [
                'total'      => count($rows),
                'valid'      => count($valid),
                'invalid'    => count($invalid),
                'with_cards' => count(array_filter($valid, static fn (array $r): bool => $r['card_uid'] !== null)),
            ],
        ];
    }

    /**
     * Commit a previously previewed batch.
     *
     * The whole import is one transaction: if row 400 of 500 fails, rows 1–399
     * are rolled back too, and the administrator gets one clear failure rather
     * than a database in an unknown state.
     *
     * @param  list<array<string,mixed>> $records
     * @return array{imported:int,cards_assigned:int}
     */
    public static function commitStudents(array $records, int $userId): array
    {
        if ($records === []) {
            throw new ValidationException(['file' => ['There are no valid rows to import.']]);
        }

        $db = Database::instance();

        $result = $db->transaction(static function (Database $db) use ($records, $userId): array {
            $imported = 0;
            $cards    = 0;

            foreach ($records as $record) {
                try {
                    $studentId = StudentService::create([
                        'student_number'   => $record['student_number'],
                        'section_id'       => $record['section_id'],
                        'first_name'       => $record['first_name'],
                        'middle_name'      => $record['middle_name'],
                        'last_name'        => $record['last_name'],
                        'suffix'           => $record['suffix'],
                        'gender'           => $record['gender'],
                        'birthdate'        => $record['birthdate'],
                        'email'            => $record['email'],
                        'address'          => $record['address'],
                        'guardian_name'    => $record['guardian_name'],
                        'guardian_contact' => $record['guardian_contact'],
                        'guardian_email'   => $record['guardian_email'],
                        'status'           => $record['status'],
                    ], $userId);
                } catch (ValidationException $e) {
                    throw new ValidationException(
                        ['file' => [sprintf('Line %d (%s): %s', $record['line'], $record['student_number'], $e->firstMessage())]],
                        'Import aborted; no rows were saved.'
                    );
                }

                $imported++;

                if ($record['card_uid'] !== null) {
                    RfidService::assign($studentId, $record['card_uid'], $userId, 'Assigned during bulk import.');
                    $cards++;
                }
            }

            return ['imported' => $imported, 'cards_assigned' => $cards];
        });

        AuditService::log(
            AuditService::STUDENTS_IMPORTED,
            'students',
            'import',
            null,
            null,
            $result,
            sprintf('Bulk import: %d student(s) created, %d card(s) assigned.', $result['imported'], $result['cards_assigned'])
        );

        NotificationService::toAdministrators(
            'import',
            'Student import complete',
            sprintf('%d student(s) imported and %d RFID card(s) assigned.', $result['imported'], $result['cards_assigned']),
            'normal',
            '/admin/students'
        );

        return $result;
    }

    /**
     * Preview a device import (Part 19.7).
     *
     * @param  list<array<string,string>> $rows
     * @return array{valid:list<array<string,mixed>>,invalid:list<array<string,mixed>>,summary:array<string,int>}
     */
    public static function previewDevices(array $rows): array
    {
        $db      = Database::instance();
        $valid   = [];
        $invalid = [];
        $seenMac = [];
        $seenIds = [];

        foreach ($rows as $index => $row) {
            $lineNumber = $index + 2;
            $errors     = [];

            $name     = trim((string) ($row['device_name'] ?? ''));
            $deviceId = strtoupper(trim((string) ($row['device_id'] ?? '')));
            $mac      = NetworkService::normaliseMac((string) ($row['mac_address'] ?? ''));
            $roomCode = strtoupper(trim((string) ($row['classroom_code'] ?? '')));
            $role     = strtolower(trim((string) ($row['device_role'] ?? 'both')));

            if ($name === '') {
                $errors[] = 'Device name is required.';
            }

            if ($mac === '' || !NetworkService::isValidMac($mac)) {
                $errors[] = 'MAC address must look like AA:BB:CC:DD:EE:FF.';
            } elseif (isset($seenMac[$mac])) {
                $errors[] = sprintf('Duplicate MAC address within this file (also on line %d).', $seenMac[$mac]);
            } elseif ($db->scalar('SELECT 1 FROM devices WHERE mac_address = :m LIMIT 1', ['m' => $mac]) !== null) {
                $errors[] = 'MAC address is already registered.';
            } else {
                $seenMac[$mac] = $lineNumber;
            }

            if ($deviceId !== '') {
                if (isset($seenIds[$deviceId])) {
                    $errors[] = sprintf('Duplicate device ID within this file (also on line %d).', $seenIds[$deviceId]);
                } elseif ($db->scalar('SELECT 1 FROM devices WHERE device_id = :d LIMIT 1', ['d' => $deviceId]) !== null) {
                    $errors[] = 'Device ID is already registered.';
                } else {
                    $seenIds[$deviceId] = $lineNumber;
                }
            }

            if (!in_array($role, ['entry', 'exit', 'both'], true)) {
                $errors[] = 'Device role must be entry, exit or both.';
                $role     = 'both';
            }

            $classroomId = null;

            if ($roomCode !== '') {
                $classroomId = $db->scalar(
                    'SELECT classroom_id FROM classrooms WHERE room_number = :r AND deleted_at IS NULL',
                    ['r' => $roomCode]
                );

                if ($classroomId === null) {
                    $errors[] = sprintf('Classroom "%s" does not exist.', $roomCode);
                }
            }

            $record = [
                'line'           => $lineNumber,
                'device_name'    => $name,
                'device_id'      => $deviceId,
                'mac_address'    => $mac,
                'classroom_id'   => $classroomId === null ? null : (int) $classroomId,
                'classroom_code' => $roomCode,
                'device_role'    => $role,
                'serial_number'  => trim((string) ($row['serial_number'] ?? '')) ?: null,
            ];

            if ($errors === []) {
                $valid[] = $record;
            } else {
                $invalid[] = $record + ['errors' => $errors];
            }
        }

        return [
            'valid'   => $valid,
            'invalid' => $invalid,
            'summary' => ['total' => count($rows), 'valid' => count($valid), 'invalid' => count($invalid)],
        ];
    }

    /**
     * Register a batch of devices, each with its own unique key pair.
     *
     * @param  list<array<string,mixed>> $records
     * @return array{registered:int,provisioning:list<array<string,mixed>>}
     */
    public static function commitDevices(array $records, int $userId): array
    {
        if ($records === []) {
            throw new ValidationException(['file' => ['There are no valid rows to import.']]);
        }

        $db = Database::instance();

        $result = $db->transaction(static function (Database $db) use ($records, $userId): array {
            $provisioning = [];

            foreach ($records as $record) {
                try {
                    $registered = DeviceService::register([
                        'device_name'   => $record['device_name'],
                        'device_id'     => $record['device_id'],
                        'mac_address'   => $record['mac_address'],
                        'classroom_id'  => $record['classroom_id'],
                        'device_role'   => $record['device_role'],
                        'serial_number' => $record['serial_number'],
                    ], $userId);
                } catch (ValidationException $e) {
                    throw new ValidationException(
                        ['file' => [sprintf('Line %d (%s): %s', $record['line'], $record['device_name'], $e->firstMessage())]],
                        'Import aborted; no devices were registered.'
                    );
                }

                $provisioning[] = DeviceService::buildProvisioningFile(
                    (int) $registered['device_row_id'],
                    $registered['credentials'],
                    $registered['claim']
                );
            }

            return ['registered' => count($provisioning), 'provisioning' => $provisioning];
        });

        AuditService::log(
            AuditService::DEVICES_BULK_REGISTERED,
            'devices',
            'import',
            null,
            null,
            [
                'count'   => $result['registered'],
                // The manifest records which devices were created — never their keys.
                'devices' => array_map(
                    static fn (array $p): array => ['device_id' => $p['device_id'], 'classroom' => $p['classroom_name']],
                    $result['provisioning']
                ),
            ],
            sprintf('Bulk device registration: %d terminal(s).', $result['registered'])
        );

        return $result;
    }

    /** Blank template so administrators start from the right column layout. */
    public static function template(string $kind = 'students'): string
    {
        $columns = $kind === 'devices' ? self::DEVICE_COLUMNS : self::STUDENT_COLUMNS;

        $example = $kind === 'devices'
            ? [['Room 204 Terminal', '', 'AA:BB:CC:DD:EE:01', '204', 'both', 'SN-0001']]
            : [[
                '2026-00001', 'Juan', 'Santos', 'Dela Cruz', '',
                'Male', '2009-05-14', '', '123 Mabini St.',
                'Maria Dela Cruz', '09171234567', '',
                'G12', 'G12-STEM-A', '04A7C1935D', 'active',
            ]];

        return CsvWriter::build($columns, $example);
    }

    /**
     * File-upload hardening (Part 6).
     *
     * @param array<string,mixed> $file
     */
    private static function assertSafeUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => [match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
                UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
                UPLOAD_ERR_NO_FILE   => 'Choose a file to upload.',
                default              => 'The file could not be uploaded.',
            }]]);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        // Refusing anything that is not a genuine PHP upload closes the path
        // where a crafted request points tmp_name at an arbitrary server file.
        if ($tmpName === '' || (!is_uploaded_file($tmpName) && !Config::get('app.debug', false))) {
            throw new ValidationException(['file' => ['The upload could not be verified.']]);
        }

        $maxBytes = (int) Config::get('app.uploads.max_bytes', 4194304);

        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new ValidationException(['file' => [sprintf('The file exceeds the %d MB limit.', intdiv($maxBytes, 1048576))]]);
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw new ValidationException(['file' => ['Only .csv and .xlsx files may be imported.']]);
        }

        // Trust the sniffed type, not the client-declared one.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmpName);

        $allowed = [
            'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel',
            'application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        if (!in_array($mime, $allowed, true)) {
            SecurityLogService::log(
                SecurityLogService::MALICIOUS_UPLOAD,
                'high',
                sprintf('Rejected upload "%s" with sniffed MIME type %s.', $file['name'] ?? '?', $mime),
                ['declared_extension' => $extension, 'detected_mime' => $mime]
            );

            throw new ValidationException(['file' => ['The file contents do not match a spreadsheet or CSV file.']]);
        }
    }

    private static function isValidDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private static function normaliseGender(string $value): string
    {
        return match (strtolower(trim($value))) {
            'm', 'male'   => 'Male',
            'f', 'female' => 'Female',
            'other'       => 'Other',
            default        => 'Prefer not to say',
        };
    }
}
