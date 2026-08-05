/* ==========================================================================
   SVG chart renderer

   Hand-written rather than a charting library, for the same reason as the
   XLSX and PDF writers: this server may have no outbound internet access, and
   a school's IT staff should be able to redeploy from a USB stick.

   Every chart is inline SVG — resolution-independent, printable, themable via
   the same CSS custom properties as the rest of the UI, and accessible through
   a <title> and a data table fallback.
   ========================================================================== */

(function () {
    'use strict';

    const LS = window.LSIAMS = window.LSIAMS || {};
    const NS = 'http://www.w3.org/2000/svg';

    // Categorical sequence chosen so adjacent series stay distinguishable in
    // greyscale and for the common forms of colour vision deficiency.
    const PALETTE = ['#2563EB', '#22C55E', '#F59E0B', '#EF4444', '#8B5CF6', '#0EA5E9', '#EC4899', '#14B8A6'];

    function el(name, attrs = {}, text = null) {
        const node = document.createElementNS(NS, name);

        Object.entries(attrs).forEach(([key, value]) => {
            if (value !== null && value !== undefined) node.setAttribute(key, String(value));
        });

        if (text !== null) node.textContent = String(text);

        return node;
    }

    function niceMax(value) {
        if (value <= 0) return 10;

        const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
        const scaled    = value / magnitude;
        const rounded   = scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10;

        return rounded * magnitude;
    }

    function tooltipFor(container) {
        let tip = container.querySelector('.chart-tooltip');

        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'chart-tooltip';
            container.appendChild(tip);
        }

        return tip;
    }

    function attachTip(container, node, label) {
        const tip = tooltipFor(container);

        node.addEventListener('mouseenter', (event) => {
            tip.textContent = label;
            tip.classList.add('visible');
            const bounds = container.getBoundingClientRect();
            tip.style.left = (event.clientX - bounds.left + 10) + 'px';
            tip.style.top  = (event.clientY - bounds.top - 30) + 'px';
        });

        node.addEventListener('mousemove', (event) => {
            const bounds = container.getBoundingClientRect();
            tip.style.left = (event.clientX - bounds.left + 10) + 'px';
            tip.style.top  = (event.clientY - bounds.top - 30) + 'px';
        });

        node.addEventListener('mouseleave', () => tip.classList.remove('visible'));
    }

    function emptyState(container, message) {
        container.innerHTML =
            '<div class="empty-state" style="padding:2rem">'
            + '<div class="empty-state__icon"><i class="fa-solid fa-chart-line"></i></div>'
            + '<div class="empty-state__text"></div></div>';
        container.querySelector('.empty-state__text').textContent = message || 'No data for this period.';
    }

    LS.charts = {
        PALETTE: PALETTE,

        /**
         * Multi-series line chart.
         * series: [{ key, label, color }], rows: [{ [xKey], [key]… }]
         */
        line(container, rows, options = {}) {
            container = typeof container === 'string' ? document.getElementById(container) : container;
            if (!container) return;

            if (!rows || rows.length === 0) {
                emptyState(container, options.emptyMessage);
                return;
            }

            const width  = options.width  || container.clientWidth || 720;
            const height = options.height || 260;
            const pad    = { top: 16, right: 18, bottom: 34, left: 44 };
            const xKey   = options.xKey || 'date';
            const series = options.series || [{ key: 'total', label: 'Total', color: PALETTE[0] }];

            const plotWidth  = width - pad.left - pad.right;
            const plotHeight = height - pad.top - pad.bottom;

            let max = 0;
            rows.forEach((row) => series.forEach((s) => { max = Math.max(max, Number(row[s.key]) || 0); }));
            max = options.maxValue || niceMax(max);

            const svg = el('svg', {
                viewBox: `0 0 ${width} ${height}`,
                role: 'img',
                'aria-label': options.title || 'Line chart',
            });

            svg.appendChild(el('title', {}, options.title || 'Line chart'));

            const xAt = (index) => pad.left + (rows.length === 1 ? plotWidth / 2 : (index / (rows.length - 1)) * plotWidth);
            const yAt = (value) => pad.top + plotHeight - ((Number(value) || 0) / max) * plotHeight;

            // Horizontal gridlines with value labels.
            for (let i = 0; i <= 4; i++) {
                const y = pad.top + (plotHeight / 4) * i;
                const value = Math.round(max - (max / 4) * i);

                svg.appendChild(el('line', {
                    x1: pad.left, y1: y, x2: width - pad.right, y2: y,
                    stroke: 'var(--border)', 'stroke-width': 1,
                }));

                svg.appendChild(el('text', {
                    x: pad.left - 8, y: y + 4, 'text-anchor': 'end',
                    'font-size': 10, fill: 'var(--text-muted)',
                }, value));
            }

            // X labels, thinned so they never overlap.
            const step = Math.max(1, Math.ceil(rows.length / 8));

            rows.forEach((row, index) => {
                if (index % step !== 0 && index !== rows.length - 1) return;

                svg.appendChild(el('text', {
                    x: xAt(index), y: height - 12, 'text-anchor': 'middle',
                    'font-size': 10, fill: 'var(--text-muted)',
                }, options.formatX ? options.formatX(row[xKey]) : String(row[xKey]).slice(5)));
            });

            series.forEach((s, seriesIndex) => {
                const color = s.color || PALETTE[seriesIndex % PALETTE.length];
                const points = rows.map((row, index) => `${xAt(index)},${yAt(row[s.key])}`);

                if (options.area && series.length === 1) {
                    svg.appendChild(el('polygon', {
                        points: `${pad.left},${pad.top + plotHeight} ${points.join(' ')} ${xAt(rows.length - 1)},${pad.top + plotHeight}`,
                        fill: color, opacity: .1,
                    }));
                }

                svg.appendChild(el('polyline', {
                    points: points.join(' '),
                    fill: 'none', stroke: color, 'stroke-width': 2,
                    'stroke-linejoin': 'round', 'stroke-linecap': 'round',
                }));

                rows.forEach((row, index) => {
                    const dot = el('circle', {
                        cx: xAt(index), cy: yAt(row[s.key]), r: 3.5,
                        fill: color, stroke: 'var(--surface)', 'stroke-width': 1.5,
                    });

                    attachTip(container, dot, `${row[xKey]} · ${s.label}: ${row[s.key] ?? 0}`);
                    svg.appendChild(dot);
                });
            });

            container.innerHTML = '';
            container.classList.add('chart-wrap');
            container.appendChild(svg);

            if (series.length > 1) {
                container.appendChild(this.legend(series));
            }
        },

        /** Vertical bar chart. */
        bar(container, rows, options = {}) {
            container = typeof container === 'string' ? document.getElementById(container) : container;
            if (!container) return;

            if (!rows || rows.length === 0) {
                emptyState(container, options.emptyMessage);
                return;
            }

            rows = rows.slice(0, options.limit || 14);

            const width  = options.width  || container.clientWidth || 720;
            const height = options.height || 260;
            const pad    = { top: 16, right: 16, bottom: 48, left: 44 };
            const valueKey = options.valueKey || 'total';
            const labelKey = options.labelKey || 'label';

            const plotWidth  = width - pad.left - pad.right;
            const plotHeight = height - pad.top - pad.bottom;

            const max = options.maxValue || niceMax(Math.max(...rows.map((r) => Number(r[valueKey]) || 0)));
            const slot = plotWidth / rows.length;
            const barWidth = Math.min(46, slot * 0.62);

            const svg = el('svg', { viewBox: `0 0 ${width} ${height}`, role: 'img', 'aria-label': options.title || 'Bar chart' });
            svg.appendChild(el('title', {}, options.title || 'Bar chart'));

            for (let i = 0; i <= 4; i++) {
                const y = pad.top + (plotHeight / 4) * i;

                svg.appendChild(el('line', {
                    x1: pad.left, y1: y, x2: width - pad.right, y2: y,
                    stroke: 'var(--border)', 'stroke-width': 1,
                }));

                svg.appendChild(el('text', {
                    x: pad.left - 8, y: y + 4, 'text-anchor': 'end',
                    'font-size': 10, fill: 'var(--text-muted)',
                }, Math.round(max - (max / 4) * i)));
            }

            rows.forEach((row, index) => {
                const value = Number(row[valueKey]) || 0;
                const barHeight = (value / max) * plotHeight;
                const x = pad.left + slot * index + (slot - barWidth) / 2;
                const y = pad.top + plotHeight - barHeight;

                const color = options.colorFor
                    ? options.colorFor(row, index)
                    : PALETTE[index % PALETTE.length];

                const bar = el('rect', {
                    x: x, y: y, width: barWidth, height: Math.max(1, barHeight),
                    fill: color, rx: 3,
                });

                attachTip(container, bar, `${row[labelKey]}: ${value}${options.suffix || ''}`);
                svg.appendChild(bar);

                // Rotate labels when there are enough bars to make them collide.
                const label = String(row[labelKey]);
                const labelX = pad.left + slot * index + slot / 2;

                if (rows.length > 7) {
                    svg.appendChild(el('text', {
                        x: labelX, y: height - 30, 'text-anchor': 'end',
                        'font-size': 9.5, fill: 'var(--text-muted)',
                        transform: `rotate(-45 ${labelX} ${height - 30})`,
                    }, label.length > 12 ? label.slice(0, 11) + '…' : label));
                } else {
                    svg.appendChild(el('text', {
                        x: labelX, y: height - 22, 'text-anchor': 'middle',
                        'font-size': 10, fill: 'var(--text-muted)',
                    }, label.length > 14 ? label.slice(0, 13) + '…' : label));
                }
            });

            container.innerHTML = '';
            container.classList.add('chart-wrap');
            container.appendChild(svg);
        },

        /** Donut chart with a centre total. */
        donut(container, slices, options = {}) {
            container = typeof container === 'string' ? document.getElementById(container) : container;
            if (!container) return;

            const total = slices.reduce((sum, slice) => sum + (Number(slice.value) || 0), 0);

            if (total === 0) {
                emptyState(container, options.emptyMessage || 'No records yet today.');
                return;
            }

            const size   = options.size || 200;
            const radius = size / 2;
            const inner  = options.inner || radius * 0.62;
            const centre = radius;

            const svg = el('svg', {
                viewBox: `0 0 ${size} ${size}`,
                width: size, height: size,
                role: 'img', 'aria-label': options.title || 'Donut chart',
                style: 'max-width:100%;height:auto',
            });

            svg.appendChild(el('title', {}, options.title || 'Distribution'));

            let angle = -Math.PI / 2; // start at 12 o'clock

            slices.forEach((slice, index) => {
                const value = Number(slice.value) || 0;
                if (value === 0) return;

                const sweep = (value / total) * Math.PI * 2;
                const end   = angle + sweep;
                const large = sweep > Math.PI ? 1 : 0;

                const x1 = centre + radius * Math.cos(angle);
                const y1 = centre + radius * Math.sin(angle);
                const x2 = centre + radius * Math.cos(end);
                const y2 = centre + radius * Math.sin(end);
                const x3 = centre + inner * Math.cos(end);
                const y3 = centre + inner * Math.sin(end);
                const x4 = centre + inner * Math.cos(angle);
                const y4 = centre + inner * Math.sin(angle);

                const path = el('path', {
                    d: `M ${x1} ${y1} A ${radius} ${radius} 0 ${large} 1 ${x2} ${y2} L ${x3} ${y3} A ${inner} ${inner} 0 ${large} 0 ${x4} ${y4} Z`,
                    fill: slice.color || PALETTE[index % PALETTE.length],
                });

                attachTip(container, path, `${slice.label}: ${value} (${Math.round(value / total * 100)}%)`);
                svg.appendChild(path);

                angle = end;
            });

            svg.appendChild(el('text', {
                x: centre, y: centre - 2, 'text-anchor': 'middle',
                'font-size': 23, 'font-weight': 700, fill: 'var(--text)',
            }, options.centerValue !== undefined ? options.centerValue : total));

            svg.appendChild(el('text', {
                x: centre, y: centre + 16, 'text-anchor': 'middle',
                'font-size': 10, fill: 'var(--text-muted)',
            }, options.centerLabel || 'Total'));

            container.innerHTML = '';
            container.classList.add('chart-wrap');
            container.style.display = 'flex';
            container.style.justifyContent = 'center';
            container.appendChild(svg);

            if (options.legend !== false) {
                const wrap = document.createElement('div');
                wrap.style.width = '100%';
                wrap.appendChild(this.legend(slices.map((s, i) => ({
                    label: `${s.label} (${s.value})`,
                    color: s.color || PALETTE[i % PALETTE.length],
                }))));
                container.style.flexWrap = 'wrap';
                container.appendChild(wrap);
            }
        },

        /** Horizontal bars — better than vertical when labels are long. */
        horizontalBar(container, rows, options = {}) {
            container = typeof container === 'string' ? document.getElementById(container) : container;
            if (!container) return;

            if (!rows || rows.length === 0) {
                emptyState(container, options.emptyMessage);
                return;
            }

            rows = rows.slice(0, options.limit || 10);

            const valueKey = options.valueKey || 'total';
            const labelKey = options.labelKey || 'label';
            const max = Math.max(...rows.map((r) => Number(r[valueKey]) || 0)) || 1;

            const list = document.createElement('div');
            list.style.display = 'flex';
            list.style.flexDirection = 'column';
            list.style.gap = '.55rem';

            rows.forEach((row, index) => {
                const value = Number(row[valueKey]) || 0;
                const percent = (value / max) * 100;

                const item = document.createElement('div');

                const head = document.createElement('div');
                head.style.display = 'flex';
                head.style.justifyContent = 'space-between';
                head.style.fontSize = '12px';
                head.style.marginBottom = '.2rem';

                const label = document.createElement('span');
                label.textContent = row[labelKey];

                const amount = document.createElement('span');
                amount.className = 'fw-600';
                amount.textContent = value + (options.suffix || '');

                head.appendChild(label);
                head.appendChild(amount);

                const track = document.createElement('div');
                track.className = 'progress';

                const fill = document.createElement('div');
                fill.className = 'progress__bar';
                fill.style.width = percent + '%';
                fill.style.background = options.colorFor
                    ? options.colorFor(row, index)
                    : PALETTE[index % PALETTE.length];

                track.appendChild(fill);
                item.appendChild(head);
                item.appendChild(track);
                list.appendChild(item);
            });

            container.innerHTML = '';
            container.appendChild(list);
        },

        legend(items) {
            const wrap = document.createElement('div');
            wrap.className = 'chart-legend';

            items.forEach((item, index) => {
                const entry = document.createElement('span');
                entry.className = 'chart-legend__item';

                const swatch = document.createElement('span');
                swatch.className = 'chart-legend__swatch';
                swatch.style.background = item.color || PALETTE[index % PALETTE.length];

                const label = document.createElement('span');
                label.textContent = item.label;

                entry.appendChild(swatch);
                entry.appendChild(label);
                wrap.appendChild(entry);
            });

            return wrap;
        },
    };

    // Charts are sized from their container, so a resize needs a redraw.
    let resizeTimer = null;

    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            document.dispatchEvent(new CustomEvent('charts:redraw'));
        }, 250);
    });
})();
