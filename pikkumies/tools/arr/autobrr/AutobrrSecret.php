<?php
/**
 * AutobrrSecret: the autobrr API key, typed once by the user at a terminal (ADR-0019), the same pattern as
 * torrent-clients/qbittorrent/QbitSecret.php and backup/lib/BackupStore.php: never in the chat, never on a
 * command line; stored in local/secrets/autobrr (directory 0700, file 0600, written to a temporary file and
 * renamed: lib/SecretFile.php, which also refuses a file another account owns). autobrr answers a wrong key with 401
 * and nothing more (internal/http/middleware.go:71-85), so there is no "rejected" mark as for qBittorrent; a rejected
 * key is an error that names `login`.
 */
class AutobrrSecret
{
    private $file;
    private $uid;

    /** $uid: the owner the file must have (a test seam; this account's uid by default). */
    public function __construct($dir, $uid = null)
    {
        $this->file = rtrim($dir, '/') . '/autobrr';
        $this->uid = $uid;
    }

    public function path()
    {
        return $this->file;
    }

    /** The stored key, or '' when none is stored. */
    public function load()
    {
        $raw = SecretFile::read($this->file, 'the user runs `php ' . dirname(__FILE__) . '/autobrr.php login` in a terminal', $this->uid);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) && isset($d['key']) && is_string($d['key']) ? ArrConfig::key($d['key']) : '';
    }

    public function save($key)
    {
        SecretFile::write($this->file, ['key' => $key], 'autobrr API key');
    }

    /** Reads the key from the terminal without echo; refuses when stdin is not a terminal (pikkumies/lib/SecretFile.php). */
    public static function prompt($label)
    {
        return SecretFile::prompt($label, 'login reads the API key from a terminal, and stdin is not one', 'the user runs `php '
            . dirname(__FILE__) . '/autobrr.php login` in their own terminal (never pass the key on the command line or in chat)');
    }
}
