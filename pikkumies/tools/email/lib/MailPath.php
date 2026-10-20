<?php
/**
 * MailPath: where the email units may write and what they may read (ADR-0053, R15). Writes go only to a NEW
 * directory inside the home, never to a directory that exists (an attachment named authorized_keys must not land in
 * ~/.ssh, a .php file not in ~/www), and never under a dot-directory, ~/www, ~/bin or the Pikkumies install. Names
 * from a message are decoded first, cut to their last path part and reduced to letters, digits, `.`, `_`, ` ` and `-`;
 * files are created with fopen 'x' (never following a symlink, never replacing a file), mode 0600. A file read for
 * sending is a regular file inside the home, outside dot-directories and local/secrets, and does not look like a key.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class MailPath
{
    const KEY_NAME = '/^(id_(rsa|dsa|ecdsa|ed25519)(_sk)?|authorized_keys|known_hosts)$|\.(pem|key|p12|pfx|kdbx|ppk)$/i';

    /** Refuses a real path outside $home or under a dot-directory, ~/www, ~/bin or the Pikkumies directory. */
    public static function fence($real, $home, $what)
    {
        if (!HomePath::inside($real, $home) || $real === $home) {
            throw new CliError("$real is not inside the home directory; nothing done", "use a path under $home");
        }
        $parts = explode('/', substr($real, strlen(rtrim($home, '/')) + 1));
        $root = realpath(Config::root());
        $bad = $parts[0] === 'www' || $parts[0] === 'bin' || in_array('pikkumies', $parts, true)
            || ($root !== false && HomePath::inside($real, $root));
        foreach ($parts as $p) {
            $bad = $bad || $p === '' || $p[0] === '.';
        }
        if ($bad) {
            throw new CliError("$what $real is under a dot-directory, ~/www, ~/bin or Pikkumies; nothing done",
                'use a plain folder in your home, e.g. ' . $home . '/mail');
        }
        return $real;
    }

    /** A fresh directory for one fetch: created here, 0700; an existing path (even an empty directory) is refused. */
    public static function freshDir($path, $home)
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            throw new CliError('--to must be a full path to a new directory', 'e.g. --to ' . $home . '/mail-attachments/invoice');
        }
        $path = rtrim($path, '/');
        $parent = realpath(dirname($path));
        if ($parent === false) {
            throw new CliError(dirname($path) . ' does not exist', 'create the parent folder first, or leave --to out');
        }
        self::fence($parent . '/' . PathName::base($path), $home, 'the folder');
        if (file_exists($path) || is_link($path)) {
            throw new CliError("$path already exists; attachments go only to a new directory", 'leave --to out, or name a new directory');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        list($real, $created) = HomePath::newTarget($path, $home, HomePath::fuseMounts('/proc/self/mountinfo', $uid));
        if (!$created) {
            throw new CliError("$real already exists; attachments go only to a new directory", 'name a new directory');
        }
        return $real;
    }

    /**
     * The --to folder of imap archive and pop fetch, the one rule for both: a full path inside the home, outside
     * dot-directories, ~/www, ~/bin and Pikkumies, whose parent exists, not a symlink, no . or .. part. Created 0700
     * when missing (an existing folder is fine: both add to it). Returns its real path.
     */
    public static function mailRoot($to, $home, $verb)
    {
        $example = 'e.g. --to ' . $home . '/' . ($verb === 'archive' ? 'mail-archive' : 'mail-pop');
        if (!is_string($to) || $to === '' || $to[0] !== '/') {
            throw new CliError("$verb needs --to <full path of a folder for the Maildir>", $example);
        }
        $to = rtrim($to, '/');
        $parent = realpath(dirname($to));
        if ($parent === false || is_link($to) || preg_match('#(^|/)\.\.?(/|$)#', $to)) {
            throw new CliError("$to is not a usable folder (missing parent, a symlink, or . or .. in it)", $example);
        }
        $real = self::fence($parent . '/' . PathName::base($to), $home, 'the Maildir folder');
        if (!is_dir($real) && !@mkdir($real, 0700)) {
            throw new CliError("cannot create $real", 'check the parent directory and the quota (storage stats)');
        }
        return $real;
    }

    /** A message's attachment name made safe: one plain file name of at most 200 bytes, extension kept. */
    public static function safeName($name, $n)
    {
        $name = str_replace('\\', '/', (string) $name);
        $name = (string) substr($name, (int) strrpos('/' . $name, '/'));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $name = (string) preg_replace('/[^\p{L}\p{N}._ -]/u', '_', preg_match('//u', $name) ? $name : preg_replace('/[\x80-\xFF]/', '_', $name));
        $name = ltrim(trim($name), '.-');
        if ($name === '' || $name === '.' || $name === '..') {
            return "attachment-$n";
        }
        if (strlen($name) > 200) {
            $ext = (string) pathinfo($name, PATHINFO_EXTENSION);
            $ext = $ext !== '' && strlen($ext) <= 16 ? '.' . $ext : '';
            $base = function_exists('mb_strcut') ? mb_strcut($name, 0, 200 - strlen($ext), 'UTF-8') : substr($name, 0, 200 - strlen($ext));
            $name = $base . $ext;
        }
        return $name;
    }

    /** Writes $data as a new file in $dir: name, then name-1, name-2... before the extension. Returns the path. */
    public static function writeNew($dir, $name, $data)
    {
        $ext = (string) pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($name, 0, -strlen($ext) - 1) : $name;
        for ($i = 0; $i < 1000; $i++) {
            $path = $dir . '/' . ($i === 0 ? $name : $base . '-' . $i . ($ext !== '' ? ".$ext" : ''));
            $old = umask(0077);
            $fh = @fopen($path, 'x');
            umask($old);
            if ($fh !== false) {
                $ok = fwrite($fh, $data) === strlen($data);
                fclose($fh);
                @chmod($path, 0600);
                if (!$ok) {
                    @unlink($path);
                    throw new CliError("could not write $path completely", 'check the quota (storage stats)');
                }
                return $path;
            }
        }
        throw new CliError("no free file name for $name in $dir", 'use a new directory');
    }

    /** A file the user names for sending (body or attachment): its real path, if it passes every rule. */
    public static function readable($path, $home, $what)
    {
        $real = is_string($path) && $path !== '' && $path[0] === '/' ? realpath($path) : false;
        if ($real === false || !is_file($real)) {
            throw new CliError("$what is not an existing file given as a full path: " . Cli::cleanString((string) $path), 'give a full path, e.g. ' . $home . '/notes/letter.txt');
        }
        self::fence($real, $home, $what);
        $secrets = realpath(Config::localDir() . '/secrets');
        if (($secrets !== false && HomePath::inside($real, $secrets)) || preg_match(self::KEY_NAME, PathName::base($real))) {
            throw new CliError("$what $real is a stored password or looks like a key; never sent", 'send a different file');
        }
        $head = (string) @file_get_contents($real, false, null, 0, 65536);
        if (strpos($head, 'PRIVATE KEY-----') !== false) {
            throw new CliError("$what $real holds a private key; never sent", 'send a different file');
        }
        return $real;
    }
}
