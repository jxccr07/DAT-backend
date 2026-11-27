<?php

return [

    // A student counts as "delayed" when they are this many credit hours (or more)
    // behind their study plan: roughly one full semester of courses.
    'delayed_gap_hours' => (int) env('TWIN_DELAYED_GAP_HOURS', 15),

    // Outcomes are measured this many terms after the decision.
    'horizon_terms' => 2,

    // Never show a comparison built on fewer students than this.
    'min_cohort' => (int) env('TWIN_MIN_COHORT', 100),

];
