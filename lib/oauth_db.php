<?php
/**
 * OAuth Tokens Database
 * Centralized token storage for multi-user OAuth
 */

class OAuthDB {
    private $db;
    private $dbPath = __DIR__ . '/../data/oauth_tokens.sqlite';

    public function __construct() {
        $this->db = new SQLite3($this->dbPath);
        $this->db->busyTimeout(5000);
        $this->db->exec('CREATE TABLE IF NOT EXISTS handoff_codes (
            code TEXT PRIMARY KEY, email TEXT NOT NULL, provider TEXT NOT NULL,
            expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS state_returns (
            state TEXT PRIMARY KEY, return_url TEXT NOT NULL,
            expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
    }

    /**
     * Crea un handoff code monouso (email,provider) -> code. TTL secondi.
     */
    public function createHandoff($email, $provider, $ttl = 120) {
        $this->db->exec('DELETE FROM handoff_codes WHERE expires_at < strftime("%s","now")');
        $code = bin2hex(random_bytes(24));
        $stmt = $this->db->prepare('INSERT INTO handoff_codes
            (code,email,provider,expires_at,created_at)
            VALUES (:c,:e,:p,strftime("%s","now")+:ttl,strftime("%s","now"))');
        $stmt->bindValue(':c', $code, SQLITE3_TEXT);
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $stmt->bindValue(':p', $provider, SQLITE3_TEXT);
        $stmt->bindValue(':ttl', (int)$ttl, SQLITE3_INTEGER);
        $stmt->execute();
        return $code;
    }

    /**
     * Riscatta un handoff code: ritorna [email,provider] e lo CANCELLA (monouso).
     * null se inesistente/scaduto.
     */
    public function redeemHandoff($code) {
        $stmt = $this->db->prepare('SELECT email,provider FROM handoff_codes
            WHERE code = :c AND expires_at >= strftime("%s","now")');
        $stmt->bindValue(':c', $code, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $del = $this->db->prepare('DELETE FROM handoff_codes WHERE code = :c');
        $del->bindValue(':c', $code, SQLITE3_TEXT);
        $del->execute();
        return $row ?: null;
    }

    /**
     * Associa un return_url allo state OAuth (sopravvive al round-trip Google).
     */
    public function saveStateReturn($state, $returnUrl, $ttl = 900) {
        $this->db->exec('DELETE FROM state_returns WHERE expires_at < strftime("%s","now")');
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO state_returns
            (state,return_url,expires_at,created_at)
            VALUES (:s,:u,strftime("%s","now")+:ttl,strftime("%s","now"))');
        $stmt->bindValue(':s', $state, SQLITE3_TEXT);
        $stmt->bindValue(':u', $returnUrl, SQLITE3_TEXT);
        $stmt->bindValue(':ttl', (int)$ttl, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    /**
     * Recupera e CANCELLA il return_url per uno state (monouso). null se assente/scaduto.
     */
    public function popStateReturn($state) {
        $stmt = $this->db->prepare('SELECT return_url FROM state_returns
            WHERE state = :s AND expires_at >= strftime("%s","now")');
        $stmt->bindValue(':s', $state, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $del = $this->db->prepare('DELETE FROM state_returns WHERE state = :s');
        $del->bindValue(':s', $state, SQLITE3_TEXT);
        $del->execute();
        return $row ? $row['return_url'] : null;
    }

    /**
     * Save or update OAuth tokens
     */
    public function saveTokens($email, $provider, $accessToken, $refreshToken = null, $expiresAt = null) {
        $stmt = $this->db->prepare('
            INSERT INTO oauth_tokens (email, provider, access_token, refresh_token, expires_at, updated_at)
            VALUES (:email, :provider, :access_token, :refresh_token, :expires_at, strftime("%s", "now"))
            ON CONFLICT(email, provider) DO UPDATE SET
                access_token = :access_token,
                refresh_token = COALESCE(:refresh_token, refresh_token),
                expires_at = :expires_at,
                updated_at = strftime("%s", "now")
        ');

        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':provider', $provider, SQLITE3_TEXT);
        $stmt->bindValue(':access_token', $accessToken, SQLITE3_TEXT);
        $stmt->bindValue(':refresh_token', $refreshToken, SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $expiresAt, SQLITE3_INTEGER);

        return $stmt->execute();
    }

    /**
     * Get tokens for email + provider
     */
    public function getTokens($email, $provider) {
        $stmt = $this->db->prepare('
            SELECT * FROM oauth_tokens
            WHERE email = :email AND provider = :provider
        ');

        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':provider', $provider, SQLITE3_TEXT);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ?: null;
    }

    /**
     * Get all tokens for an email
     */
    public function getTokensByEmail($email) {
        $stmt = $this->db->prepare('
            SELECT * FROM oauth_tokens WHERE email = :email
        ');

        $stmt->bindValue(':email', $email, SQLITE3_TEXT);

        $result = $stmt->execute();
        $tokens = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tokens[] = $row;
        }

        return $tokens;
    }

    /**
     * Delete tokens
     */
    public function deleteTokens($email, $provider) {
        $stmt = $this->db->prepare('
            DELETE FROM oauth_tokens
            WHERE email = :email AND provider = :provider
        ');

        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':provider', $provider, SQLITE3_TEXT);

        return $stmt->execute();
    }

    /**
     * List all tokens (admin)
     */
    public function listAll() {
        $result = $this->db->query('SELECT * FROM oauth_tokens ORDER BY updated_at DESC');
        $tokens = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tokens[] = $row;
        }

        return $tokens;
    }
}
