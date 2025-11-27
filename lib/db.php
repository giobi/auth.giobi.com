<?php
/**
 * Database helper for auth.giobi.com
 * SQLite database for apps, emails, magic links, and access logs
 */

function get_db(): PDO {
    $db_path = dirname(__DIR__) . '/data/auth.db';
    $dir = dirname($db_path);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $is_new = !file_exists($db_path);

    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($is_new) {
        init_db($pdo);
    }

    return $pdo;
}

function init_db(PDO $pdo): void {
    // Apps table
    $pdo->exec("CREATE TABLE IF NOT EXISTS apps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        callback_url TEXT NOT NULL,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Allowed emails table
    $pdo->exec("CREATE TABLE IF NOT EXISTS allowed_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        name TEXT,
        is_admin INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Magic links table
    $pdo->exec("CREATE TABLE IF NOT EXISTS magic_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT UNIQUE NOT NULL,
        email TEXT NOT NULL,
        app_name TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Access logs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS access_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        app_name TEXT NOT NULL,
        method TEXT NOT NULL,
        ip_address TEXT,
        user_agent TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed default data
    $pdo->exec("INSERT OR IGNORE INTO apps (name, callback_url) VALUES
        ('status.giobi.com', 'https://status.giobi.com/auth/callback'),
        ('ledger.giobi.com', 'https://ledger.giobi.com/auth/callback'),
        ('forms.giobi.com', 'https://forms.giobi.com/auth/callback')
    ");

    $pdo->exec("INSERT OR IGNORE INTO allowed_emails (email, name, is_admin) VALUES
        ('giobi@giobi.com', 'Giobi', 1),
        ('giobimail@gmail.com', 'Giobi Gmail', 1)
    ");
}

// Helper functions
function get_allowed_emails(PDO $pdo): array {
    return $pdo->query("SELECT * FROM allowed_emails WHERE is_active = 1 ORDER BY email")->fetchAll();
}

function get_allowed_apps(PDO $pdo): array {
    return $pdo->query("SELECT * FROM apps WHERE is_active = 1 ORDER BY name")->fetchAll();
}

function is_email_allowed(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM allowed_emails WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    return (bool) $stmt->fetch();
}

function is_email_admin(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare("SELECT is_admin FROM allowed_emails WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row && $row['is_admin'];
}

function get_app_callback(PDO $pdo, string $app_name): ?string {
    $stmt = $pdo->prepare("SELECT callback_url FROM apps WHERE name = ? AND is_active = 1");
    $stmt->execute([$app_name]);
    $row = $stmt->fetch();
    return $row ? $row['callback_url'] : null;
}

function log_access(PDO $pdo, string $email, string $app, string $method): void {
    $stmt = $pdo->prepare("INSERT INTO access_logs (email, app_name, method, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $email,
        $app,
        $method,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
}

function create_magic_link(PDO $pdo, string $email, string $app, int $hours, string $created_by): string {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + ($hours * 3600));

    $stmt = $pdo->prepare("INSERT INTO magic_links (token, email, app_name, expires_at, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$token, $email, $app, $expires_at, $created_by]);

    return $token;
}

function validate_magic_link(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("SELECT * FROM magic_links WHERE token = ? AND used_at IS NULL AND expires_at > datetime('now')");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function mark_magic_link_used(PDO $pdo, string $token): void {
    $stmt = $pdo->prepare("UPDATE magic_links SET used_at = datetime('now') WHERE token = ?");
    $stmt->execute([$token]);
}
