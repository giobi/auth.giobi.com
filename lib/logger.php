<?php
/**
 * OAuth Logging Library
 * Logs all OAuth attempts to data/oauth-log.jsonl
 */

function logOAuthAttempt($event, $data = []) {
    $logFile = __DIR__ . '/../data/oauth-log.jsonl';
    
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'data' => $data,
    ];
    
    // Append to JSONL file
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
