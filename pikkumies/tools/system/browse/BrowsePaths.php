<?php
/**
 * BrowsePaths: where the browse unit keeps its state, all under local/browse/ (AGENTS.md: state lives under local/),
 * and the one check every directory passes before use: a real directory (never a symlink) owned by this account with
 * no group or other bits. The agent-browser socket, the Chrome profile and the guard's intent file live here, so a
 * directory another account could write or swap would hand it the browser.
 */
class BrowsePaths
{
    /** Debian's chromium package (installed by PMSS); never a browser downloaded into the account. */
    const CHROMIUM = '/usr/bin/chromium';
    /** The one agent-browser session every call is pinned to, so every call reaches the guarded daemon. */
    const SESSION = 'pikkumies';

    /** Test seams: another local/ directory, another chromium path. */
    public static $local = null;
    public static $chromium = null;

    public static function local()
    {
        return self::$local !== null ? self::$local : Config::localDir();
    }

    public static function dir()
    {
        return self::local() . '/browse';
    }

    public static function chromium()
    {
        return self::$chromium !== null ? self::$chromium : self::CHROMIUM;
    }

    /** name => path of each directory the unit uses; all are created 0700 and checked by ensureDirs(). */
    public static function dirs()
    {
        $d = self::dir();
        return ['browse' => $d, 'run' => "$d/run", 'profile' => "$d/profile", 'tmp' => "$d/tmp", 'bin' => "$d/bin", 'path' => "$d/path"];
    }

    public static function file($name)
    {
        $files = [
            'state' => 'state.json',          // pids and identities of the running parts
            'lock' => 'lock',                 // up and down take it, so two starts never race
            'lastUse' => 'last-use',          // touched by every CLI call; its mtime drives the idle stop
            'intent' => 'intent',             // the URL of the CLI's next open, for the guard (one line, 0600)
            'ready' => 'guard.ready',         // written by the guard once it is attached
            'guardLog' => 'guard.jsonl',      // the guard's own log (blocked prefixes, never query strings)
            'log' => 'browse.log',            // what up, down and the watcher did
            'config' => 'agent-browser.json', // agent-browser's config: only the profile directory
            'resolv' => 'resolv.conf',        // bound over /etc/resolv.conf inside the namespace
            'shim' => 'path/agent-browser',   // the command Hermes finds first on its PATH
            'panelAuth' => 'panel-auth',      // the agent's panel login for the guard (ADR-0079), 0600; deleted once read
        ];
        return self::dir() . '/' . $files[$name];
    }

    /** The panel's Basic header for the guard, when the user stored one (local/secrets/, ADR-0019). */
    public static function authFile()
    {
        return self::local() . '/secrets/browse-panel-auth';
    }

    /**
     * '' when $path is a directory this account owns with no group/other bits, after creating it 0700 when missing;
     * otherwise the problem. A looser mode on our own directory is set back to 0700 (drift, as SecretFile does); a
     * symlink or another owner is refused, never repaired.
     */
    public static function checkDir($path, $uid = null)
    {
        $uid = $uid !== null ? (int) $uid : self::uid();
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            @mkdir($path, 0700, true);
            clearstatcache(true, $path);
        }
        $st = @lstat($path);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000) {
            return Config::shown($path) . ($st === false ? ' cannot be created' : ' is not a directory (a symlink or a file)');
        }
        if ((int) $st['uid'] !== $uid) {
            return Config::shown($path) . " belongs to uid {$st['uid']}, not to this account";
        }
        if (($st['mode'] & 077) && !@chmod($path, 0700)) {
            return sprintf('%s is mode %04o and cannot be set to 0700', Config::shown($path), $st['mode'] & 0777);
        }
        return '';
    }

    /** checkDir() over every directory, the parent first; '' or the first problem. */
    public static function ensureDirs()
    {
        foreach (self::dirs() as $path) {
            $problem = self::checkDir($path);
            if ($problem !== '') {
                return $problem;
            }
        }
        return '';
    }

    /**
     * The command Hermes runs as agent-browser: pure exec glue into the unit's PHP entry (AGENTS.md: shell only as
     * thin glue). Generated because it names this PHP binary and this checkout by absolute path.
     */
    public static function shimText($php, $entry)
    {
        return "#!/bin/sh\n# Written by pikkumies (tools/system/browse): every agent-browser call goes through the isolated browser.\nexec "
            . escapeshellarg($php) . ' ' . escapeshellarg($entry) . " cli -- \"\$@\"\n";
    }

    /** Writes the shim when it differs; throws when it cannot. */
    public static function writeShim($php = PHP_BINARY, $entry = null)
    {
        $entry = $entry !== null ? $entry : __DIR__ . '/browse.php';
        $text = self::shimText($php, $entry);
        $path = self::file('shim');
        if ((string) @file_get_contents($path) !== $text || (fileperms($path) & 0777) !== 0700) {
            HermesConfig::put($path, $text, 0700);
        }
    }

    public static function uid()
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /** This account's home from the password database (Hermes may hand its tools a trimmed environment). */
    public static function home()
    {
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(self::uid());
            if (is_array($pw) && $pw['dir'] !== '') {
                return $pw['dir'];
            }
        }
        return (string) getenv('HOME');
    }
}
