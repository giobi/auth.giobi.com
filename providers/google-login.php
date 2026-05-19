<?php
/**
 * Google Login Provider (SSO)
 *
 * Central OAuth for internal tools.
 * Supports dynamic scopes: ?scopes=gmail,calendar,drive
 *
 * Flow:
 * 1. App redirects here with ?app=xxx&state=xxx&scopes=gmail,calendar
 * 2. We redirect to Google OAuth with requested scopes
 * 3. Google calls back to /google-login/callback
 * 4. We create signed token with user info + tokens and redirect back to app
 */

require_once __DIR__ . '/../lib/env.php';

// Allowed apps and their callback URLs
$allowed_apps = [
    'status.giobi.com' => 'https://status.giobi.com/auth/callback',
    'ledger.giobi.com' => 'https://ledger.giobi.com/auth/callback',
    'abchat.giobi.com' => 'https://abchat.giobi.com/auth/callback',
    'abchat.it' => 'https://abchat.it/auth/callback',
];

// Scope mapping: shorthand => Google OAuth scopes
$scope_map = [
    'profile' => 'openid email profile',
    'gmail' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send',
    'gmail.full' => 'https://mail.google.com/ https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/gmail.compose https://www.googleapis.com/auth/gmail.metadata https://www.googleapis.com/auth/gmail.settings.basic',
    'calendar' => 'https://www.googleapis.com/auth/calendar',
    'calendar.full' => 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.acls',
    'drive' => 'https://www.googleapis.com/auth/drive',
    'drive.full' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.metadata https://www.googleapis.com/auth/drive.activity',
    'youtube' => 'https://www.googleapis.com/auth/youtube.readonly',
    'analytics' => 'https://www.googleapis.com/auth/analytics.readonly',
    'search_console' => 'https://www.googleapis.com/auth/webmasters.readonly',
];

$client_id = env('GOOGLE_CLIENT_ID');
$client_secret = env('GOOGLE_CLIENT_SECRET');
$redirect_uri = 'https://auth.giobi.com/google-login/callback';

// Get request path
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$is_callback = str_contains($path, '/callback');

if ($is_callback) {
    handle_callback($client_id, $client_secret, $redirect_uri, $allowed_apps);
} else {
    initiate_oauth($client_id, $redirect_uri, $allowed_apps, $scope_map);
}

/**
 * Initiate OAuth flow - redirect to Google
 */
function initiate_oauth($client_id, $redirect_uri, $allowed_apps, $scope_map) {
    $app = $_GET['app'] ?? '';
    $state = $_GET['state'] ?? '';
    $requested_scopes = $_GET['scopes'] ?? '';

    if (!$app || !isset($allowed_apps[$app])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or missing app parameter']);
        return;
    }

    if (!$state) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing state parameter']);
        return;
    }

    // Build scope string from requested scopes
    $scopes = ['openid', 'email', 'profile']; // Always include base scopes

    if ($requested_scopes) {
        $scope_keys = array_map('trim', explode(',', $requested_scopes));
        foreach ($scope_keys as $key) {
            if (isset($scope_map[$key]) && $key !== 'profile') {
                $scopes[] = $scope_map[$key];
            }
        }
    }

    $scope_string = implode(' ', array_unique($scopes));

    // Determine if we need offline access (for refresh tokens)
    $needs_offline = $requested_scopes && $requested_scopes !== 'profile';

    // Store in session
    session_start();
    $_SESSION['oauth_app'] = $app;
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_scopes'] = $requested_scopes;

    // Build Google OAuth URL
    $params = [
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => $scope_string,
        'access_type' => $needs_offline ? 'offline' : 'online',
        'state' => $state,
        'prompt' => $needs_offline ? 'consent' : 'select_account', // Force consent for refresh token
    ];

    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    header("Location: $url");
    exit;
}

/**
 * Handle Google OAuth callback
 */
