<?php
declare(strict_types=1);

/**
 * Attendance defaults. Every value here is overridable per-schedule (Part 16.3)
 * and editable at runtime through Settings, which writes to the `settings`
 * table. Nothing in the attendance engine may hard-code these numbers.
 */
return [
    'windows' => [
        'time_in_window_open'    => 10, // minutes before start that tap-in opens
        'late_threshold_minutes' => 15, // after start -> LATE
        'time_in_window_close'   => 30, // after start -> tap-in refused
        'time_out_window_open'   => 10, // before end that tap-out opens
        'time_out_window_close'  => 15, // after end -> tap-out refused, session auto-closes
        'minimum_dwell_minutes'  => 20, // shortest credited classroom stay
    ],

    'auto_timeout_on_close' => true,   // stamp a time-out at session end instead of Incomplete
    'auto_close_sessions'   => true,   // background worker closes expired sessions
    'generate_absent_on_close' => true,

    'section_mismatch_alert_threshold' => 3, // taps/day/student before admin notification

    'fingerprint' => [
        'max_failures_before_lock' => 5,
        'device_lock_minutes'      => 5,
        'alert_after_failures'     => 3,

        // How long an open enrolment request waits before it gives up. The
        // clock restarts on every progress report from the sensor, so this is
        // the gap between steps, not the budget for the whole capture — three
        // minutes of no contact means nobody is standing at the terminal.
        'enrollment_ttl_seconds'   => 180,

        // How often an idle terminal asks whether it has been asked to enrol
        // somebody. Two seconds is what makes "Start" feel immediate; an idle
        // ESP32 has nothing else to do, and the request is a few hundred bytes.
        'enrollment_poll_seconds'  => 2,

        // A print captured during registration is held while the rest of the
        // form is filled in. Longer than the capture timeout on purpose: the
        // person is typing a department and grade levels, not standing at a
        // sensor. When it lapses the terminal is told to delete the template,
        // because a slot holding a print nobody owns is worse than no print.
        'enrollment_hold_seconds'  => 1800,
    ],

    'rfid' => [
        // Card issuance driven from the browser, mirroring the fingerprint
        // enrolment above. The gap between steps rather than the budget for
        // the whole thing — every report from the terminal restarts it.
        'enrollment_ttl_seconds'  => 180,
        'enrollment_poll_seconds' => 2,

        // How long the terminal holds the reader to itself waiting for a card.
        // This is the whole point of the feature: while it is inside this
        // window it does not heartbeat, does not sync and does not poll, so a
        // card presented at any moment is read rather than landing in the gap
        // where the board was busy talking to the server.
        'enrollment_wait_seconds' => 45,

        // A UID that has been read waits much longer than a reader does: the
        // card is in somebody's hand and the only thing outstanding is which
        // student it belongs to. Losing it would mean walking back.
        'enrollment_hold_seconds' => 1800,
    ],

    'device' => [
        'heartbeat_interval_sec' => 30,
        'heartbeat_jitter_sec'   => 5,
        'offline_after_sec'      => 90,
        'sync_interval_sec'      => 60,
        'offline_queue_limit'    => 500,
        'queue_warning_depth'    => 100,
    ],

    'retention' => [
        'heartbeats_days'    => 90,
        'notifications_days' => 180,
        'realtime_events_hours' => 48,
        'processed_requests_hours' => 24,
        'nonces_hours'       => 24,
    ],

    'chronic_absence_threshold_percent' => 80.0,
];
