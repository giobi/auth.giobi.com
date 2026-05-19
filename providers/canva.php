<?php
/**
 * Canva OAuth Provider (PKCE)
 *
 * Usage:
 *   https://auth.giobi.com/canva
 *   → redirects to Canva OAuth, saves CANVA_ACCESS_TOKEN + CANVA_REFRESH_TOKEN
 */

require_once __DIR__ . '/../lib/env.php';
require_once __DIR__ . '/../lib/logger.php';

session_start();

define('CANVA_AUTH_URL',  'https://www.canva.com/api/oauth/authorize');
define('CANVA_TOKEN_URL', 'https://api.canva.com/rest/v1/oauth/token');
define('REDIRECT_URI',    'https://auth.giobi.com');
define('ENV_PATH',        '/home/giobi/brain/.env');

$isCallback = isset($_GET['code']) || isset($_GET['error']);

if ($isCallback) {
    handleCallback();
} else {
    startFlow();
}

// ─────────────────────────────────────────────────────────
// Start OAuth flow with PKCE
// ─────────────────────────────────────────────────────────
function startFlow() {
    $scopes = [
        'design:meta:read',
        'design:content:read',
        'design:content:write',
        'asset:read',
        'asset:write',
        'folder:read',
        'folder:write',
        'profile:read',
    ];

    $clientId = canvaGetEnvVar('CANVA_CLIENT_ID', ENV_PATH);
    if (!$clientId) {
        canvaShowError('CANVA_CLIENT_ID not found in brain/.env');
        return;
    }

    // PKCE: code_verifier + code_challenge
    $codeVerifier  = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    $state         = 'canva-' . bin2hex(random_bytes(16));

    // Store in session for callback
    $_SESSION['canva_code_verifier'] = $codeVerifier;
    $_SESSION['canva_state']         = $state;

    $params = http_build_query([
        'response_type'         => 'code',
        'client_id'             => $clientId,
        'redirect_uri'          => REDIRECT_URI,
        'scope'                 => implode(' ', $scopes),
        'state'                 => $state,
        'code_challenge'        => $codeChallenge,
        'code_challenge_method' => 'S256',
    ]);

    logOAuthAttempt('canva_start', ['state' => $state]);

    header('Location: ' . CANVA_AUTH_URL . '?' . $params);
    exit;
}

// ─────────────────────────────────────────────────────────
// Handle callback from Canva
// ─────────────────────────────────────────────────────────
function handleCallback() {
    logOAuthAttempt('canva_callback', [
        'has_code'  => isset($_GET['code']),
        'has_error' => isset($_GET['error']),
        'state'     => $_GET['state'] ?? '',
    ]);

    if (isset($_GET['error'])) {
        canvaShowError($_GET['error_description'] ?? $_GET['error']);
        return;
    }

    if (!isset($_GET['code'])) {
        canvaShowError('No authorization code received from Canva');
        return;
    }

    // CSRF check
    $returnedState  = $_GET['state'] ?? '';
    $expectedState  = $_SESSION['canva_state'] ?? '';
    if (!$expectedState || $returnedState !== $expectedState) {
        canvaShowError('State mismatch — possible CSRF attack. Try again.');
        return;
    }

    $codeVerifier = $_SESSION['canva_code_verifier'] ?? '';
    if (!$codeVerifier) {
        canvaShowError('Missing code_verifier in session. Try again.');
        return;
    }

    $clientId     = canvaGetEnvVar('CANVA_CLIENT_ID', ENV_PATH);
    $clientSecret = canvaGetEnvVar('CANVA_CLIENT_SECRET', ENV_PATH);

    // Exchange code for tokens
    $ch = curl_init(CANVA_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => REDIRECT_URI,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code_verifier' => $codeVerifier,
        ]),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $token = json_decode($response, true);

    if ($httpCode !== 200 || isset($token['error'])) {
        logOAuthAttempt('canva_token_error', [
            'http_code' => $httpCode,
            'response'  => $response,
        ]);
        canvaShowError($token['error_description'] ?? $token['error'] ?? "Token exchange failed (HTTP $httpCode)");
        return;
    }

    // Save tokens to brain/.env
    $updated = [];
    if (!empty($token['access_token'])) {
        canvaUpdateEnvFile(ENV_PATH, 'CANVA_ACCESS_TOKEN', $token['access_token']);
        $updated[] = 'CANVA_ACCESS_TOKEN';
    }
    if (!empty($token['refresh_token'])) {
        canvaUpdateEnvFile(ENV_PATH, 'CANVA_REFRESH_TOKEN', $token['refresh_token']);
        $updated[] = 'CANVA_REFRESH_TOKEN';
    }

    logOAuthAttempt('canva_success', ['updated' => $updated]);

    canvaShowSuccess($updated, $token['expires_in'] ?? null);
}

// ─────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────
function canvaGetEnvVar($key, $envPath) {
    if (!file_exists($envPath)) return null;
    foreach (file($envPath, FILE_IGNORE_NEW_LINES) as $line) {
        if (strpos($line, "$key=") === 0) {
            return trim(substr($line, strlen($key) + 1), " \t\"'");
        }
    }
    return null;
}

function canvaUpdateEnvFile($path, $key, $value) {
    $content = file_get_contents($path);
    $lines   = explode("\n", $content);
    $updated = false;
    foreach ($lines as &$line) {
        if (strpos($line, "$key=") === 0) {
            $line    = "$key=$value";
            $updated = true;
            break;
        }
    }
    if (!$updated) $lines[] = "$key=$value";
    file_put_contents($path, implode("\n", $lines));
}

function canvaShowSuccess($updated, $expiresIn) {
    $expiresMsg = $expiresIn ? "Expires in: {$expiresIn}s (auto-refresh attivo)" : '';
?>
<!DOCTYPE html>
<html>
<head><title>Canva OAuth - Success</title>
<style>
body { font-family: -apple-system, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #f8f9fa; }
.container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
h1 { color: #155724; }
.success-box { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0; }
ul { list-style: none; padding: 0; }
li::before { content: '✅ '; }
code { background: #e9ecef; padding: 2px 8px; border-radius: 3px; font-size: 13px; }
</style>
</head>
<body>
<div class="container">
    <h1>✅ Canva OAuth OK!</h1>
    <div class="success-box">
        Token salvati in <code>brain/.env</code><br>
        <?= $expiresMsg ?>
    </div>
    <ul>
        <?php foreach ($updated as $k): ?>
        <li><code><?= htmlspecialchars($k) ?></code></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="https://auth.giobi.com">← OAuth Hub</a></p>
</div>
</body>
</html>
<?php
}

function canvaShowError($message) {
?>
<!DOCTYPE html>
<html>
<head><title>Canva OAuth - Error</title>
<style>
body { font-family: -apple-system, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #f8f9fa; }
.container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.error-box { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0; }
a.btn { display: inline-block; background: #007bff; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; margin-top: 15px; }
</style>
</head>
<body>
<div class="container">
    <h1>❌ Canva OAuth Error</h1>
    <div class="error-box"><?= htmlspecialchars($message) ?></div>
    <a href="/canva" class="btn">Riprova →</a>
    <p><a href="https://auth.giobi.com">← OAuth Hub</a></p>
</div>
</body>
</html>
<?php
}
