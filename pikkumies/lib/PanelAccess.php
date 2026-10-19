<?php
/**
 * PanelAccess: Pikkumies' own login to the account's panel, so the agent can open its panel without the user's password
 * and without asking (ADR-0079). The panel's lighttpd checks Basic logins against ~/.lighttpd/.htpasswd. A PMSS version
 * that lists the reserved name pikkumies.agent in the /user-<account>/ require rule accepts a second line there; a dot is
 * never part of a PMSS account name, so that name can never be an account.
 *  - available(): the rendered ~/.lighttpd.conf (root's file, only read) lists the agent in that rule. Until it does,
 *    nothing is written and every answer says why in one plain line.
 *  - ensure(): one line `pikkumies.agent:<bcrypt>` of 32 random bytes in the password file (PanelHtpasswd: the shared
 *    lock, no symlink, every other line byte for byte), the secret in local/secrets/panel-agent (SecretFile, 0600). A
 *    missing secret, a line that does not match it, or a rotate on request (a 401 with the stored login) makes a new one.
 *  - remove(): the line and the secret go. Each create, rotate, restore and remove is one JSON line in
 *    local/state/panel-access.jsonl; the secret and the hash never leave PHP: no argv, no log, no output.
 */
class PanelAccess
{
    const AGENT = 'pikkumies.agent';
    /** bcrypt cost: the secret is 256 random bits, so a higher cost buys nothing against guessing, and lighttpd checks it per request. */
    const COST = 10;

    public $home;
    public $user;
    public $local;
    public $uid;
    /** @var PanelHtpasswd */
    public $file;

    public function __construct($home, $user, $local, $uid = null)
    {
        $this->home = rtrim($home, '/');
        $this->user = (string) $user;
        $this->local = $local;
        $this->uid = $uid !== null ? (int) $uid : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        $this->file = new PanelHtpasswd($this->home . '/.lighttpd/.htpasswd', $this->uid);
    }

