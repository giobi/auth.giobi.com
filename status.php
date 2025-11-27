<?php
/**
 * OAuth Hub Status
 */

require_once __DIR__ . '/lib/env.php';

header('Content-Type: application/json');

$status = [
    'service' => 'auth.giobi.com',
    'status' => 'ok',
    'timestamp' => date('c'),
    'providers' => [
        'microsoft' => [
            'configured' => !empty(env('MS_GRAPH_CLIENT_ID')),
            'has_refresh_token' => !empty(env('MS_GRAPH_REFRESH_TOKEN'))
        ],
        'google' => [
            'configured' => !empty(env('GOOGLE_CLIENT_ID')),
            'has_refresh_token' => !empty(env('GOOGLE_REFRESH_TOKEN'))
        ],
        'google-login' => [
            'configured' => !empty(env('GOOGLE_LOGIN_CLIENT_ID')),
        ]
    ]
];

echo json_encode($status, JSON_PRETTY_PRINT);
