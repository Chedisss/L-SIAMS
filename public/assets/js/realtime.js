/* ==========================================================================
   Real-time transport (Part 17.4)

   Three tiers with automatic degradation:
     1. WebSocket (WSS, ticket-authenticated)
     2. Server-Sent Events
     3. AJAX long-polling

   On reconnect the client asks for everything after the last sequence it saw,
   so no event is lost across a drop (Part 17.7). All fallback traffic is marked
   passive and therefore never extends the session.
   ========================================================================== */

(function () {
    'use strict';

    const LS = window.LSIAMS = window.LSIAMS || {};

    LS.realtime = {
        socket: null,
        eventSource: null,
        pollTimer: null,
        tier: null,
        cursor: 0,
        sequences: {},
        handlers: {},
        reconnectAttempts: 0,
        maxReconnectDelay: 30000,
        intentionalClose: false,

        init() {
            if (!LS.config.authenticated) return;

            this.cursor = parseInt(sessionStorage.getItem('lsiams-rt-cursor') || '0', 10);
            this.connect();

            // A tab returning to the foreground may have missed events while
            // throttled; reconnecting triggers the replay path.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && this.tier !== 'websocket') {
                    this.connect();
                }
            });

            window.addEventListener('online', () => { this.reconnectAttempts = 0; this.connect(); });
            window.addEventListener('offline', () => this.setIndicator('offline'));
            window.addEventListener('beforeunload', () => this.disconnect());
        },

        on(eventType, handler) {
            (this.handlers[eventType] = this.handlers[eventType] || []).push(handler);
            return this;
        },

        emit(eventType, payload) {
            (this.handlers[eventType] || []).forEach((handler) => {
                try { handler(payload); } catch (error) { console.error('Realtime handler failed', error); }
            });

            (this.handlers['*'] || []).forEach((handler) => {
                try { handler(eventType, payload); } catch (error) { /* ignore */ }
            });
        },

        /* ---------------------------------------------------- connection -- */

        async connect() {
            this.disconnect(true);

            if (!LS.config.realtimeUrl || !('WebSocket' in window)) {
                this.startSse();
                return;
            }

            try {
                const response = await LS.http.post('/api/realtime/ticket', {});
                const ticket = response.data && response.data.ticket;
                const url    = (response.data && response.data.url) || LS.config.realtimeUrl;

                if (!ticket || !url) {
                    this.startSse();
                    return;
                }

                this.openSocket(this.resolveUrl(url) + '/?ticket=' + encodeURIComponent(ticket));
            } catch (error) {
                this.startSse();
            }
        },

        /**
         * `localhost` in the configured WebSocket URL means "this machine" on
         * whichever machine reads it — so a phone opening the system by the
         * server's LAN address would dial its own port 8443 and find nothing.
         * Point a loopback host at whatever address the page itself came from,
         * which is by definition the machine running the server. Any other
         * configured host is a deliberate deployment choice and is left alone.
         */
        resolveUrl(url) {
            const loopback = ['localhost', '127.0.0.1', '[::1]', '::1'];

            try {
                const parsed = new URL(url);

                if (loopback.indexOf(parsed.hostname) !== -1 && window.location.hostname) {
                    parsed.hostname = window.location.hostname;
                }

                return parsed.toString().replace(/\/+$/, '');
            } catch (error) {
                return url;
            }
        },

        openSocket(url) {
            let socket;

            try {
                socket = new WebSocket(url);
            } catch (error) {
                this.startSse();
                return;
            }

            this.socket = socket;
            this.setIndicator('reconnecting');

            const connectTimeout = setTimeout(() => {
                if (socket.readyState !== WebSocket.OPEN) {
                    socket.close();
                    this.startSse();
                }
            }, 5000);

            socket.onopen = () => {
                clearTimeout(connectTimeout);
                this.tier = 'websocket';
                this.reconnectAttempts = 0;
                this.setIndicator('live');
                this.requestReplay();
            };

            socket.onmessage = (event) => {
                let payload;

                try { payload = JSON.parse(event.data); } catch (e) { return; }

                if (payload.type === 'pong' || payload.type === 'welcome') return;

                this.handleEvent(payload);
            };

            socket.onerror = () => { /* onclose follows and handles the fallback */ };

            socket.onclose = (event) => {
                clearTimeout(connectTimeout);

                if (this.intentionalClose) return;

                // 4001 = the server ended our session; there is nothing to retry.
                if (event.code === 4001) {
                    LS.session.forceLogout('terminated');
                    return;
                }

                this.socket = null;
                this.scheduleReconnect();
            };

            // Keep-alive so idle proxies do not silently drop the connection.
            this.pingTimer = setInterval(() => {
                if (socket.readyState === WebSocket.OPEN) {
                    socket.send(JSON.stringify({ type: 'ping' }));
                }
            }, 25000);
        },

        scheduleReconnect() {
            this.reconnectAttempts++;

            // Exponential backoff, capped. After three failures, fall back to
            // SSE rather than keep hammering a WebSocket port that may be
            // blocked by a school firewall.
            if (this.reconnectAttempts > 3) {
                this.startSse();
                return;
            }

            const delay = Math.min(1000 * Math.pow(2, this.reconnectAttempts), this.maxReconnectDelay);

            this.setIndicator('reconnecting');
            setTimeout(() => this.connect(), delay);
        },

        /* ---------------------------------------------------------- SSE --- */

        startSse() {
            this.disconnect(true);

            if (!('EventSource' in window)) {
                this.startPolling();
                return;
            }

            try {
                this.eventSource = new EventSource('/api/realtime/stream?cursor=' + this.cursor);
            } catch (error) {
                this.startPolling();
                return;
            }

            this.tier = 'sse';
            this.setIndicator('live');

            this.eventSource.onmessage = (event) => {
                try { this.handleEvent(JSON.parse(event.data)); } catch (e) { /* ignore */ }
            };

            this.eventSource.onerror = () => {
                this.eventSource.close();
                this.eventSource = null;
                this.startPolling();
            };
        },

        /* ------------------------------------------------------ polling --- */

        startPolling() {
            this.disconnect(true);

            this.tier = 'polling';
            this.setIndicator('polling');

            const interval = LS.config.pollIntervalMs || 3000;

            const tick = async () => {
                if (document.visibilityState === 'hidden') return;

                try {
                    // Passive: polling for the live feed is not user activity.
                    const response = await LS.http.poll('/api/realtime/poll', { cursor: this.cursor });
                    const data = response.data || {};

                    (data.events || []).forEach((event) => this.handleEvent(event));

                    if (data.cursor) {
                        this.cursor = data.cursor;
                        sessionStorage.setItem('lsiams-rt-cursor', String(this.cursor));
                    }

                    this.setIndicator('polling');
                } catch (error) {
                    if (!error.handled) this.setIndicator('offline');
                }
            };

            tick();
            this.pollTimer = setInterval(tick, interval);
        },

        /* ------------------------------------------------------- replay --- */

        /** Ask for everything after the last sequence seen on each channel. */
        async requestReplay() {
            const channels = Object.keys(this.sequences);

            if (channels.length === 0) return;

            for (const channel of channels) {
                try {
                    const response = await LS.http.poll('/api/realtime/replay', {
                        channel: channel,
                        since: this.sequences[channel],
                    });

                    const events = (response.data && response.data.events) || [];

                    if (events.length > 0) {
                        events.forEach((event) => this.handleEvent(event));
                        LS.toast.info(events.length + ' update(s) caught up after reconnecting.');
                    }
                } catch (error) { /* a failed replay is not fatal */ }
            }
        },

        handleEvent(payload) {
            if (!payload || !payload.event) return;

            if (payload.channel && payload.sequence) {
                const known = this.sequences[payload.channel] || 0;

                // Ignore anything we have already applied — replay and live
                // delivery can overlap during a reconnect.
                if (payload.sequence <= known) return;

                this.sequences[payload.channel] = payload.sequence;
            }

            this.emit(payload.event, payload.data || {});
        },

        setIndicator(state) {
            const pill = document.getElementById('connection-indicator');
            if (!pill) return;

            pill.dataset.state = state;

            const label = pill.querySelector('.connection-pill__label');

            if (label) {
                label.textContent = {
                    live: 'Live',
                    reconnecting: 'Reconnecting',
                    polling: 'Polling',
                    offline: 'Offline',
                }[state] || state;
            }

            pill.title = {
                live: 'Connected — updates arrive instantly.',
                reconnecting: 'Reconnecting to the live update service…',
                polling: 'Live updates unavailable; refreshing every few seconds instead.',
                offline: 'No connection to the server.',
            }[state] || '';
        },

        disconnect(silent) {
            this.intentionalClose = true;

            if (this.socket) {
                try { this.socket.close(); } catch (e) { /* ignore */ }
                this.socket = null;
            }

            if (this.eventSource) {
                try { this.eventSource.close(); } catch (e) { /* ignore */ }
                this.eventSource = null;
            }

            clearInterval(this.pollTimer);
            clearInterval(this.pingTimer);
            this.pollTimer = null;
            this.pingTimer = null;

            if (!silent) this.setIndicator('offline');

            this.intentionalClose = false;
        },
    };
})();