    /** This account, from the password database (Hermes may hand its tools a trimmed environment). */
    public static function forAccount($local = null)
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $pw = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        return new self(is_array($pw) && $pw['dir'] !== '' ? $pw['dir'] : (string) getenv('HOME'), is_array($pw) ? $pw['name'] : get_current_user(),
            $local !== null ? $local : Config::localDir(), $uid);
    }

    public function secretFile()
    {
        return $this->local . '/secrets/panel-agent';
    }

    public function logFile()
    {
        return $this->local . '/state/panel-access.jsonl';
    }

    public function available()
    {
        return $this->unavailable() === '';
    }

    /** '' when the panel accepts the agent's login, else why not. */
    public function unavailable()
    {
        $conf = $this->home . '/.lighttpd.conf';
        clearstatcache(true, $conf);
        $st = @lstat($conf);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
            return 'this account has no panel configuration to read (' . Config::shown($conf) . ')';
        }
        $require = self::userZoneRequire((string) @file_get_contents($conf, false, null, 0, PanelHtpasswd::MAX), $this->user);
        if ($require === null) {
            return 'the panel configuration has no login rule for /user-' . $this->user . '/';
        }
        return in_array('user=' . self::AGENT, array_map('trim', explode('|', $require)), true) ? ''
            : 'the panel on this server does not accept a second login yet (a PMSS update adds it)';
    }

    /** The require value of the "/user-<account>/" entry in auth.require (quoted values may hold parentheses, as the realm does), comment lines left out; null when there is none. */
    public static function userZoneRequire($text, $user)
    {
        $text = (string) preg_replace('/^[ \t]*#.*$/m', '', $text);
        if (!preg_match('#"/user-' . preg_quote($user, '#') . '/"\s*=>\s*\(((?:"[^"]*"|[^()"])*)\)#', $text, $m)) {
            return null;
        }
        return preg_match('#"require"\s*=>\s*"([^"]*)"#', $m[1], $r) ? $r[1] : null;
    }

    /** [true, 'present'|'created'|'rotated'] or [false, why]. $rotate: make a new login even when the stored one matches. */
    public function ensure($rotate = false)
    {
        $why = $this->unavailable();
        $why = $why !== '' ? $why : $this->file->problem();
        if ($why !== '') {
            return [false, $why];
        }
        return $this->file->locked(function () use ($rotate) {
            $before = $this->file->read();
            if ($before === null) {
                return [false, 'cannot read ' . Config::shown($this->file->path)];
            }
            $mine = PanelHtpasswd::named(PanelHtpasswd::lines($before), self::AGENT);
            $secret = $this->secret();
            $why = $rotate ? 'asked' : self::mismatch($mine, $secret);
            if ($why === '') {
                return [true, 'present'];
            }
            $secret = bin2hex(random_bytes(32));
            list($after, $append) = $this->compose($before, self::AGENT . ':' . password_hash($secret, PASSWORD_BCRYPT, ['cost' => self::COST]));
            $problem = $this->apply($before, $after, $append);
            $mine = PanelHtpasswd::named(PanelHtpasswd::lines((string) $this->file->read()), self::AGENT);
            $problem = $problem !== '' ? $problem : (self::mismatch($mine, $secret) === '' ? '' : 'the new line did not check out against its secret');
            if ($problem === '') {
                try {
                    SecretFile::write($this->secretFile(), ['user' => self::AGENT, 'password' => $secret], 'panel login');
                } catch (CliError $e) {
                    $problem = $e->getMessage() . ($this->file->replace($before) === '' ? '; the password file was put back' : '');
                }
            }
            $action = $why === 'no line' ? 'create' : 'rotate';
            $this->log($problem === '' ? $action : 'error', $problem === '' ? $why : "$action failed: $problem");
            return $problem === '' ? [true, $action === 'create' ? 'created' : 'rotated'] : [false, $problem];
        });
    }

    /** [true, 'removed'|'nothing to remove'] or [false, why]; works whether or not the panel still accepts the agent. */
    public function remove()
    {
        $problem = $this->file->problem();
        if ($problem !== '' && $this->file->exists()) {
            return [false, $problem];
        }
        $r = $problem !== '' ? [true, 'nothing to remove'] : $this->file->locked(function () {
            $before = $this->file->read();
            $mine = $before === null ? [] : PanelHtpasswd::named(PanelHtpasswd::lines($before), self::AGENT);
            if ($before === null || $mine === []) {
                return [$before !== null, $before === null ? 'cannot read ' . Config::shown($this->file->path) : 'nothing to remove'];
            }
            $problem = $this->apply($before, $this->compose($before, null)[0], false);
            $this->log($problem === '' ? 'remove' : 'error', $problem === '' ? count($mine) . ' line(s)' : "remove failed: $problem");
            return $problem === '' ? [true, 'removed'] : [false, $problem];
        });
        if ($r[0]) {
            @unlink($this->secretFile());
        }
        return $r;
    }

    /** `pikkumies access status` and doctor: one line, never the secret or the hash. */
    public function statusLine()
    {
        $why = $this->unavailable();
        $why = $why !== '' ? $why : $this->file->problem();
        if ($why !== '') {
            return "panel access: off ($why)";
        }
        $mine = PanelHtpasswd::named(PanelHtpasswd::lines((string) $this->file->read()), self::AGENT);
        $mismatch = self::mismatch($mine, $this->secret());
        if ($mismatch === '') {
            return 'panel access: ready (login ' . self::AGENT . ' in ' . Config::shown($this->file->path) . '; ' . $this->lastChange() . ')';
        }
        return 'panel access: ' . ($mismatch === 'no line' ? 'available, no agent login yet' : "the agent login needs renewing ($mismatch)")
            . '; made at the next browser start or with `pikkumies access rotate`';
    }

    /** The guard's header file at $path: created O_EXCL, 0600, one line "Basic <base64>". '' or why not. */
    public function writeHeader($path)
    {
        $secret = $this->secret();
        if ($secret === '') {
            return 'no stored panel login';
        }
        $old = umask(0077);
        $h = @fopen($path, 'x');
        umask($old);
        if ($h === false) {
            return 'cannot create ' . Config::shown($path) . ' (it exists, or its directory is not writable)';
        }
        $v = 'Basic ' . base64_encode(self::AGENT . ':' . $secret) . "\n";
        $ok = fwrite($h, $v) === strlen($v);
        $ok = fclose($h) && $ok && @chmod($path, 0600);
        if (!$ok) {
            @unlink($path);
            return 'cannot write ' . Config::shown($path);
        }
        return '';
    }

    /** [new bytes, true when they only add at the end]. $line null: the agent's lines go, nothing is added. */
    protected function compose($before, $line)
    {
        $lines = PanelHtpasswd::lines($before);
        if ($line !== null && PanelHtpasswd::named($lines, self::AGENT) === []) {
            return [PanelHtpasswd::appended($before, $line), true];
        }
        $keep = PanelHtpasswd::others($lines, self::AGENT);
        if ($line !== null) {
            $keep[] = $line;
        }
        return [$keep ? implode("\n", $keep) . "\n" : '', false];
    }

    /**
     * Writes $after, then reads it back: '' or why not. A file that is not what was written was changed by another program
     * and is left alone. A file that is exactly what was written but differs from $before in a line that is not the
     * agent's is put back to $before: that change came from this code and the account's own login must not move.
     */
    private function apply($before, $after, $append)
    {
        $problem = $append ? $this->file->append($before, $after) : $this->file->replace($after);
        if ($problem !== '') {
            return $problem;
        }
        $now = $this->file->read();
        if ($now !== $after) {
            return 'the password file changed while it was written (another program); left as it is';
        }
        if (PanelHtpasswd::others(PanelHtpasswd::lines($now), self::AGENT) !== PanelHtpasswd::others(PanelHtpasswd::lines($before), self::AGENT)) {
            $put = $this->file->replace($before);
            $this->log('restore', 'a line that is not the agent\'s changed in the write');
            return 'a line that is not the agent\'s would have changed; ' . ($put === '' ? 'the file was put back as it was' : "putting it back failed: $put");
        }
        return '';
    }

    /** '' when there is exactly one agent line and $secret checks out against it, else what is wrong. */
    private static function mismatch(array $mine, $secret)
    {
        if ($mine === []) {
            return 'no line';
        }
        if (count($mine) > 1) {
            return 'more than one line';
        }
        if ($secret === '') {
            return 'no stored secret';
        }
        return password_verify($secret, substr($mine[0], strlen(self::AGENT) + 1)) ? '' : 'the line does not match the stored secret';
    }

    /** The stored secret, '' when there is none usable (a symlink or another owner's file is never used; rotate replaces it). */
    private function secret()
    {
        try {
            $raw = SecretFile::read($this->secretFile(), 'pikkumies access rotate', $this->uid);
        } catch (CliError $e) {
            return '';
        }
        $d = $raw === false ? null : json_decode((string) $raw, true);
        return is_array($d) && isset($d['password']) && is_string($d['password']) && preg_match('/^[0-9a-f]{64}$/', $d['password']) ? $d['password'] : '';
    }

    private function log($action, $detail)
    {
        @mkdir(dirname($this->logFile()), 0700, true);
        $old = umask(0077);
        @file_put_contents($this->logFile(), json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'action' => $action, 'detail' => $detail], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        umask($old);
    }

    private function lastChange()
    {
        $lines = @file($this->logFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last = $lines ? json_decode((string) end($lines), true) : null;
        return is_array($last) && isset($last['action'], $last['ts']) && is_string($last['action']) && is_string($last['ts'])
            ? 'last change: ' . preg_replace('/[^a-z]/', '', $last['action']) . ' at ' . preg_replace('/[^0-9TZ:-]/', '', $last['ts']) : 'no change logged';
    }
}
