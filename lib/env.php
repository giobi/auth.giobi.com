<?php
/**
 * Environment loader
 */

function load_env($path = null) {
    $path = $path ?? __DIR__ . '/../.env';

    if (!file_exists($path)) {
        error_log("ENV file not found: $path");
        return [];
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1], " \t\n\r\0\x0B\"'");
        $env[$key] = $value;
    }

    return $env;
}

// Load env globally
$GLOBALS['ENV'] = load_env();

function env($key, $default = null) {
    return $GLOBALS['ENV'][$key] ?? $default;
}
