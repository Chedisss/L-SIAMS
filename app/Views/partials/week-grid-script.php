<?php
/**
 * The "Download as image" button behind every week grid.
 *
 * Included by whichever page rendered partials.week-grid, and reads the
 * timetable out of the #week-data JSON block that page emits. Kept in one
 * file because it is two hundred lines of SVG geometry: a copy of it in the
 * teacher pages would drift from this one the first time a column width
 * changed, and the drift would only show up in a downloaded picture nobody
 * looks at until they need it.
 *
 * @var App\Core\View $__view
 */
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
            + ' fill="#374151">' + escapeXml(data.caption || 'Class Schedule') + '</text>');
        parts.push('<text x="' + (width / 2) + '" y="' + (PAD + 68)
            + '" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="16"'
            + ' font-weight="600" fill="#111827">' + escapeXml(data.heading)
            + (data.subheading ? ' · ' + escapeXml(data.subheading) : '') + '</text>');

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
                    const name  = wrapToWidth(cell.primary, inner, 12.5);
                    const who   = wrapToWidth('(' + cell.secondary + ')', inner, 11).slice(0, 1);

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
                link.download = (data.filename || data.heading || 'timetable').replace(/[^A-Za-z0-9-]+/g, '-') + '.png';
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
