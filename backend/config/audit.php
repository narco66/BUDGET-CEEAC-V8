<?php

return [
    'fuseau' => env('AUDIT_FUSEAU', 'Africa/Libreville'),
    'sensibles' => [
        'password', 'password_confirmation', 'remember_token', 'token', 'secret',
        'api_token', 'access_token', 'refresh_token', 'private_key', 'cookie',
    ],
];
