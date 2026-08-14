/* ==========================================================================
   L-SIAMS core front-end
   Plain ES2020 modules-in-a-namespace. No framework, no bundler, no CDN —
   the server may have no outbound internet access at all.
   ========================================================================== */

(function () {
    'use strict';

    const LS = window.LSIAMS = window.LSIAMS || {};

    /* ------------------------------------------------------------ config -- */

    LS.config = Object.assign({
        csrfToken: '',
        idleTimeoutMinutes: 10,
        warnSecondsBefore: 60,
        realtimeUrl: '',
        pollIntervalMs: 3000,
        role: 'guest',
    }, window.__LSIAMS_CONFIG__ || {});

    /* --------------------------------------------------------------- http -- */

    /**
     * Fetch wrapper.
     *
     * Two things every call gets for free: the CSRF token on state-changing
     * requests, and a global 401 handler that redirects to the login page
     * instead of letting the UI silently break when a session expires
     * (Part 18.3).
     */
    LS.http = {
        async request(url, options = {}) {
            const opts = Object.assign({ method: 'GET', headers: {} }, options);

            opts.headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            }, opts.headers);

            if (!['GET', 'HEAD'].includes(opts.method.toUpperCase())) {
                opts.headers['X-CSRF-Token'] = LS.config.csrfToken;
            }

            // Background refreshes must not extend the session, so they are
            // marked and the server honours the marker.
            if (opts.passive) {
                opts.headers['X-Passive-Request'] = 'true';
                delete opts.passive;
            }

            // A GET or HEAD may not carry a body — fetch() throws a TypeError
            // rather than sending it, and that TypeError is indistinguishable
            // from a genuine network failure by the time it reaches the catch
            // below. Moving the values to the query string keeps the caller
            // working instead of failing in a way that points at the network.
            if (opts.body && ['GET', 'HEAD'].includes(opts.method.toUpperCase())) {
                if (!(opts.body instanceof FormData) && typeof opts.body === 'object') {
                    const query = this.query(opts.body);

                    if (query !== '') url += (url.includes('?') ? '&' : '?') + query;
                }

                delete opts.body;
            }

            if (opts.body && !(opts.body instanceof FormData) && typeof opts.body === 'object') {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(opts.body);
            }

            opts.credentials = 'same-origin';

            let response;

            try {
                response = await fetch(url, opts);
            } catch (error) {
                throw { code: 'NETWORK_ERROR', message: 'Could not reach the server. Check your network connection.' };
            }

            let payload = null;

            const contentType = response.headers.get('content-type') || '';

            if (contentType.includes('application/json')) {
                try { payload = await response.json(); } catch (e) { payload = null; }
            }

            if (response.status === 401) {
                const code = payload && payload.code;

                if (code === 'SESSION_EXPIRED' || code === 'SESSION_TERMINATED' || code === 'SESSION_INVALID') {
                    LS.session.forceLogout(code === 'SESSION_EXPIRED' ? 'idle_timeout' : 'terminated');
                    throw { code: code, message: 'Session ended.', handled: true };
                }
            }

            if (response.status === 403 && payload && payload.code === 'PASSWORD_CHANGE_REQUIRED') {
                window.location.href = '/password/change';
                throw { code: payload.code, message: payload.message, handled: true };
            }

            if (!response.ok) {
                throw {
                    code: (payload && payload.code) || 'HTTP_' + response.status,
                    message: (payload && payload.message) || 'The request failed (' + response.status + ').',
                    errors: payload && payload.data && payload.data.errors,
                    status: response.status,
                    payload: payload,
                };
            }

            return payload;
        },

        /**
         * Query-string encoding that survives arrays.
         *
         * URLSearchParams stringifies an array value into one comma-joined
         * field, which arrives server-side as the string "3,4" rather than as
         * two values — so a multi-select filter silently matches nothing.
         * Repeating the key with PHP's `[]` suffix is what the request layer
         * already parses into an array.
         */
        query(params) {
            const search = new URLSearchParams();

            Object.entries(params || {}).forEach(([key, value]) => {
                if (value === null || value === undefined || value === '') return;

                if (Array.isArray(value)) {
                    value.forEach((item) => {
                        if (item !== null && item !== undefined && item !== '') {
                            search.append(key + '[]', String(item));
                        }
                    });

                    return;
                }

                search.append(key, String(value));
            });

            return search.toString();
        },

        get(url, params, options = {}) {
            const query = params ? this.query(params) : '';

            return this.request(
                url + (query === '' ? '' : (url.includes('?') ? '&' : '?') + query),
                Object.assign({ method: 'GET' }, options)
            );
        },

        post(url, body, options = {}) {
            return this.request(url, Object.assign({ method: 'POST', body: body }, options));
        },

        put(url, body, options = {}) {
            return this.request(url, Object.assign({ method: 'PUT', body: body }, options));
        },

        /**
         * Multipart upload. The FormData is passed through untouched so the
         * browser sets its own boundary; the CSRF token still travels in the
         * header like every other state-changing call.
         */
        upload(url, formData, options = {}) {
            return this.request(url, Object.assign({ method: 'POST', body: formData }, options));
        },

        /** Passive helper: never counts as user activity. */
        poll(url, params) {
            return this.get(url, params, { passive: true });
        },
    };

    /* ------------------------------------------------------------- toasts -- */

    LS.toast = {
        stack: null,

        ensureStack() {
            if (!this.stack) {
                this.stack = document.createElement('div');
                this.stack.className = 'toast-stack';
                this.stack.setAttribute('role', 'status');
                this.stack.setAttribute('aria-live', 'polite');
                document.body.appendChild(this.stack);
            }
            return this.stack;
        },

        /* Re-arm a toast's dismissal, replacing any timer already running. */
        arm(toast, timeout) {
            if (toast._lsTimer) {
                clearTimeout(toast._lsTimer);
                toast._lsTimer = null;
            }

            toast.style.opacity = '';

            if (timeout > 0) {
                toast._lsTimer = setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 200);
                }, timeout);
            }
        },

        show(message, type = 'info', title = null, timeout = 5000) {
            const stack = this.ensureStack();

            // Pressing Save on a form that will not validate produces the same
            // message every time. Six identical toasts stacked down the screen
            // say nothing the first one did not, and bury the field errors that
            // actually name the problem. Repeats reset the existing toast and
            // count up on it instead.
            const key      = type + ' ' + (title || '') + ' ' + message;
            const existing = Array.prototype.find.call(
                stack.children,
                (node) => node._lsKey === key
            );

            if (existing) {
                existing._lsCount = (existing._lsCount || 1) + 1;

                let badge = existing.querySelector('.toast__count');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'toast__count';
                    existing.querySelector('.toast__body').appendChild(badge);
                }
                badge.textContent = '× ' + existing._lsCount;

                this.arm(existing, timeout);
                return existing;
            }

            const toast = document.createElement('div');
            toast.className = 'toast toast--' + type;
            toast._lsKey   = key;
            toast._lsCount = 1;

            const body = document.createElement('div');
            body.className = 'toast__body';

            if (title) {
                const heading = document.createElement('div');
                heading.className = 'toast__title';
                heading.textContent = title;
                body.appendChild(heading);
            }

            // textContent, never innerHTML — server messages can contain data.
            const text = document.createElement('div');
            text.textContent = message;
            body.appendChild(text);

            const close = document.createElement('button');
            close.className = 'toast__close';
            close.setAttribute('aria-label', 'Dismiss');
            close.innerHTML = '&times;';
            close.onclick = () => toast.remove();

            toast.appendChild(body);
            toast.appendChild(close);
            stack.appendChild(toast);

            // A burst of distinct messages should not fill the viewport either.
            while (stack.children.length > 4) {
                stack.removeChild(stack.firstElementChild);
            }

            this.arm(toast, timeout);

            return toast;
        },

        success(message, title) { return this.show(message, 'success', title || 'Success'); },
        error(message, title)   { return this.show(message, 'danger', title || 'Error', 8000); },
        warning(message, title) { return this.show(message, 'warning', title || 'Warning', 7000); },
        info(message, title)    { return this.show(message, 'info', title); },

        /** Render a thrown http error consistently, including field errors. */
        fromError(error) {
            if (error && error.handled) {
                return;
            }

            if (error && error.errors) {
                const messages = Object.values(error.errors).flat();
                this.error(messages.join(' '), 'Please check the form');
                return;
            }

            this.error((error && error.message) || 'Something went wrong.');
        },
    };

    /* ------------------------------------------------------------- modals -- */

    LS.modal = {
        open(id, data) {
            const el = document.getElementById(id);
            if (!el) return null;

            el.classList.add('open');
            document.body.style.overflow = 'hidden';

            if (data) {
                LS.modal.fill(el, data);
            }

            const focusable = el.querySelector('[autofocus], input:not([type=hidden]), select, textarea, button');
            if (focusable) setTimeout(() => focusable.focus(), 60);

            el.dispatchEvent(new CustomEvent('modal:open', { detail: data }));
            return el;
        },

        close(id) {
            const el = typeof id === 'string' ? document.getElementById(id) : id;
            if (!el) return;

            el.classList.remove('open');

            if (!document.querySelector('.modal-backdrop.open')) {
                document.body.style.overflow = '';
            }

            el.dispatchEvent(new CustomEvent('modal:close'));
        },

        closeAll() {
            document.querySelectorAll('.modal-backdrop.open').forEach((el) => this.close(el));
        },

        /** Populate [data-field] elements and matching form inputs. */
        fill(el, data) {
            Object.entries(data || {}).forEach(([key, value]) => {
                el.querySelectorAll('[data-field="' + key + '"]').forEach((node) => {
                    node.textContent = value === null || value === undefined || value === '' ? '—' : value;
                });

                const input = el.querySelector('[name="' + key + '"]');

                if (input) {
                    if (input.type === 'checkbox') {
                        input.checked = !!value;
                    } else {
                        input.value = value === null || value === undefined ? '' : value;
                    }
                }
            });
        },

        confirm(options) {
            return new Promise((resolve) => {
                const opts = Object.assign({
                    title: 'Confirm',
                    message: 'Are you sure?',
                    confirmLabel: 'Confirm',
                    cancelLabel: 'Cancel',
                    danger: false,
                    requirePassword: false,
                    requirePhrase: null,
                }, options);

                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop open';

                const passwordField = opts.requirePassword
                    ? '<div class="form-group"><label for="confirm-password-field">Confirm with your password</label>'
                      + '<input type="password" id="confirm-password-field" autocomplete="current-password" required>'
                      + '<span class="field-help">This action is sensitive and requires re-authentication.</span></div>'
                    : '';

                const phraseField = opts.requirePhrase
                    ? '<div class="form-group"><label for="confirm-phrase-field">Type <strong>' + opts.requirePhrase + '</strong> to continue</label>'
                      + '<input type="text" id="confirm-phrase-field" autocomplete="off" required></div>'
                    : '';

                backdrop.innerHTML =
                    '<div class="modal modal--sm" role="dialog" aria-modal="true">'
                    + '<div class="modal__header"><h3 class="modal__title"></h3></div>'
                    + '<div class="modal__body"><p class="confirm-message"></p>' + passwordField + phraseField + '</div>'
                    + '<div class="modal__footer">'
                    + '<button type="button" class="btn btn-secondary" data-act="cancel"></button>'
                    + '<button type="button" class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + '" data-act="ok"></button>'
                    + '</div></div>';

                backdrop.querySelector('.modal__title').textContent = opts.title;
                backdrop.querySelector('.confirm-message').textContent = opts.message;
                backdrop.querySelector('[data-act=cancel]').textContent = opts.cancelLabel;
                backdrop.querySelector('[data-act=ok]').textContent = opts.confirmLabel;

                document.body.appendChild(backdrop);
                document.body.style.overflow = 'hidden';

                const cleanup = (result) => {
                    backdrop.remove();
                    document.body.style.overflow = '';
                    resolve(result);
                };

                backdrop.querySelector('[data-act=cancel]').onclick = () => cleanup(false);

                backdrop.querySelector('[data-act=ok]').onclick = () => {
                    const password = backdrop.querySelector('#confirm-password-field');
                    const phrase   = backdrop.querySelector('#confirm-phrase-field');

                    if (opts.requirePassword && (!password || !password.value)) {
                        LS.toast.warning('Enter your password to confirm.');
                        return;
                    }

                    if (opts.requirePhrase && (!phrase || phrase.value !== opts.requirePhrase)) {
                        LS.toast.warning('Type ' + opts.requirePhrase + ' exactly to continue.');
                        return;
                    }

                    cleanup({
                        confirmed: true,
                        password: password ? password.value : null,
                        phrase: phrase ? phrase.value : null,
                    });
                };

                setTimeout(() => {
                    const first = backdrop.querySelector('input') || backdrop.querySelector('[data-act=ok]');
                    if (first) first.focus();
                }, 60);
            });
        },
    };

    /* ------------------------------------------------------------ helpers -- */

    LS.util = {
        debounce(fn, wait = 300) {
            let timer = null;
            return function (...args) {
                clearTimeout(timer);
                timer = setTimeout(() => fn.apply(this, args), wait);
            };
        },

        escape(value) {
            const div = document.createElement('div');
            div.textContent = value === null || value === undefined ? '' : String(value);
            return div.innerHTML;
        },

        formatTime(value) {
            if (!value) return '—';
            const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
            if (isNaN(date)) return value;
            return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        },

        formatDate(value) {
            if (!value) return '—';
            const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
            if (isNaN(date)) return value;
            return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
        },

        statusBadge(status) {
            const map = {
                'Present': 'badge-success', 'Late': 'badge-warning', 'Absent': 'badge-danger',
                'Left Early': 'badge-warning', 'Incomplete': 'badge-warning',
                'Excused': 'badge-info', 'Official Business': 'badge-info',
            };
            return map[status] || 'badge-neutral';
        },

        initials(name) {
            if (!name) return '?';
            return name.replace(/,/g, ' ').split(/\s+/).filter(Boolean).slice(0, 2)
                .map((part) => part[0].toUpperCase()).join('');
        },

        /** Serialise a form, collecting multi-selects and checkbox groups. */
        formData(form) {
            const data = {};

            new FormData(form).forEach((value, key) => {
                if (key.endsWith('[]')) {
                    const cleanKey = key.slice(0, -2);
                    (data[cleanKey] = data[cleanKey] || []).push(value);
                } else if (data[key] !== undefined) {
                    data[key] = [].concat(data[key], value);
                } else {
                    data[key] = value;
                }
            });

            form.querySelectorAll('input[type=checkbox]:not(:checked)').forEach((box) => {
                if (!box.name.endsWith('[]') && data[box.name] === undefined) {
                    data[box.name] = false;
                }
            });

            return data;
        },

        showFieldErrors(form, errors) {
            form.querySelectorAll('.field-error[data-generated]').forEach((el) => el.remove());
            form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));

            Object.entries(errors || {}).forEach(([field, messages]) => {
                const input = form.querySelector('[name="' + field + '"], [name="' + field + '[]"]');
                if (!input) return;

                input.classList.add('is-invalid');

                const span = document.createElement('span');
                span.className = 'field-error';
                span.dataset.generated = 'true';
                span.textContent = [].concat(messages)[0];
                (input.closest('.form-group') || input.parentNode).appendChild(span);
            });

            const firstInvalid = form.querySelector('.is-invalid');
            if (firstInvalid) firstInvalid.focus();
        },

        setBusy(button, busy, busyLabel = 'Working…') {
            if (!button) return;

            if (busy) {
                button.dataset.originalLabel = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span> ' + busyLabel;
            } else {
                button.disabled = false;
                if (button.dataset.originalLabel) {
                    button.innerHTML = button.dataset.originalLabel;
                    delete button.dataset.originalLabel;
                }
            }
        },

        copy(text, label = 'Copied to clipboard.') {
            const done = () => LS.toast.success(label);

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(() => LS.util.copyFallback(text, done));
            } else {
                LS.util.copyFallback(text, done);
            }
        },

        copyFallback(text, done) {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (e) { LS.toast.error('Copy failed. Select the text manually.'); }
            area.remove();
        },

        /** Update the query string without a reload, for shareable filter state. */
        syncQuery(params) {
            const url = new URL(window.location.href);

            Object.entries(params).forEach(([key, value]) => {
                if (value === null || value === undefined || value === '' || value === false) {
                    url.searchParams.delete(key);
                } else {
                    url.searchParams.set(key, value);
                }
            });

            window.history.replaceState({}, '', url);
        },
    };

    /* -------------------------------------------------------------- theme -- */

    LS.theme = {
        init() {
            const stored = localStorage.getItem('lsiams-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            this.apply(stored || (prefersDark ? 'dark' : 'light'));

            const toggle = document.getElementById('theme-toggle');

            if (toggle) {
                toggle.addEventListener('click', () => {
                    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                    this.apply(next);
                    localStorage.setItem('lsiams-theme', next);
                });
            }
        },

        apply(theme) {
            document.documentElement.dataset.theme = theme;

            const icon = document.querySelector('#theme-toggle i');
            if (icon) icon.className = theme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        },
    };

    /* -------------------------------------------------------------- shell -- */

    LS.shell = {
        init() {
            const sidebar = document.querySelector('.sidebar');
            const toggle  = document.querySelector('.topbar__toggle');

            if (toggle && sidebar) {
                toggle.addEventListener('click', (event) => {
                    event.stopPropagation();
                    sidebar.classList.toggle('open');
                });

                document.addEventListener('click', (event) => {
                    if (window.innerWidth <= 1024
                        && sidebar.classList.contains('open')
                        && !sidebar.contains(event.target)) {
                        sidebar.classList.remove('open');
                    }
                });
            }

            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-dropdown]');

                document.querySelectorAll('.dropdown__menu.open').forEach((menu) => {
                    if (!trigger || menu.id !== trigger.dataset.dropdown) {
                        menu.classList.remove('open');
                    }
                });

                if (trigger) {
                    event.preventDefault();
                    const menu = document.getElementById(trigger.dataset.dropdown);
                    if (menu) menu.classList.toggle('open');
                }
            });

            document.addEventListener('click', (event) => {
                const opener = event.target.closest('[data-modal-open]');
                if (opener) {
                    event.preventDefault();
                    LS.modal.open(opener.dataset.modalOpen, opener.dataset.modalData ? JSON.parse(opener.dataset.modalData) : null);
                }

                const closer = event.target.closest('[data-modal-close]');
                if (closer) {
                    event.preventDefault();
                    LS.modal.close(closer.closest('.modal-backdrop'));
                }

                // Clicking the backdrop closes, clicking the panel does not.
                if (event.target.classList.contains('modal-backdrop')) {
                    LS.modal.close(event.target);
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    LS.modal.closeAll();
                    document.querySelectorAll('.dropdown__menu.open').forEach((m) => m.classList.remove('open'));
                }

                // "/" focuses global search, unless the user is already typing.
                if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
                    const search = document.getElementById('global-search');
                    if (search) { event.preventDefault(); search.focus(); }
                }
            });

            this.startClock();
            this.initSearch();
            this.initCopyButtons();
        },

        startClock() {
            const clock = document.getElementById('live-clock');
            if (!clock) return;

            const tick = () => {
                clock.textContent = new Date().toLocaleString([], {
                    weekday: 'short', month: 'short', day: 'numeric',
                    hour: 'numeric', minute: '2-digit',
                });
            };

            tick();
            setInterval(tick, 30000);
        },

        initSearch() {
            const input   = document.getElementById('global-search');
            const results = document.getElementById('search-results');
            if (!input || !results) return;

            const run = LS.util.debounce(async (query) => {
                if (query.length < 2) {
                    results.classList.remove('open');
                    return;
                }

                try {
                    const response = await LS.http.get('/api/search', { q: query });
                    const rows = (response.data && response.data.results) || [];

                    results.innerHTML = '';

                    if (rows.length === 0) {
                        results.innerHTML = '<div class="search__result text-muted">No matches found.</div>';
                    } else {
                        rows.forEach((row) => {
                            const link = document.createElement('a');
                            link.className = 'search__result';
                            link.href = row.url;
                            link.innerHTML =
                                '<span class="search__result-type"></span>'
                                + '<span class="search__result-label"></span>'
                                + '<span class="search__result-meta"></span>';
                            link.querySelector('.search__result-type').textContent = row.type;
                            link.querySelector('.search__result-label').textContent = row.label;
                            link.querySelector('.search__result-meta').textContent = row.meta || '';
                            results.appendChild(link);
                        });
                    }

                    results.classList.add('open');
                } catch (error) {
                    results.classList.remove('open');
                }
            }, 250);

            input.addEventListener('input', (event) => run(event.target.value.trim()));

            document.addEventListener('click', (event) => {
                if (!input.contains(event.target) && !results.contains(event.target)) {
                    results.classList.remove('open');
                }
            });
        },

        initCopyButtons() {
            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-copy]');
                if (!button) return;

                event.preventDefault();

                const source = button.dataset.copy.startsWith('#')
                    ? document.querySelector(button.dataset.copy)
                    : null;

                LS.util.copy(source ? source.textContent.trim() : button.dataset.copy);
            });
        },
    };

    /* ------------------------------------------------------- table helper -- */

    /**
     * AJAX table controller: search, filter, sort and paginate without a page
     * reload, with a shimmer while in flight (Part 7).
     */
    LS.table = function (options) {
        const config = Object.assign({
            endpoint: '',
            body: null,
            render: null,
            filters: {},
            onLoad: null,
            perPage: 25,
        }, options);

        let state = { page: 1, per_page: config.perPage, sort: config.sort || '', direction: config.direction || 'ASC' };

        const controller = {
            state: state,

            async load(overrides = {}) {
                Object.assign(state, overrides);

                const body = config.body;

                if (body) {
                    body.classList.add('is-loading');
                    body.style.opacity = '.55';
                }

                try {
                    const params = Object.assign({}, config.filters(), state);
                    const response = await LS.http.get(config.endpoint, params);

                    // The address bar is updated before rendering, not after.
                    // A render callback is allowed to reload the page — the
                    // students list does exactly that — and a reload that
                    // happened before the query string was synced would fetch
                    // page 2 and then reload page 1, so paging appeared to do
                    // nothing at all.
                    LS.util.syncQuery(params);

                    if (config.render) {
                        config.render(response.data.rows, response.data.pagination);
                    }

                    if (config.onLoad) config.onLoad(response.data);
                } catch (error) {
                    LS.toast.fromError(error);
                } finally {
                    if (body) {
                        body.classList.remove('is-loading');
                        body.style.opacity = '';
                    }
                }
            },

            sort(column) {
                state.direction = state.sort === column && state.direction === 'ASC' ? 'DESC' : 'ASC';
                state.sort = column;
                state.page = 1;
                return this.load();
            },

            page(number) { return this.load({ page: number }); },
            refresh()    { return this.load(); },
            reset()      { return this.load({ page: 1 }); },
        };

        return controller;
    };

    /* -------------------------------------------------- unsaved-work guard -- */

    /**
     * Part 18.5 — forms auto-draft to sessionStorage so a forced logout never
     * costs the user their work. Passwords and key material are never drafted.
     */
    LS.drafts = {
        SENSITIVE: ['password', 'confirm_password', 'current_password', 'password_confirmation', 'api_key', 'hmac_secret', '_csrf'],

        init() {
            document.querySelectorAll('form[data-draft]').forEach((form) => this.attach(form));
            this.restoreAll();
        },

        key(form) {
            return 'lsiams-draft:' + LS.config.userId + ':' + form.dataset.draft;
        },

        attach(form) {
            const save = LS.util.debounce(() => this.save(form), 800);

            form.addEventListener('input', () => { form.dataset.dirty = 'true'; save(); });
            form.addEventListener('submit', () => { delete form.dataset.dirty; this.clear(form); });

            setInterval(() => { if (form.dataset.dirty === 'true') this.save(form); }, 15000);
        },

        save(form) {
            const data = {};

            new FormData(form).forEach((value, name) => {
                if (this.SENSITIVE.some((s) => name.includes(s))) return;
                data[name] = value;
            });

            try {
                sessionStorage.setItem(this.key(form), JSON.stringify({
                    savedAt: Date.now(),
                    data: data,
                }));
            } catch (e) { /* storage full or unavailable — drafting is best-effort */ }
        },

        restoreAll() {
            document.querySelectorAll('form[data-draft]').forEach((form) => {
                let stored;

                try { stored = JSON.parse(sessionStorage.getItem(this.key(form))); } catch (e) { return; }

                if (!stored || !stored.data) return;

                // Drafts expire after 24 hours.
                if (Date.now() - stored.savedAt > 86400000) {
                    this.clear(form);
                    return;
                }

                let restored = 0;

                Object.entries(stored.data).forEach(([name, value]) => {
                    const field = form.elements[name];
                    if (field && !field.value && value) { field.value = value; restored++; }
                });

                if (restored > 0) {
                    LS.toast.info('Your unsaved changes were restored.', 'Draft recovered');
                }
            });
        },

        clear(form) {
            try { sessionStorage.removeItem(this.key(form)); } catch (e) { /* no-op */ }
        },

        hasDirtyForms() {
            return document.querySelector('form[data-dirty="true"]') !== null;
        },
    };

    /* --------------------------------------------------------------- boot -- */

    /* -------------------------------------------------- declarative behaviours -- */

    /**
     * Replaces the inline `onclick=` / `onchange=` / `onsubmit=` attributes the
     * views used to carry.
     *
     * A Content-Security-Policy nonce authorises a <script> block; it does not
     * authorise an inline event-handler attribute — nothing does, short of
     * 'unsafe-inline', which would undo the whole policy. So every handler that
     * used to live in an attribute is expressed as a data attribute here and
     * bound once, by delegation, which also means markup injected later (a
     * re-rendered table body, a fresh page of results) is wired automatically
     * without any re-binding call.
     */
    LS.behaviors = {
        init() {
            // Filter bars submit nothing: they re-query over AJAX. The attribute
            // marks the form so the default navigation is suppressed.
            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (form instanceof Element && form.matches('form[data-no-submit]')) event.preventDefault();
            });

            document.addEventListener('click', (event) => {
                const el = event.target instanceof Element
                    ? event.target.closest('[data-action]')
                    : null;

                if (!el) return;

                switch (el.dataset.action) {
                    case 'clear-filters':
                        // Drop the query string; the page re-renders unfiltered.
                        event.preventDefault();
                        window.location.href = window.location.pathname;
                        break;
                    case 'history-back':
                        event.preventDefault();
                        history.back();
                        break;
                    default:
                        break;
                }
            });

            // Fields drawn in uppercase are uppercased for real.
            //
            // text-transform:uppercase is a painting instruction: the box shows
            // MATH-101 while the value stays "math-101", and the two only
            // diverge at the moment it matters — submission. Doing it to the
            // value as well means what is on screen is what is sent, and what
            // is stored.
            document.addEventListener('input', (event) => {
                const el = event.target instanceof Element
                    ? event.target.closest('input[data-uppercase]')
                    : null;

                if (!el) return;

                const upper = el.value.toUpperCase();

                if (upper === el.value) return;

                // Assigning to value moves the caret to the end, which makes
                // editing the middle of a code impossible. Put it back.
                const start = el.selectionStart;
                const end   = el.selectionEnd;

                el.value = upper;

                if (start !== null && end !== null) el.setSelectionRange(start, end);
            });

            // Any control marked data-filter-input announces a change; each page
            // listens for the event and runs its own query. Announcing rather
            // than calling a named global keeps the page's controller private to
            // the page and out of the window namespace.
            document.addEventListener('change', (event) => {
                const el = event.target instanceof Element
                    ? event.target.closest('[data-filter-input]')
                    : null;

                if (!el) return;

                document.dispatchEvent(new CustomEvent('ls:filter-change', { detail: { field: el } }));
            });

            this.initPagination();
        },

        /**
         * Resolve a dotted path such as "window.tableController" against the
         * global object. Property lookup only — never eval, which the policy
         * forbids and which would reintroduce exactly the injection risk the
         * nonce exists to prevent.
         */
        resolve(path) {
            if (!path) return null;

            return String(path).split('.').reduce(
                (obj, key) => (obj == null ? null : (key === 'window' ? obj : obj[key])),
                window
            );
        },

        /**
         * Navigate by query string. The fallback for a pagination block whose
         * page did not register a controller — previously those blocks called a
         * name that did not exist and the buttons did nothing at all.
         */
        navigateWithParams(params) {
            const query = new URLSearchParams(window.location.search);
            Object.entries(params).forEach(([key, value]) => query.set(key, String(value)));
            window.location.search = query.toString();
        },

        initPagination() {
            document.addEventListener('click', (event) => {
                const link = event.target instanceof Element
                    ? event.target.closest('[data-page]')
                    : null;

                if (!link || link.disabled) return;

                const page       = parseInt(link.dataset.page, 10);
                const controller = this.resolve(link.closest('[data-pagination-target]')?.dataset.paginationTarget);

                if (controller && typeof controller.page === 'function') {
                    controller.page(page);
                } else {
                    this.navigateWithParams({ page: page });
                }
            });

            document.addEventListener('change', (event) => {
                const select = event.target instanceof Element
                    ? event.target.closest('[data-per-page]')
                    : null;

                if (!select) return;

                const perPage    = parseInt(select.value, 10);
                const controller = this.resolve(select.closest('[data-pagination-target]')?.dataset.paginationTarget);

                if (controller && typeof controller.load === 'function') {
                    controller.load({ per_page: perPage, page: 1 });
                } else {
                    this.navigateWithParams({ per_page: perPage, page: 1 });
                }
            });
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        LS.theme.init();
        LS.shell.init();
        LS.drafts.init();

        if (LS.session && LS.session.init) LS.session.init();
        if (LS.realtime && LS.realtime.init && LS.config.realtimeEnabled) LS.realtime.init();

        // Warn before leaving a page with unsaved changes (Part 7).
        window.addEventListener('beforeunload', (event) => {
            if (LS.drafts.hasDirtyForms()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        // Progressive enhancement: any form with data-ajax submits over fetch.
        document.addEventListener('submit', async (event) => {
            const form = event.target;
            if (!form.matches('form[data-ajax]')) return;

            event.preventDefault();

            const button = form.querySelector('[type=submit]');
            LS.util.setBusy(button, true, form.dataset.busyLabel || 'Saving…');

            try {
                // form.getAttribute('method'), not form.method. The property
                // reflects the *effective* method and returns "get" when the
                // attribute is absent, so `form.method || 'POST'` never reached
                // its fallback — every data-ajax form without an explicit
                // method="post" was sent as a GET carrying a JSON body, which
                // fetch() rejects outright with "Request with GET/HEAD method
                // cannot have body". That surfaced to the user as "Could not
                // reach the server", which is exactly the wrong thing to look
                // at. The attribute is null when unset, so the fallback works.
                const method = (form.dataset.method || form.getAttribute('method') || 'POST').toUpperCase();

                const response = await LS.http.request(form.action, {
                    method: method,
                    body: LS.util.formData(form),
                });

                delete form.dataset.dirty;
                LS.drafts.clear(form);
                LS.toast.success(response.message || 'Saved.');

                form.dispatchEvent(new CustomEvent('ajax:success', { detail: response }));

                if (form.dataset.redirect) {
                    setTimeout(() => { window.location.href = form.dataset.redirect; }, 600);
                } else if (response.data && response.data.redirect) {
                    setTimeout(() => { window.location.href = response.data.redirect; }, 600);
                } else if (form.dataset.reload === 'true') {
                    setTimeout(() => window.location.reload(), 600);
                }
            } catch (error) {
                if (error.errors) LS.util.showFieldErrors(form, error.errors);
                LS.toast.fromError(error);
                form.dispatchEvent(new CustomEvent('ajax:error', { detail: error }));
            } finally {
                LS.util.setBusy(button, false);
            }
        });

        LS.behaviors.init();
    });
})();
