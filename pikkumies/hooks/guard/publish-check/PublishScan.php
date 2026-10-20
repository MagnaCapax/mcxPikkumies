<?php
/**
 * PublishScan: where a push goes and what in it looks like a secret (ADR-0083). A remote counts as a public place only on
 * a public code-hosting service (HOSTS); an SSH host alias is read from ~/.ssh/config's Host/HostName lines (no Include,
 * no Match). What goes out is read with Redact::secrets(): a line it changes is a hit, shown as Redact prints it. File
 * names that look like a credential file (credentialName) are hits too. Scanning stops at a deadline; what was left
 * unread is reported, never treated as clean.
 */
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/Redact.php';
require_once dirname(__DIR__, 3) . '/lib/ToolProc.php';

class PublishScan
{
    const HOSTS = ['github.com', 'gitlab.com', 'codeberg.org', 'bitbucket.org'];
    const HITS_MAX = 20;      // hits kept; the scan stops there
    const LINE_SHOWN = 160;   // characters of a masked line in a message
    const FILE_MAX = 1048576; // bytes of a published file read; a larger one is checked by name only

    /** [host, 'owner/repo'] of a remote URL or scp-like address, host lower-cased; ['', ''] for a local path. */
    public static function remote($url, $home)
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://(?:[^@/]*@)?(\[[^\]]*\]|[^/:]+)(?::\d*)?/+(.*?)(?:\.git)?/*$#i', $url, $m)) {
            list($host, $path) = [$m[1], $m[2]];
        } elseif (preg_match('#^(?:[^@/:]+@)?([^/:]+):(?!//)/*(.*?)(?:\.git)?/*$#', $url, $m)) {
            list($host, $path) = [self::alias($m[1], $home), $m[2]]; // scp-like: host may be an ssh alias
        } else {
            return ['', ''];
        }
        $host = strtolower($host);
        $host = in_array($host, ['ssh.github.com', 'www.github.com'], true) ? 'github.com' : $host;
        return [$host, $path];
    }

    public static function hosting($host)
    {
        return in_array(strtolower($host), self::HOSTS, true);
    }

    /** The HostName ~/.ssh/config gives a plain `Host` alias, or the alias itself. */
    public static function alias($host, $home)
    {
        $text = $home === '' ? false : @file_get_contents("$home/.ssh/config", false, null, 0, 262144);
        $match = false;
        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^\s*(Host|Match)\s+(.*)$/i', $line, $m)) {
                $match = strtolower($m[1]) === 'host' && in_array(strtolower($host), preg_split('/\s+/', strtolower(trim($m[2]))), true);
            } elseif ($match && preg_match('/^\s*HostName[\s=]+(\S+)/i', $line, $m)) {
                return $m[1];
            }
        }
        return $host;
    }

    /** A file name that usually holds a credential: .env files (not their examples), keys, certificates' private parts. */
    public static function credentialName($path)
    {
        $b = basename((string) $path);
        return (preg_match('/^\.env(\..+)?$/i', $b) && !preg_match('/\.(example|sample|template|dist|defaults?)$/i', $b))
            || preg_match('/\.(pem|key|p12|pfx)$/i', $b) || $b === '.netrc'
            || (preg_match('/^id_(rsa|dsa|ecdsa|ed25519)/', $b) && !preg_match('/\.pub$/', $b))
            || preg_match('/^credentials(\.(json|ya?ml|ini|toml|xml|csv|txt|cfg|conf|env))?$/i', $b);
    }

    /** The masked line when Redact removes something from it, else null. A line is read in 4096-byte pieces. */
    public static function masked($line)
    {
        foreach (str_split((string) $line, 4096) as $piece) {
            try {
                $out = Redact::secrets($piece);
            } catch (CliError $e) {
                continue; // too complex for the regex engine: not a hit
            }
            if (substr_count($out, 'REDACTED') > substr_count($piece, 'REDACTED')) {
                $out = trim((string) preg_replace('/\s+/', ' ', $out));
                return preg_match('/^.{' . (self::LINE_SHOWN + 1) . '}/su', $out) ? preg_replace('/^(.{' . self::LINE_SHOWN . '}).*$/su', '$1', $out) . '...' : $out;
            }
        }
        return null;
    }

    /** Hits in a text: "<where>: <masked line>" per line that holds a secret. */
    public static function text($text, $where, array &$hits)
    {
        $n = 0;
        foreach (explode("\n", (string) $text) as $line) {
            $n++;
            $m = count($hits) < self::HITS_MAX ? self::masked($line) : null;
            if ($m !== null) {
                $hits[] = "$where line $n: $m";
            }
        }
    }

    /** A published file: its name, and its text when it is a readable file of at most FILE_MAX bytes without NUL bytes. */
    public static function file($path, $shown, array &$hits)
    {
        if (self::credentialName($path)) {
            $hits[] = "$shown: a file named like a credential file";
        }
        if (!is_file($path) || !is_readable($path)) {
            return "$shown could not be read to check it";
        }
        if (filesize($path) > self::FILE_MAX) {
            return null;
        }
        $text = (string) @file_get_contents($path);
        if (strpos($text, "\0") === false) {
            self::text($text, $shown, $hits);
        }
        return null;
    }

    /**
     * What `git log -p $revs` adds, read until $deadline: commit messages, added lines (with the commit, file and line)
     * and the names of added, renamed or copied files. Returns true when everything was read.
     */
    public static function commits($dir, array $revs, $deadline, array &$hits)
    {
        $state = ['commit' => '', 'file' => '', 'line' => 0, 'diff' => false];
        $read = function ($line) use (&$state, &$hits, $deadline) {
            if (microtime(true) > $deadline || count($hits) >= self::HITS_MAX) {
                return false;
            }
            if (preg_match('/^\x01([0-9a-f]{7,})$/', $line, $m)) {
                $state = ['commit' => $m[1], 'file' => '', 'line' => 0, 'diff' => false];
            } elseif (strpos($line, 'diff --git ') === 0) {
                $state['diff'] = true;
            } elseif (!$state['diff']) {
                $m = self::masked($line);
                $hits = $m === null ? $hits : array_merge($hits, ["commit {$state['commit']} message: $m"]);
            } elseif (preg_match('#^(?:\+\+\+ b/|rename to |copy to )(.*)$#', $line, $m)) {
                $state['file'] = $m[1];
                $hits = self::credentialName($m[1]) ? array_merge($hits, ["commit {$state['commit']} adds {$m[1]}: a file named like a credential file"]) : $hits;
            } elseif (preg_match('/^@@ -\S+ \+(\d+)/', $line, $m)) {
                $state['line'] = (int) $m[1];
            } elseif (substr($line, 0, 1) === '+' && strpos($line, '+++ ') !== 0) {
                $m = self::masked(substr($line, 1));
                $hits = $m === null ? $hits : array_merge($hits, ["commit {$state['commit']} {$state['file']}:{$state['line']}: $m"]);
                $state['line']++;
            } elseif (substr($line, 0, 1) === ' ') {
                $state['line']++;
            }
            return true;
        };
        $r = ToolProc::run(array_merge(['git', '-C', $dir, 'log', '-p', '--no-color', '--no-ext-diff', '--no-textconv', '--format=%x01%h%n%B'],
            $revs), ['GIT_PAGER' => 'cat', 'LC_ALL' => 'C'], $read);
        return !$r['stopped'] && $r['code'] === 0;
    }

    /** One git command's trimmed output, or null when it fails. */
    public static function git($dir, array $args)
    {
        try {
            $r = ToolProc::run(array_merge(['git', '-C', $dir], $args), ['GIT_PAGER' => 'cat', 'GIT_TERMINAL_PROMPT' => '0']);
        } catch (CliError $e) {
            return null;
        }
        return $r['code'] === 0 ? trim($r['out']) : null;
    }
}