function handle_callback($client_id, $client_secret, $redirect_uri, $allowed_apps) {
    session_start();

    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $error = $_GET['error'] ?? '';

    // Get stored session data
    $stored_app = $_SESSION['oauth_app'] ?? '';
    $stored_state = $_SESSION['oauth_state'] ?? '';
    $stored_scopes = $_SESSION['oauth_scopes'] ?? '';

    // Clear session
    unset($_SESSION['oauth_app'], $_SESSION['oauth_state'], $_SESSION['oauth_scopes']);

    // Handle errors from Google
    if ($error) {
        error_log("Google OAuth error: $error");
        redirect_with_error($stored_app, $allowed_apps, "Google authentication failed: $error");
        return;
    }

    // Verify state
    if (!$state || $state !== $stored_state) {
        redirect_with_error($stored_app, $allowed_apps, 'Invalid state parameter');
        return;
    }

    // Verify app
    if (!$stored_app || !isset($allowed_apps[$stored_app])) {
        http_response_code(400);
        echo json_encode(['error' => 'Session expired or invalid app']);
        return;
    }

    // Exchange code for tokens
    $token_response = exchange_code($code, $client_id, $client_secret, $redirect_uri);

    if (!$token_response || isset($token_response['error'])) {
        $error_msg = $token_response['error_description'] ?? $token_response['error'] ?? 'Token exchange failed';
        error_log("Token exchange error: $error_msg");
        redirect_with_error($stored_app, $allowed_apps, $error_msg);
        return;
    }

    // Get user info
    $user_info = get_user_info($token_response['access_token']);

    if (!$user_info || !isset($user_info['email'])) {
        redirect_with_error($stored_app, $allowed_apps, 'Failed to get user info');
        return;
    }

    // Create signed token for the app (includes OAuth tokens if scopes were requested)
    $app_token = create_app_token($user_info, $token_response, $stored_scopes, $client_secret);

    // Redirect back to app with token
    $callback_url = $allowed_apps[$stored_app];
    $params = [
        'token' => $app_token,
        'state' => $state,
    ];

    header("Location: $callback_url?" . http_build_query($params));
    exit;
}

/**
 * Exchange authorization code for tokens
 */
function exchange_code($code, $client_id, $client_secret, $redirect_uri) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("cURL error in token exchange: $error");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Get user info from Google
 */
function get_user_info($access_token) {
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $access_token"],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("cURL error in userinfo: $error");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Create signed token for the app
 * Format: base64(payload).hmac_signature
 *
 * If scopes were requested, includes OAuth tokens for the app to store
 */
function create_app_token($user_info, $token_response, $scopes, $client_secret) {
    $payload = [
        'email' => $user_info['email'],
        'name' => $user_info['name'] ?? $user_info['email'],
        'picture' => $user_info['picture'] ?? null,
        'google_id' => $user_info['id'] ?? null,
        'exp' => time() + 300, // 5 minutes expiry
        'iat' => time(),
    ];

    // Include OAuth tokens if scopes were requested (not just profile)
    if ($scopes && $scopes !== 'profile') {
        $payload['oauth'] = [
            'access_token' => $token_response['access_token'],
            'refresh_token' => $token_response['refresh_token'] ?? null,
            'expires_in' => $token_response['expires_in'] ?? 3600,
            'scopes' => $scopes,
        ];
    }

    $payload_encoded = base64_encode(json_encode($payload));

    // Sign with HMAC using hashed secret
    $hashed_secret = hash('sha256', $client_secret);
    $signature = hash_hmac('sha256', $payload_encoded, $hashed_secret);

    return $payload_encoded . '.' . $signature;
}

/**
 * Redirect back to app with error
 */
function redirect_with_error($app, $allowed_apps, $error) {
    if ($app && isset($allowed_apps[$app])) {
        $base_url = str_replace('/auth/callback', '/admin/login', $allowed_apps[$app]);
        header("Location: $base_url?error=" . urlencode($error));
    } else {
        http_response_code(400);
        echo json_encode(['error' => $error]);
    }
    exit;
}
