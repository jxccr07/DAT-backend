<?php

return [

    'registration' => [
        // Max/min credit hours a student may register in one semester.
        'max_hours' => (int) env('REGISTRATION_MAX_HOURS', 18),
        'min_hours' => (int) env('REGISTRATION_MIN_HOURS', 12),

        // Most a student may take in a summer term (used to show catching up over the summer).
        'summer_max_hours' => (int) env('REGISTRATION_SUMMER_MAX_HOURS', 8),

        // Last day to drop a course this term. Set DROP_DEADLINE (YYYY-MM-DD) to the
        // university calendar; otherwise it is this many days before the term ends.
        'drop_deadline' => env('DROP_DEADLINE'),
        'drop_deadline_days_before_end' => (int) env('DROP_DEADLINE_DAYS_BEFORE_END', 35),

        // When true, add/drop is only allowed between the active semester's
        // registration_starts_at and registration_ends_at.
        'enforce_window' => (bool) env('REGISTRATION_ENFORCE_WINDOW', false),
    ],

    'demo' => [
        // Exposes the one-click demo sign-ins (GET /api/v1/demo-student, /demo-advisor).
        'enabled' => (bool) env('DEMO_MODE', false),
        'password' => env('DEMO_PASSWORD', 'password'),
        // Production: the one advisor whose students the demo buttons use. Only these
        // accounts keep a usable password after `php artisan demo:secure-accounts`.
        'advisor_email' => env('DEMO_ADVISOR_EMAIL'),
    ],

];
