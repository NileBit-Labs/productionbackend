<?php

return [
    // New accounts are invite-only in production unless this is explicitly enabled.
    // Local and test environments keep registration available for development workflows.
    'public_registration' => filter_var(
        env('PUBLIC_REGISTRATION', env('APP_ENV', 'production') !== 'production'),
        FILTER_VALIDATE_BOOL,
    ),
];
