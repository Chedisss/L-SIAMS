<?php
/** @var App\Core\View $__view */
$__view->extend('layouts.app');
$__view->start('content');
?>

<?php $__view->include('partials.page-header', [
    'title'       => $section['section_code'] . ' — ' . $section['section_name'],
    'subtitle'    => $section['grade_level_code'] . ($section['strand'] ? ' · ' . $section['strand'] : '')
        . ' · Adviser: ' . ($section['adviser_name'] ?? 'unassigned'),
    'breadcrumbs' => [['Dashboard', '/admin'], ['Sections', '/admin/sections'], [$section['section_code'], null]],
]); ?>

<div class="grid grid--4 mb-3">
    <?php foreach ([
        ['Enrolled',   $section['enrolled_count'] . ' / ' . $section['capacity'], 'fa-people-group', ''],
        ['With cards', $section['students_with_cards'] . ' / ' . $section['active_students'], 'fa-id-card', 'info'],
        ['Attendance', ($section['attendance_percentage_30d'] ?? 0) . '%', 'fa-percent', 'success'],
        ['Schedules',  count($schedules), 'fa-calendar-days', 'warning'],
    ] as [$label, $value, $icon, $tone]): ?>
        <div class="stat">
            <span class="stat__icon <?= $tone ? 'stat__icon--' . e($tone) : '' ?>"><i class="fa-solid <?= e($icon) ?>"></i></span>
            <div><div class="stat__label"><?= e($label) ?></div><div class="stat__value" style="font-size:21px"><?= e($value) ?></div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Roster</h2>
            <span class="text-sm text-muted"><?= e(count($roster)) ?> student(s)</span>
        </div>
        <div class="card__body--flush">
            <?php if ($roster === []): ?>
                <?php $__view->include('partials.empty-state', ['icon' => 'fa-user-graduate', 'title' => 'No students enrolled']); ?>
            <?php else: ?>
                <div class="table-wrap" style="max-height:520px;overflow-y:auto">
                    <table class="data">
                        <thead><tr><th>Student No.</th><th>Name</th><th>Card</th><th class="numeric">Attendance</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($roster as $student): ?>
                            <tr>
                                <td class="mono text-sm"><?= e($student['student_number']) ?></td>
                                <td>
                                    <a href="/admin/students/<?= e($student['student_id']) ?>">
                                        <?= e($student['last_name']) ?>, <?= e($student['first_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($student['card_uid']): ?>
                                        <span class="mono text-xs"><?= e($student['card_uid']) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">No card</span>
                                    <?php endif; ?>
                                </td>
                                <td class="numeric">
                                    <?php if ($student['attendance_percentage'] === null): ?>
                                        <span class="text-subtle">—</span>
                                    <?php else: ?>
                                        <span class="<?= $student['attendance_percentage'] < 80 ? 'text-danger fw-600' : '' ?>">
                                            <?= e($student['attendance_percentage']) ?>%
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= e(status_badge($student['status'])) ?>"><?= e(ucfirst((string) $student['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php /* Full width, on its own row.
        Sharing the two-column grid with the roster left five day columns in
        half a page: Thursday and Friday were off the right-hand edge and the
        band labels, centred across the whole week, were centred somewhere
        nobody could see. A timetable is a wide thing and has to be given the
        width. */ ?>
<div class="grid">
    <?php /* The week as a grid rather than a list.
            Nobody reads a timetable by scanning forty rows for the ones that
            say Wednesday — they look at a column. The list this replaced was
            correct and unusable for the thing it was for. */ ?>
    <div class="card" id="week-card">
        <div class="card__header">
            <h2 class="card__title">Weekly schedule</h2>
            <?php if (($week['rows'] ?? []) !== []): ?>
                <button class="btn btn-secondary btn-sm" id="week-download">
                    <i class="fa-solid fa-image"></i> Download as image
                </button>
            <?php endif; ?>
        </div>
        <div class="card__body--flush">
            <?php if (($week['rows'] ?? []) === []): ?>
                <?php $__view->include('partials.empty-state', [
                    'icon' => 'fa-calendar-days', 'title' => 'No classes scheduled',
                    'text' => 'This section has no schedule yet, so no attendance can be taken for it.',
                ]); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data timetable" id="week-grid">
                        <thead>
                            <tr>
                                <th style="width:104px">Time</th>
                                <th class="numeric" style="width:74px">Duration</th>
                                <?php foreach ($week['days'] as $day): ?>
                                    <th><?= e($day) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($week['rows'] as $row): ?>
                            <?php if ($row['type'] === 'band'): ?>
                                <?php /* Break, lunch and home time are not schedules — nothing
                                        is taught in them, so no session can open — but leaving
                                        the gap blank makes the timetable look broken. The times
                                        are the school's own; only the label is inferred. */ ?>
                                <tr class="timetable__band">
                                    <td class="mono text-sm nowrap">
                                        <?= e($row['start']) ?><?= $row['end'] ? '–' . e($row['end']) : '' ?>
                                    </td>
                                    <td class="numeric text-sm"><?= $row['duration'] ? e($row['duration']) : '' ?></td>
                                    <td colspan="<?= e(count($week['days'])) ?>"><?= e($row['label']) ?></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td class="mono text-sm nowrap"><?= e($row['start']) ?>–<?= e($row['end']) ?></td>
                                    <td class="numeric text-sm"><?= e($row['duration']) ?></td>
                                    <?php foreach ($week['days'] as $day): ?>
                                        <?php $cell = $row['cells'][$day] ?? null; ?>
                                        <td class="timetable__cell">
                                            <?php if ($cell === null): ?>
                                                <span class="text-muted">—</span>
                                            <?php else: ?>
                                                <span class="cell-primary"><?= e($cell['subject_name']) ?></span>
                                                <span class="cell-muted"><?= e($cell['teacher_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (($week['rows'] ?? []) !== []): ?>
<?php
/* Handed to the download as data rather than scraped back out of the DOM:
   the picture should be of the timetable, not of however the table happens to
   be laid out at the window width somebody had open. */
$__timetable = [
    'section' => trim((string) $section['section_code'] . ' — ' . (string) $section['section_name']),
    'grade'   => (string) ($section['grade_level_name'] ?? ''),
    'school'  => App\Services\SettingsService::schoolName(),
    'days'    => $week['days'],
    'rows'    => $week['rows'],
];
?>
<script type="application/json" id="week-data"><?= json_encode($__timetable, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<?php
$__view->stop();
$__view->start('scripts');
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const source = document.getElementById('week-data');
    const button = document.getElementById('week-download');

    if (!source || !button) return;

    const data = JSON.parse(source.textContent);

    /* Drawn as SVG and rasterised through a canvas.
     *
     * No library. This system runs on a school LAN with no internet, so
     * html2canvas or anything else from a CDN would simply never load — the
     * button would do nothing on the one machine it has to work on. An SVG
     * built here and painted into a canvas needs nothing but the browser.
     *
     * Plain <text> elements rather than <foreignObject>: foreignObject is what
     * lets you reuse HTML and CSS, and it is also what several browsers refuse
     * to rasterise, producing a blank image with no error. */

    const COL_TIME = 118, COL_DUR = 78, COL_DAY = 178;
    const ROW = 54, BAND = 30, HEAD = 38, TITLE = 84, PAD = 18;

    /* SVG text does not wrap, and a long subject name simply runs on into the
     * next column — "Homeroom Guidance Program" walked straight across three
     * of them. There is no measuring API here that works before the image is
     * rendered, so the width is estimated from the characters: bold text at
     * this size averages a little over half the font size per character, which
     * is close enough to break on the right word and never close to running
     * over a whole column. */
    function wrapToWidth(text, maxWidth, fontSize) {
        const per   = fontSize * 0.56;
        const limit = Math.max(6, Math.floor(maxWidth / per));
        const words = String(text).split(/\s+/);
        const lines = [];
        let current = '';

        words.forEach((word) => {
            const candidate = current === '' ? word : current + ' ' + word;

            if (candidate.length <= limit) {
                current = candidate;
                return;
            }

            if (current !== '') lines.push(current);
            current = word;
        });

        if (current !== '') lines.push(current);

        // Two lines is the most a row has height for. A third would collide
        // with the teacher beneath it, so the remainder is trimmed rather than
        // allowed to overlap.
        if (lines.length > 2) {
            lines[1] = lines[1].slice(0, Math.max(0, limit - 1)) + '…';
            lines.length = 2;
        }

        return lines;
    }

    function escapeXml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&apos;');
    }

    function buildSvg() {
        const days  = data.days;
        const width = PAD * 2 + COL_TIME + COL_DUR + COL_DAY * days.length;

        let bodyHeight = 0;
        data.rows.forEach((r) => { bodyHeight += r.type === 'band' ? BAND : ROW; });

        const height = PAD * 2 + TITLE + HEAD + bodyHeight;
        const parts  = [];

        parts.push('<rect width="' + width + '" height="' + height + '" fill="#ffffff"/>');

        // Title block.
        parts.push('<text x="' + (width / 2) + '" y="' + (PAD + 24)
            + '" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="19"'
            + ' font-weight="700" fill="#111827">' + escapeXml(data.school) + '</text>');
        parts.push('<text x="' + (width / 2) + '" y="' + (PAD + 46)
            + '" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="14"'
            + ' fill="#374151">Class Schedule</text>');
        parts.push('<text x="' + (width / 2) + '" y="' + (PAD + 68)
            + '" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="16"'
            + ' font-weight="600" fill="#111827">' + escapeXml(data.section)
            + (data.grade ? ' · ' + escapeXml(data.grade) : '') + '</text>');

        const top = PAD + TITLE;
        const cols = [PAD, PAD + COL_TIME, PAD + COL_TIME + COL_DUR];
        days.forEach((d, i) => cols.push(PAD + COL_TIME + COL_DUR + COL_DAY * (i + 1)));

        // Header.
        parts.push('<rect x="' + PAD + '" y="' + top + '" width="' + (width - PAD * 2)
            + '" height="' + HEAD + '" fill="#1e3a8a"/>');

        const headings = ['Time', 'Duration'].concat(days);
        headings.forEach((label, i) => {
            const x = (cols[i] + cols[i + 1]) / 2;
            parts.push('<text x="' + x + '" y="' + (top + 25) + '" text-anchor="middle"'
                + ' font-family="Segoe UI, Arial, sans-serif" font-size="12" font-weight="700"'
                + ' fill="#ffffff" letter-spacing="0.6">' + escapeXml(label.toUpperCase()) + '</text>');
        });

        let y = top + HEAD;

        data.rows.forEach((row) => {
            const h = row.type === 'band' ? BAND : ROW;

            if (row.type === 'band') {
                parts.push('<rect x="' + PAD + '" y="' + y + '" width="' + (width - PAD * 2)
                    + '" height="' + h + '" fill="#fef3c7"/>');
                parts.push('<text x="' + ((cols[2] + cols[cols.length - 1]) / 2) + '" y="' + (y + h / 2 + 4)
                    + '" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="12"'
                    + ' font-weight="600" fill="#92400e" letter-spacing="2">'
                    + escapeXml(row.label.toUpperCase()) + '</text>');
            } else {
                days.forEach((day, i) => {
                    const cell = row.cells[day];
                    if (!cell) return;

                    const cx    = (cols[i + 2] + cols[i + 3]) / 2;
                    const inner = COL_DAY - 16;
                    const name  = wrapToWidth(cell.subject_name, inner, 12.5);
                    const who   = wrapToWidth('(' + cell.teacher_name + ')', inner, 11).slice(0, 1);

                    // Centred as a block, so a one-line cell and a two-line one
                    // sit on the same optical line rather than the same baseline.
                    const block = name.length * 16 + 15;
                    let   ty    = y + (ROW - block) / 2 + 12;

                    name.forEach((line) => {
                        parts.push('<text x="' + cx + '" y="' + ty + '" text-anchor="middle"'
                            + ' font-family="Segoe UI, Arial, sans-serif" font-size="12.5" font-weight="600"'
                            + ' fill="#111827">' + escapeXml(line) + '</text>');
                        ty += 16;
                    });

                    parts.push('<text x="' + cx + '" y="' + (ty - 1) + '" text-anchor="middle"'
                        + ' font-family="Segoe UI, Arial, sans-serif" font-size="11" fill="#4b5563">'
                        + escapeXml(who[0] || '') + '</text>');
                });
            }

            // Time and duration read the same on a band as on a period.
            const timeLabel = row.end ? row.start + '–' + row.end : row.start;
            parts.push('<text x="' + ((cols[0] + cols[1]) / 2) + '" y="' + (y + h / 2 + 4)
                + '" text-anchor="middle" font-family="Consolas, monospace" font-size="11.5"'
                + ' fill="#111827">' + escapeXml(timeLabel) + '</text>');

            if (row.duration) {
                parts.push('<text x="' + ((cols[1] + cols[2]) / 2) + '" y="' + (y + h / 2 + 4)
                    + '" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="11.5"'
                    + ' fill="#374151">' + escapeXml(row.duration) + '</text>');
            }

            y += h;
            parts.push('<line x1="' + PAD + '" y1="' + y + '" x2="' + (width - PAD) + '" y2="' + y
                + '" stroke="#d1d5db" stroke-width="1"/>');
        });

        // Verticals, drawn last so they sit over the band fills.
        cols.forEach((x) => {
            parts.push('<line x1="' + x + '" y1="' + top + '" x2="' + x + '" y2="' + y
                + '" stroke="#d1d5db" stroke-width="1"/>');
        });
        parts.push('<rect x="' + PAD + '" y="' + top + '" width="' + (width - PAD * 2)
            + '" height="' + (y - top) + '" fill="none" stroke="#9ca3af" stroke-width="1.5"/>');

        return {
            markup: '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height
                + '" viewBox="0 0 ' + width + ' ' + height + '">' + parts.join('') + '</svg>',
            width: width,
            height: height,
        };
    }

    button.addEventListener('click', function () {
        const svg = buildSvg();

        /* Twice the size, so the picture is still sharp when somebody prints
         * it or drops it into a document. */
        const scale  = 2;
        const canvas = document.createElement('canvas');
        canvas.width  = svg.width * scale;
        canvas.height = svg.height * scale;

        const context = canvas.getContext('2d');
        context.scale(scale, scale);

        const blob = new Blob([svg.markup], { type: 'image/svg+xml;charset=utf-8' });
        const url  = URL.createObjectURL(blob);
        const image = new Image();

        image.onload = function () {
            context.drawImage(image, 0, 0);
            URL.revokeObjectURL(url);

            canvas.toBlob(function (png) {
                if (!png) {
                    LS.toast.error('The browser could not produce the image.');
                    return;
                }

                const link = document.createElement('a');
                link.href = URL.createObjectURL(png);
                link.download = (data.section || 'timetable').replace(/[^A-Za-z0-9-]+/g, '-') + '.png';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.setTimeout(() => URL.revokeObjectURL(link.href), 1000);
            }, 'image/png');
        };

        image.onerror = function () {
            URL.revokeObjectURL(url);
            LS.toast.error('The browser could not render the timetable image.');
        };

        image.src = url;
    });
})();
</script>
<?php $__view->stop(); ?>
