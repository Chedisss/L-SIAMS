/*
 * Tap sounds for the teacher.
 *
 * The classroom terminal gives whoever taps no beep of its own, so a teacher
 * had no way to tell a recorded tap from one that never went through. Now that
 * the dashboards run on the live WebSocket, this plays a short, distinct tone
 * on each attendance event — wherever the teacher is in their portal, not only
 * on the dashboard page — so a scan is audible from across the room:
 *
 *   attendance.time_in   rising two-note chime  — recorded
 *   attendance.time_out  gentle descending pair — tapped out
 *   attendance.rejected  low buzz               — refused (unknown card,
 *                                                  duplicate, wrong section,
 *                                                  tapped out too soon, …)
 *
 * The tones are synthesised with the Web Audio API rather than loaded from
 * files: nothing to fetch, and nothing for the page's content-security policy
 * to block. Teacher accounts only — an administrator watching the security
 * centre does not want a chime on every tap in the building.
 */
(function () {
    const LS = window.LSIAMS = window.LSIAMS || {};

    const TapSound = {
        ctx: null,
        muted: false,
        bound: false,

        load() {
            try { this.muted = localStorage.getItem('lsiams-tap-muted') === '1'; } catch (e) { /* ignore */ }
        },

        save() {
            try { localStorage.setItem('lsiams-tap-muted', this.muted ? '1' : '0'); } catch (e) { /* ignore */ }
        },

        // Browsers keep an AudioContext suspended until the page has had a user
        // gesture. Creating/resuming it here — from the first interaction, and
        // again on each sound — is what lets it ever play.
        context() {
            if (this.ctx === null) {
                const Ctor = window.AudioContext || window.webkitAudioContext;
                if (!Ctor) return null;
                try { this.ctx = new Ctor(); } catch (e) { return null; }
            }
            if (this.ctx.state === 'suspended') { this.ctx.resume().catch(function () {}); }
            return this.ctx;
        },

        // A short sequence of notes, each fading in and out so it reads as a
        // soft chime rather than a click.
        play(notes, type, peak) {
            if (this.muted) return;
            const ctx = this.context();
            if (!ctx) return;

            let at = ctx.currentTime;

            notes.forEach(function (note) {
                const osc  = ctx.createOscillator();
                const gain = ctx.createGain();

                osc.type = type || 'sine';
                osc.frequency.value = note.f;
                osc.connect(gain);
                gain.connect(ctx.destination);

                const end = at + note.d;
                gain.gain.setValueAtTime(0.0001, at);
                gain.gain.exponentialRampToValueAtTime(peak || 0.16, at + 0.012);
                gain.gain.exponentialRampToValueAtTime(0.0001, end);

                osc.start(at);
                osc.stop(end + 0.02);

                at = end;
            });
        },

        accepted()  { this.play([{ f: 880, d: 0.10 }, { f: 1320, d: 0.13 }], 'sine', 0.16); },
        tappedOut() { this.play([{ f: 1175, d: 0.09 }, { f: 880, d: 0.11 }], 'sine', 0.13); },
        refused()   { this.play([{ f: 233, d: 0.20 }, { f: 175, d: 0.22 }], 'square', 0.12); },

        // One interaction anywhere unlocks audio for the rest of the visit.
        unlock() {
            const self = this;
            function once() {
                self.context();
                window.removeEventListener('pointerdown', once);
                window.removeEventListener('keydown', once);
            }
            window.addEventListener('pointerdown', once);
            window.addEventListener('keydown', once);
        },

        // Attach the sound to the live feed. Guarded so repeated calls (or a
        // page that also binds its own handlers) never double up the tone.
        bindRealtime() {
            if (this.bound || !LS.realtime) return;
            this.bound = true;

            const self = this;
            LS.realtime
                .on('attendance.time_in',  function () { self.accepted(); })
                .on('attendance.time_out', function () { self.tappedOut(); })
                .on('attendance.rejected', function () { self.refused(); });
        },

        mountToggle(el) {
            if (!el) return;
            const self = this;

            function paint() {
                const icon = el.querySelector('i');
                if (icon) icon.className = self.muted ? 'fa-solid fa-volume-xmark' : 'fa-solid fa-volume-high';
                el.setAttribute('aria-pressed', self.muted ? 'false' : 'true');
                el.title = self.muted ? 'Tap sound is off' : 'Sound on each recorded tap';
            }

            paint();

            el.addEventListener('click', function () {
                self.muted = !self.muted;
                self.save();
                paint();
                // Unmuting plays a sample so the level is heard, and the click
                // itself is the gesture that unlocks audio.
                if (!self.muted) self.accepted();
            });
        },
    };

    LS.tapSound = TapSound;
    TapSound.load();

    document.addEventListener('DOMContentLoaded', function () {
        // Teacher accounts only, but on every page they visit.
        if (!LS.config || LS.config.role !== 'teacher') return;

        TapSound.unlock();
        TapSound.bindRealtime();
        TapSound.mountToggle(document.getElementById('tap-sound-toggle'));
    });
})();
