/* ==========================================================================
   Notification centre

   The badge poll and the dropdown fetch are both marked passive, so a
   dashboard left open on an empty desk still expires on schedule (Part 18.4).
   ========================================================================== */

(function () {
    'use strict';

    const LS = window.LSIAMS = window.LSIAMS || {};

    LS.notifications = {
        loaded: false,

        init() {
            if (!LS.config.authenticated) return;

            const trigger = document.querySelector('[data-dropdown="notification-menu"]');

            if (trigger) {
                trigger.addEventListener('click', () => {
                    if (!this.loaded) this.load();
                });
            }

            const markAll = document.getElementById('mark-all-read');

            if (markAll) {
                markAll.addEventListener('click', async (event) => {
                    event.stopPropagation();

                    try {
                        const response = await LS.http.post('/notifications/read-all', {});
                        LS.toast.success(response.message);
                        this.setBadge(0);
                        this.load();
                    } catch (error) {
                        LS.toast.fromError(error);
                    }
                });
            }

            // Poll the badge every 45 seconds; push updates arrive faster when
            // the realtime channel is up, this is just the safety net.
            setInterval(() => this.refreshBadge(), 45000);

            if (LS.realtime) {
                LS.realtime.on('system.notification', (data) => {
                    this.bumpBadge();
                    this.loaded = false;

                    if (data.priority === 'critical' || data.priority === 'high') {
                        LS.toast.show(data.message, data.priority === 'critical' ? 'danger' : 'warning', data.title, 9000);
                    }
                });
            }
        },

        async refreshBadge() {
            try {
                const response = await LS.http.poll('/notifications/unread-count');
                this.setBadge((response.data && response.data.count) || 0);
            } catch (error) { /* the next tick will retry */ }
        },

        setBadge(count) {
            const badge = document.getElementById('notification-count');
            if (!badge) return;

            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.toggle('hidden', count === 0);
        },

        bumpBadge() {
            const badge = document.getElementById('notification-count');
            if (!badge) return;

            const current = parseInt(badge.textContent, 10) || 0;
            this.setBadge(current + 1);
        },

        async load() {
            const list = document.getElementById('notification-list');
            if (!list) return;

            list.innerHTML = '<div class="text-muted text-sm" style="padding:.8rem">Loading…</div>';

            try {
                const response = await LS.http.poll('/notifications', { unread_only: false });
                const grouped = (response.data && response.data.grouped) || {};

                list.innerHTML = '';

                if (Object.keys(grouped).length === 0) {
                    list.innerHTML = '<div class="text-muted text-sm" style="padding:1rem;text-align:center">'
                        + 'No notifications.</div>';
                    this.loaded = true;
                    return;
                }

                Object.entries(grouped).forEach(([group, items]) => {
                    const heading = document.createElement('div');
                    heading.className = 'sidebar__section';
                    heading.style.color = 'var(--text-subtle)';
                    heading.textContent = group;
                    list.appendChild(heading);

                    items.forEach((item) => list.appendChild(this.renderItem(item)));
                });

                this.setBadge((response.data && response.data.unread_count) || 0);
                this.loaded = true;
            } catch (error) {
                list.innerHTML = '<div class="text-danger text-sm" style="padding:.8rem">'
                    + 'Could not load notifications.</div>';
            }
        },

        renderItem(item) {
            const row = document.createElement('a');
            row.className = 'dropdown__item';
            row.href = item.link || '#';
            row.style.alignItems = 'flex-start';
            row.style.borderLeft = '3px solid ' + this.priorityColour(item.priority);

            if (!item.is_read || item.is_read === '0') {
                row.style.background = 'var(--surface-alt)';
            }

            const body = document.createElement('div');
            body.style.flex = '1';
            body.style.minWidth = '0';

            const title = document.createElement('div');
            title.className = 'fw-600';
            title.style.fontSize = '12.5px';
            title.textContent = item.title;

            const message = document.createElement('div');
            message.className = 'text-muted text-xs';
            message.textContent = item.message;

            const when = document.createElement('div');
            when.className = 'text-subtle text-xs mt-1';
            when.textContent = LS.util.formatDate(item.created_at) + ' · ' + LS.util.formatTime(item.created_at);

            body.appendChild(title);
            body.appendChild(message);
            body.appendChild(when);
            row.appendChild(body);

            row.addEventListener('click', () => {
                LS.http.post('/notifications/' + item.notification_id + '/read', {}).catch(() => {});
            });

            return row;
        },

        priorityColour(priority) {
            return {
                critical: 'var(--danger)',
                high: 'var(--warning)',
                normal: 'var(--primary)',
                low: 'var(--border)',
            }[priority] || 'var(--border)';
        },
    };

    document.addEventListener('DOMContentLoaded', () => LS.notifications.init());
})();
