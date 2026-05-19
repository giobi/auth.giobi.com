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
