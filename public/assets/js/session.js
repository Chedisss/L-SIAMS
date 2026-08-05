/* ==========================================================================
   Idle session timer (Part 18.3)

        Idle 0:00 ──────────────── 9:00 ──── 9:00–10:00 ──── 10:00
                                    │            │              │
                            warning modal   60s countdown   hard logout

   This is UX only. The server owns the real deadline (SessionTimeoutMiddleware)
   and re-states it on every response via X-Session-Expires-In, which this
   module re-syncs from — so a tampered client-side timer buys nothing.
   ========================================================================== */

(function () {
    'use strict';

    const LS = window.LSIAMS = window.LSIAMS || {};

    LS.session = {
        expiresAt: null,
        warnSeconds: 60,
        ticker: null,
        countdownTicker: null,
        warningShown: false,
        channel: null,
        isLeader: false,
        lastActivityPing: 0,
        loggedOut: false,

        ACTIVITY_EVENTS: ['mousedown', 'keydown', 'scroll', 'touchstart', 'click', 'focus'],
        PING_DEBOUNCE_MS: 60000,

        init() {
            if (!LS.config.authenticated) return;

            this.warnSeconds = LS.config.warnSecondsBefore || 60;

            const initialSeconds = LS.config.sessionExpiresIn
                || (LS.config.idleTimeoutMinutes || 10) * 60;

            this.setExpiry(initialSeconds);
            this.initChannel();
            this.bindActivity();
            this.startTicker();
            this.interceptResponses();
        },

        setExpiry(seconds) {
            this.expiresAt = Date.now() + (seconds * 1000);

            if (seconds > this.warnSeconds) {
                this.hideWarning();
            }
        },

        remaining() {
            return Math.max(0, Math.round((this.expiresAt - Date.now()) / 1000));
        },

        /* ------------------------------------------------ multi-tab sync -- */

        /**
         * Activity in any tab resets the timer in all of them, and the warning
         * modal is shown by one tab only (leader election by timestamp).
         */
        initChannel() {
            if ('BroadcastChannel' in window) {
                this.channel = new BroadcastChannel('lsiams-session');
                this.channel.onmessage = (event) => this.handleMessage(event.data);
            } else {
                // Fallback for browsers without BroadcastChannel: the storage
                // event fires in every *other* tab, which is exactly what we need.
                window.addEventListener('storage', (event) => {
                    if (event.key === 'lsiams-session-msg' && event.newValue) {
                        try { this.handleMessage(JSON.parse(event.newValue)); } catch (e) { /* ignore */ }
                    }
                });
            }

            this.claimLeadership();
            setInterval(() => this.claimLeadership(), 5000);
        },

        broadcast(message) {
            if (this.channel) {
                this.channel.postMessage(message);
            } else {
                try {
                    localStorage.setItem('lsiams-session-msg', JSON.stringify(
                        Object.assign({ at: Date.now() }, message)
                    ));
                } catch (e) { /* ignore */ }
            }
        },

        handleMessage(message) {
            if (!message || !message.type) return;

            switch (message.type) {
                case 'activity':
                    this.setExpiry(message.seconds);
                    break;

                case 'extended':
                    this.setExpiry(message.seconds);
                    this.hideWarning();
                    LS.toast.success('Session extended.');
                    break;

                case 'logout':
                    this.redirectToLogin(message.reason || 'logout');
                    break;

                case 'leader-claim':
                    // Later timestamp wins; ties resolve on the next round.
                    if (message.at > (this.leaderClaimedAt || 0)) {
                        this.isLeader = false;
                    }
                    break;
            }
        },

        claimLeadership() {
            const now = Date.now();
            const stored = parseInt(localStorage.getItem('lsiams-session-leader') || '0', 10);

            // A leader that has not refreshed in 12 seconds is presumed gone.
            if (now - stored > 12000) {
                localStorage.setItem('lsiams-session-leader', String(now));
                this.leaderClaimedAt = now;
                this.isLeader = true;
            } else if (this.isLeader) {
                localStorage.setItem('lsiams-session-leader', String(now));
                this.leaderClaimedAt = now;
            }
        },

        /* --------------------------------------------------- activity ----- */

        bindActivity() {
            const onActivity = () => this.registerActivity();

            this.ACTIVITY_EVENTS.forEach((eventName) => {
                document.addEventListener(eventName, onActivity, { passive: true, capture: true });
            });

            document.addEventListener('input', onActivity, { passive: true });
        },

        /**
         * Debounced to at most one server round trip per minute — a keystroke
         * should not cost a request.
         */
        registerActivity() {
            if (this.loggedOut || this.warningShown) return;

            const now = Date.now();

            // Optimistic local extension keeps the countdown honest between pings.
            this.setExpiry((LS.config.idleTimeoutMinutes || 10) * 60);

            if (now - this.lastActivityPing < this.PING_DEBOUNCE_MS) {
                this.broadcast({ type: 'activity', seconds: this.remaining() });
                return;
            }

            this.lastActivityPing = now;

            // Not marked passive: reaching this endpoint IS the activity signal.
            LS.http.post('/api/auth/keepalive', {})
                .then((response) => {
                    const seconds = response.data && response.data.expires_in;
                    if (seconds) {
                        this.setExpiry(seconds);
                        this.broadcast({ type: 'activity', seconds: seconds });
                    }
                })
                .catch(() => { /* the ticker will catch a genuine expiry */ });
        },

        /* ---------------------------------------------------- countdown --- */

        startTicker() {
            this.ticker = setInterval(() => {
                if (this.loggedOut) return;

                const remaining = this.remaining();

                if (remaining <= 0) {
                    this.expire();
                    return;
                }

                if (remaining <= this.warnSeconds && !this.warningShown) {
                    // Only the leader tab shows the modal; the others follow it.
                    if (this.isLeader) {
                        this.showWarning();
                    }
                }

                this.updateCountdown(remaining);
            }, 1000);
        },

        updateCountdown(remaining) {
            const display = document.getElementById('session-countdown');

            if (display && this.warningShown) {
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                display.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            }
        },

        showWarning() {
            if (this.warningShown) return;

            this.warningShown = true;

            const modal = document.getElementById('session-warning-modal');

            if (!modal) {
                // No modal on the page (a bare error page, say) — just expire.
                return;
            }

            const dirtyNotice = modal.querySelector('#session-unsaved-notice');

            if (dirtyNotice) {
                dirtyNotice.style.display = LS.drafts.hasDirtyForms() ? 'block' : 'none';
            }

            modal.classList.add('open');
            document.body.style.overflow = 'hidden';

            const stayButton = modal.querySelector('#session-stay');
            if (stayButton) setTimeout(() => stayButton.focus(), 80);
        },

        hideWarning() {
            if (!this.warningShown) return;

            this.warningShown = false;

            const modal = document.getElementById('session-warning-modal');

            if (modal) {
                modal.classList.remove('open');
                if (!document.querySelector('.modal-backdrop.open')) {
                    document.body.style.overflow = '';
                }
            }
        },

        /** The "Stay Signed In" button. Only closes once the server confirms. */
        async extend() {
            const button = document.getElementById('session-stay');
            LS.util.setBusy(button, true, 'Extending…');

            try {
                const response = await LS.http.post('/api/auth/keepalive', {});
                const seconds = (response.data && response.data.expires_in) || (LS.config.idleTimeoutMinutes * 60);

                this.setExpiry(seconds);
                this.hideWarning();
                this.broadcast({ type: 'extended', seconds: seconds });
                LS.toast.success('Session extended.');
            } catch (error) {
                this.redirectToLogin('idle_timeout');
            } finally {
                LS.util.setBusy(button, false);
            }
        },

        expire() {
            if (this.loggedOut) return;

            this.loggedOut = true;
            clearInterval(this.ticker);
            this.broadcast({ type: 'logout', reason: 'idle_timeout' });
            this.redirectToLogin('idle_timeout');
        },

        forceLogout(reason) {
            if (this.loggedOut) return;

            this.loggedOut = true;
            clearInterval(this.ticker);
            this.broadcast({ type: 'logout', reason: reason });
            this.redirectToLogin(reason);
        },

        redirectToLogin(reason) {
            if (LS.realtime && LS.realtime.disconnect) {
                LS.realtime.disconnect();
            }

            window.location.href = '/login?reason=' + encodeURIComponent(reason || 'idle_timeout');
        },

        async signOutNow() {
            try {
                await LS.http.post('/logout', {});
            } catch (e) { /* proceed regardless */ }

            this.loggedOut = true;
            this.broadcast({ type: 'logout', reason: 'logout' });
            window.location.href = '/login?reason=logout';
        },

        /**
         * Re-sync from the server's own header on every response, so the client
         * clock can never drift away from the authoritative deadline.
         */
        interceptResponses() {
            const originalFetch = window.fetch;

            window.fetch = async (...args) => {
                const response = await originalFetch(...args);

                const header = response.headers.get('X-Session-Expires-In');

                if (header !== null) {
                    const seconds = parseInt(header, 10);

                    if (!isNaN(seconds) && seconds > 0) {
                        // Only accept a *longer* remaining time from a passive
                        // request; otherwise a poll could mask a real expiry.
                        const isExtension = seconds > this.remaining();
                        if (isExtension) this.setExpiry(seconds);
                    }
                }

                return response;
            };
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        const stay = document.getElementById('session-stay');
        const out  = document.getElementById('session-signout');

        if (stay) stay.addEventListener('click', () => LS.session.extend());
        if (out)  out.addEventListener('click', () => LS.session.signOutNow());
    });
})();
