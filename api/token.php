<?php
/**
 * GET /api/token?provider=<p>&email=<e>
 * Contract abchat <-> auth.giobi.com.
 * Auth: Authorization: Bearer <JWT HS256 firmato con ABCHAT_AUTH_HUB_SECRET>.
 * Ritorna il token GIA salvato dal hub (oauth_tokens.sqlite). NON parla con Google.
 * Niente token nei log.
 */

header('Content-Type: application/json');

function _b64url_decode($d) {
    return base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4));
}
function _fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    error_log("[api/token] DENY $code $msg ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit;
}

// Auth: Bearer statico = ABCHAT_AUTH_HUB_SECRET (segreto condiviso abchat<->hub).
$secret = env('ABCHAT_AUTH_HUB_SECRET');
if (!$secret) { _fail(500, 'hub auth not configured'); }

$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (stripos($hdr, 'Bearer ') !== 0) { _fail(401, 'missing bearer'); }
$bearer = trim(substr($hdr, 7));
if (!hash_equals((string)$secret, $bearer)) { _fail(401, 'bad token'); }

require_once __DIR__ . '/../lib/oauth_db.php';
$db = new OAuthDB();

$code = trim($_GET['code'] ?? '');
if ($code !== '') {
    // path preferito: handoff code monouso (consumato qui)
    $h = $db->redeemHandoff($code);
    if (!$h) { _fail(404, 'invalid or expired code'); }
    $email = $h['email'];
    $provider = $h['provider'];
} else {
    // back-compat: lookup diretto per email+provider
    $provider = preg_replace('/[^a-z0-9_-]/i', '', $_GET['provider'] ?? '');
    $email = trim($_GET['email'] ?? '');
    if ($provider === '' || $email === '') { _fail(400, 'provider+email or code required'); }
}
$tok = $db->getTokens($email, $provider);
if (!$tok || empty($tok['refresh_token'])) {
    _fail(404, 'no stored token — user must authenticate at auth.giobi.com');
}

error_log("[api/token] OK email=$email provider=$provider ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
echo json_encode([
    'provider'      => $provider,
    'email'         => $email,
    'access_token'  => $tok['access_token'] ?? null,
    'refresh_token' => $tok['refresh_token'] ?? null,
    'expires_at'    => isset($tok['expires_at']) ? (int)$tok['expires_at'] : null,
]);
