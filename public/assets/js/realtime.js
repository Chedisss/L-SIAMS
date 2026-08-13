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
        // Every connection attempt carries the generation it was started in.
        // A socket closed because a newer attempt superseded it must not drag
        // the newer one down with it, and `intentionalClose` cannot express
        // that: onclose fires a tick later, by which time a shared flag has
        // already been reset. Comparing generations is unambiguous.
        generation: 0,
        // Set once the WebSocket port has proved unreachable. Re-running the
        // handshake on every tab focus after that only flickers the indicator
        // through "Reconnecting" on a system that has no realtime server
        // running at all — which is the normal state for a single-PC install.
        websocketUnavailable: false,
        sseUnavailable: false,
        // True once any tier has actually delivered a connection. Until then a
        // failure is still part of getting started, and saying "Reconnecting"
        // about a connection that has never existed reads as a fault.
        hasConnected: false,
        // How long a proven-unreachable realtime server is taken at its word.
        // Long enough that navigating around the system does not re-run the
        // handshake on every page; short enough that starting the realtime
        // server is noticed without closing the browser.
        unavailableTtlMs: 5 * 60 * 1000,

        init() {
            if (!LS.config.authenticated) return;

            this.cursor = parseInt(sessionStorage.getItem('lsiams-rt-cursor') || '0', 10);

            // Carried across page loads. Without it every navigation repeats
            // the full WebSocket handshake and backoff, so the pill spends the
            // first seconds of every single page reading "Reconnecting" on an
            // installation that simply has no realtime server running.
            this.websocketUnavailable = this.recallUnavailable('ws');
            this.sseUnavailable       = this.recallUnavailable('sse');

            this.connect();

            // A tab returning to the foreground may have missed events while
            // throttled. Polling already covers the gap on its next tick, so
            // only the streaming tiers need waking.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState !== 'visible') return;
                if (this.tier === 'websocket' || this.tier === 'polling') return;

                this.connect();
            });

            window.addEventListener('online', () => {
                this.reconnectAttempts = 0;
                this.forget('ws');
                this.forget('sse');
                this.connect();
            });
            window.addEventListener('offline', () => this.setIndicator('offline'));
            // Invalidate first: the socket's onclose fires after this handler,
            // and without a generation bump it would schedule a reconnect on a
            // page that is already on its way out.
            window.addEventListener('beforeunload', () => {
                this.generation++;
                this.disconnect(true);
            });
        },

        /* ------------------------------------- transport availability memo -- */

        recallUnavailable(tier) {
            const stamp = parseInt(sessionStorage.getItem('lsiams-rt-' + tier + '-down') || '0', 10);

            if (!stamp) return false;

            if (Date.now() - stamp > this.unavailableTtlMs) {
                this.forget(tier);
                return false;
            }

            return true;
        },

        remember(tier) {
            this[tier === 'ws' ? 'websocketUnavailable' : 'sseUnavailable'] = true;

            try {
                sessionStorage.setItem('lsiams-rt-' + tier + '-down', String(Date.now()));
            } catch (e) { /* private mode: the in-memory flag still holds */ }
        },

        forget(tier) {
            this[tier === 'ws' ? 'websocketUnavailable' : 'sseUnavailable'] = false;

            try {
                sessionStorage.removeItem('lsiams-rt-' + tier + '-down');
            } catch (e) { /* ignore */ }
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
            const generation = ++this.generation;

            this.disconnect(true);

            if (this.websocketUnavailable || !LS.config.realtimeUrl || !('WebSocket' in window)) {
                this.startSse();
                return;
            }

            // Until something has actually connected there is nothing to
            // reconnect to, and the alarming word on a freshly opened page is
            // what makes people think the page is broken when it is merely
            // still deciding which transport to use.
            this.setIndicator(this.hasConnected ? 'reconnecting' : 'connecting');

            let ticket;
            let url;

            try {
                const response = await LS.http.post('/api/realtime/ticket', {});
                ticket = response.data && response.data.ticket;
                url    = (response.data && response.data.url) || LS.config.realtimeUrl;
            } catch (error) {
                this.startSse();
                return;
            }

            // The await above yields, so a newer connect() may have started
            // and already opened its own socket. Dropping this one keeps the
            // two from fighting over this.socket.
            if (generation !== this.generation) return;

            if (!ticket || !url) {
                this.startSse();
                return;
            }

            this.openSocket(url + '/?ticket=' + encodeURIComponent(ticket), generation);
        },

        openSocket(url, generation) {
            let socket;

            try {
                socket = new WebSocket(url);
            } catch (error) {
                this.startSse();
                return;
            }

            this.socket = socket;

            const connectTimeout = setTimeout(() => {
                if (socket.readyState !== WebSocket.OPEN) {
                    socket.close();
                }
            }, 5000);

            socket.onopen = () => {
                clearTimeout(connectTimeout);

                if (generation !== this.generation) { socket.close(); return; }

                this.tier = 'websocket';
                this.reconnectAttempts = 0;
                this.hasConnected = true;
                this.forget('ws');
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
                clearInterval(this.pingTimer);
                this.pingTimer = null;

                // Superseded by a newer attempt: that attempt owns the tier
                // now, so this close is not a failure of anything live.
                if (generation !== this.generation) return;

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
            // blocked by a school firewall — or, far more often on a single-PC
            // install, simply has no realtime server listening behind it.
            if (this.reconnectAttempts > 3) {
                this.remember('ws');
                this.startSse();
                return;
            }

            // A connection that has never succeeded is almost always a server
            // that is not running, and a refusal comes back instantly. Backing
            // off in seconds there just holds the page on "Connecting" for no
            // gain, so the first three tries are quick; a connection that *was*
            // live gets the patient backoff, because something transient is the
            // likelier explanation and hammering it is the wrong answer.
            const base  = this.hasConnected ? 1000 : 300;
            const delay = Math.min(base * Math.pow(2, this.reconnectAttempts), this.maxReconnectDelay);

            this.setIndicator(this.hasConnected ? 'reconnecting' : 'connecting');
            setTimeout(() => this.connect(), delay);
        },

        /* ---------------------------------------------------------- SSE --- */

        startSse() {
            this.disconnect(true);

            // The server tells us whether the stream endpoint is switched on.
            // Trying it anyway costs a failed request and a visible flicker on
            // every page of a development install, where it is off by default.
            if (this.sseUnavailable || LS.config.sseEnabled === false || !('EventSource' in window)) {
                this.startPolling();
                return;
            }

            const generation = this.generation;

            try {
                this.eventSource = new EventSource('/api/realtime/stream?cursor=' + this.cursor);
            } catch (error) {
                this.remember('sse');
                this.startPolling();
                return;
            }

            this.tier = 'sse';

            // Not "live" yet — an EventSource reports readyState CONNECTING
            // until the server actually answers, and claiming a connection we
            // do not have is how the pill ends up saying Live on a page whose
            // updates never arrive.
            this.setIndicator('connecting');

            this.eventSource.onopen = () => {
                if (generation !== this.generation) return;

                this.hasConnected = true;
                this.forget('sse');
                this.setIndicator('live');
            };

            this.eventSource.onmessage = (event) => {
                try { this.handleEvent(JSON.parse(event.data)); } catch (e) { /* ignore */ }
            };

            this.eventSource.onerror = () => {
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }

                if (generation !== this.generation) return;

                this.remember('sse');
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
                    connecting: 'Connecting',
                    live: 'Live',
                    reconnecting: 'Reconnecting',
                    polling: 'Polling',
                    offline: 'Offline',
                }[state] || state;
            }

            pill.title = {
                connecting: 'Choosing how to receive live updates…',
                live: 'Connected — updates arrive instantly.',
                reconnecting: 'Reconnecting to the live update service…',
                polling: 'Live updates arrive on a short refresh instead of instantly. '
                       + 'Every page still works normally.',
                offline: 'No connection to the server.',
            }[state] || '';
        },

        disconnect(silent) {
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
        },
    };
})();
