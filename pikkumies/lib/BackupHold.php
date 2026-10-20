<?php
/**
 * BackupHold: what the delete guard's backup check reads (ADR-0099). classify() names what a shell command removes:
 * rm, unlink, shred and truncate (targets), `rm -r` and package removal (apt, apt-get, aptitude remove|purge|autoremove,
 * dpkg -r|-P) as dangerous. An append (>>), an install and a copy are not removals and are never named. recent() is the
 * record `backup-safety snap` keeps (local/state/backup-snap/recent.json: real path or package:<name> => time); covered()
 * says whether every target was copied in the last 30 minutes. marker() is the once-per-command hold: the identical
 * command in the same session within 30 minutes goes on (local/state/backup-hold/<sha1 of session NUL command>).
 */
require_once __DIR__ . '/PathName.php';

class BackupHold
{
    const WINDOW = 1800;
    const KEEP = 86400;
    const SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';
    /** Folders whose files are scratch by definition: removing only these needs no copy. */
    const SCRATCH = ['/tmp', '/var/tmp', '/dev/shm'];
    const WRAPPERS = ['sudo', 'doas', 'env', 'command', 'exec', 'nice', 'ionice', 'nohup', 'time', 'stdbuf', 'then', 'do', 'else', '!', '{'];

    /** ['danger' => bool, 'targets' => paths as written, 'packages' => names, 'found' => bool]. */
    public static function classify($command)
    {
        $t = FileopsClassify::words((string) $command);
        $out = ['danger' => false, 'targets' => [], 'packages' => [], 'found' => false];
        $segments = [[]];
        foreach ($t['words'] as $w) {
            if (isset($w['op'])) {
                $segments[] = [];
            } else {
                $segments[count($segments) - 1][] = $w['w'];
            }
        }
        foreach ($segments as $seg) {
            while ($seg && (in_array(PathName::base($seg[0]), self::WRAPPERS, true) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $seg[0])
                || ($seg[0] !== '' && $seg[0][0] === '-'))) {
                array_shift($seg); // X=1, sudo -n, env -i: the command comes after them
            }
            if (!$seg) {
                continue;
            }
            $verb = PathName::base(array_shift($seg));
            list($opts, $args) = self::split($seg, $verb === 'truncate' ? ['-s', '--size', '-r', '--reference'] : []);
            $optText = ' ' . implode(' ', $opts) . ' ';
            if (in_array($verb, ['rm', 'unlink', 'shred', 'truncate'], true)) {
                $out['found'] = true;
                $out['targets'] = array_merge($out['targets'], $args);
                $out['danger'] = $out['danger'] || ($verb === 'rm' && preg_match('/ (-[a-zA-Z]*[rR][a-zA-Z]*|--recursive) /', $optText));
            } elseif (in_array($verb, ['apt', 'apt-get', 'aptitude'], true) && $args && in_array($args[0], ['remove', 'purge', 'autoremove', 'autopurge'], true)) {
                list($out['found'], $out['danger']) = [true, true];
                $out['packages'] = array_merge($out['packages'], array_slice($args, 1));
            } elseif ($verb === 'dpkg' && preg_match('/ (-[a-zA-Z]*[rP][a-zA-Z]*|--remove|--purge) /', $optText)) {
                list($out['found'], $out['danger']) = [true, true];
                $out['packages'] = array_merge($out['packages'], $args);
            }
        }
        return $out;
    }

    /** [options, arguments]; an option in $valued takes the next word. Everything after -- is an argument. */
    private static function split(array $words, array $valued)
    {
        $opts = [];
        $args = [];
        for ($i = 0, $n = count($words), $end = false; $i < $n; $i++) {
            $w = $words[$i];
            if (!$end && $w === '--') {
                $end = true;
            } elseif (!$end && $w !== '' && $w[0] === '-' && $w !== '-') {
                $opts[] = $w;
                $i += in_array($w, $valued, true) ? 1 : 0;
            } else {
                $args[] = $w;
            }
        }
        return [$opts, $args];
    }

    /** Absolute targets ($cwd resolves relative ones; ~ is the home); null when one cannot be resolved here. */
    public static function absolute(array $targets, $cwd, $home)
    {
        $out = [];
        foreach ($targets as $t) {
            $t = $t === '~' || strpos($t, '~/') === 0 ? rtrim($home, '/') . substr($t, 1) : $t;
            if ($t === '' || ($t[0] !== '/' && ($cwd === '' || $cwd[0] !== '/'))) {
                return null;
            }
            $out[] = $t[0] === '/' ? $t : rtrim($cwd, '/') . '/' . $t;
        }
        return $out;
    }

    /**
     * True when every target lies in a scratch folder and outside the home (no target is never scratch). An existing target is
     * resolved itself too: truncate, shred and > follow a symlink, so /tmp/link -> ~/data is not scratch.
     */
    public static function scratch(array $abs, $home)
    {
        foreach ($abs as $p) {
            $places = [realpath(dirname($p))];
            if (file_exists($p) || is_link($p)) {
                $places[] = realpath($p); // a dangling link is false, so not scratch
            }
            foreach ($places as $real) {
                $ok = false;
                foreach (self::SCRATCH as $s) {
                    $ok = $ok || ($real !== false && HomePath::inside($real, $s) && ($home === '' || !HomePath::inside($real, $home)));
                }
                if (!$ok) {
                    return false;
                }
            }
        }
        return (bool) $abs;
    }

    public static function recentFile($localDir)
    {
        return rtrim($localDir, '/') . '/state/backup-snap/recent.json';
    }

    /** The snaps of the last day: real path or package:<name> => time. */
    public static function recent($localDir, $now)
    {
        $f = self::recentFile($localDir);
        $d = is_file($f) && !is_link($f) ? json_decode((string) @file_get_contents($f, false, null, 0, 1048576), true) : null;
        return array_filter(is_array($d) ? $d : [], function ($t) use ($now) {
            return is_int($t) && $t > $now - self::KEEP && $t <= $now + 60;
        });
    }

    public static function saveRecent($localDir, array $recent)
    {
        $f = self::recentFile($localDir);
        if (!is_dir(dirname($f)) && !@mkdir(dirname($f), 0700, true)) {
            return false;
        }
        $old = umask(0077);
        $ok = @file_put_contents("$f.tmp", json_encode($recent, JSON_UNESCAPED_SLASHES)) !== false && @rename("$f.tmp", $f);
        umask($old);
        return $ok;
    }

    /** True when every target (absolute paths: the real path, or inside a copied folder) and package was copied within WINDOW. */
    public static function covered(array $abs, array $packages, array $recent, $now)
    {
        if (!$abs && !$packages) {
            return false;
        }
        $fresh = array_filter($recent, function ($t) use ($now) {
            return $t >= $now - self::WINDOW;
        });
        foreach ($packages as $p) {
            if (!isset($fresh["package:$p"])) {
                return false;
            }
        }
        foreach ($abs as $p) {
            $real = realpath($p);
            $hit = false;
            foreach (array_keys($fresh) as $copied) {
                $hit = $hit || ($real !== false && strpos($copied, 'package:') !== 0 && HomePath::inside($real, $copied));
            }
            if (!$hit) {
                return false;
            }
        }
        return true;
    }

    /** 'again' (held once already, within WINDOW), 'hold' (recorded now) or 'none' (no session id or no marker: never hold). */
    public static function marker($localDir, $session, $command, $now)
    {
        if (!preg_match(self::SESSION_RE, (string) $session)) {
            return 'none';
        }
        $dir = rtrim($localDir, '/') . '/state/backup-hold';
        $f = $dir . '/' . sha1($session . "\0" . $command);
        clearstatcache(true, $f);
        $m = @filemtime($f);
        if ($m !== false && $m >= $now - self::WINDOW && $m <= $now + 5) {
            return 'again';
        }
        if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || !@touch($f, $now)) {
            return 'none';
        }
        foreach ((array) glob("$dir/*") as $old) {
            if (@filemtime($old) < $now - self::KEEP) {
                @unlink($old);
            }
        }
        return 'hold';
    }
}
