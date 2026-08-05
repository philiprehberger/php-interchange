<?php

declare(strict_types=1);

return [
    'tracing' => [
        // SPEC §3.3. Disable only if a service genuinely has no queue —
        // switching this off silently breaks cross-service traces.
        'propagate_through_queue' => env('INTERCHANGE_TRACE_QUEUE', true),
    ],

    'signing' => [
        // The Standard Webhooks spec recommends a tolerance without fixing a
        // number; 5 minutes is this contract's choice (SPEC §4.6).
        'tolerance_seconds' => (int) env('INTERCHANGE_SIGNATURE_TOLERANCE', 300),
    ],
];
