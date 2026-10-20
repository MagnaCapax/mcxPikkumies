<?php
/**
 * PublishTargets: the publishing commands in a shell text (ADR-0083): `git push` and the gh writes that carry text
 * (issue create/comment, pr create/comment, gist create, release create). Words come from the SSH check's splitter
 * (hooks/guard/ssh-connect/SshWords.php, ADR-0068), so quotes and substitutions read the same way. The directory is
 * `git -C`, then a `cd` earlier in the same text, then the call's own directory. A command whose program is built in a
 * variable, run through `sh -c` or an alias, or a cd made in an earlier call is out of sight.
 */
require_once dirname(__DIR__) . '/ssh-connect/SshWords.php';

class PublishTargets
{
    const MENTION_RE = '/\bgit\b[^\n]*\bpush\b|\bgh\b[^\n]*\b(issue|pr|gist|release)\b/';
    const GH_WRITES = ['issue' => ['create', 'comment'], 'pr' => ['create', 'comment'], 'gist' => ['create'], 'release' => ['create']];
    /** gh flags that take a value, per command group; the text-carrying ones are scanned (TEXT_FLAGS, FILE_FLAGS). */
    const GH_VALUED = [
        'issue' => ['-t', '--title', '-b', '--body', '-F', '--body-file', '-a', '--assignee', '-l', '--label', '-m', '--milestone', '-p', '--project', '-T', '--template'],
        'pr' => ['-t', '--title', '-b', '--body', '-F', '--body-file', '-a', '--assignee', '-l', '--label', '-m', '--milestone', '-p', '--project', '-T', '--template', '-B', '--base', '-H', '--head', '-r', '--reviewer'],
        'gist' => ['-d', '--desc', '-f', '--filename'],
        'release' => ['-t', '--title', '-n', '--notes', '-F', '--notes-file', '--target', '--notes-start-tag', '--discussion-category'],
    ];
    const TEXT_FLAGS = ['-t', '--title', '-b', '--body', '-n', '--notes', '-d', '--desc'];
    const FILE_FLAGS = ['-F', '--body-file', '--notes-file'];
    const GIT_VALUED = ['-C', '-c', '--git-dir', '--work-tree', '--namespace', '--config-env'];
    const PUSH_VALUED = ['-o', '--push-option', '--receive-pack', '--exec', '--repo'];

    /** [['kind' => 'push'|'gh', 'dir', ...], ...] for $text; $dir is the call's own directory ('' unknown). */
    public static function read($text, $dir, $home)
    {
        $split = SshWords::split((string) $text);
        if ($split === null) {
            return []; // an unclosed quote: the shell refuses it too
        }
        $found = [];
        foreach ($split['commands'] as $words) {
            while ($words && (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0]['text']) || in_array($words[0]['text'], ['env', 'command', 'nohup', 'time'], true))) {
                array_shift($words); // assignments and plain wrappers before the program
            }
            if (!$words) {
                continue;
            }
            $program = basename($words[0]['text']);
            $args = array_slice($words, 1);
            if ($program === 'cd' && isset($args[0]) && !$args[0]['dyn']) {
                $dir = self::path($args[0]['text'], $dir, $home);
            } elseif ($program === 'git') {
                $push = self::git($args, $dir, $home);
                $found = $push === null ? $found : array_merge($found, [$push]);
            } elseif ($program === 'gh') {
                $gh = self::gh($args, $dir);
                $found = $gh === null ? $found : array_merge($found, [$gh]);
            }
        }
        foreach ($split['nested'] as $inner) {
            $found = array_merge($found, self::read($inner, $dir, $home));
        }
        return $found;
    }

    /** An absolute path for $p (~ and relative to $dir), '' when it cannot be known. */
    public static function path($p, $dir, $home)
    {
        if ($p === '~' || substr($p, 0, 2) === '~/') {
            $p = $home === '' ? '' : $home . substr($p, 1);
        } elseif ($p !== '' && $p[0] !== '/') {
            $p = $dir === '' ? '' : rtrim($dir, '/') . '/' . $p;
        }
        return $p;
    }

    private static function git(array $args, $dir, $home)
    {
        $i = 0;
        for ($n = count($args); $i < $n && substr($args[$i]['text'], 0, 1) === '-'; $i++) {
            if ($args[$i]['text'] === '-C' && isset($args[$i + 1])) {
                $dir = self::path($args[$i + 1]['text'], $dir, $home);
            }
            $i += in_array($args[$i]['text'], self::GIT_VALUED, true) ? 1 : 0;
        }
        if (!isset($args[$i]) || $args[$i]['text'] !== 'push') {
            return null;
        }
        $push = ['kind' => 'push', 'dir' => $dir, 'remote' => '', 'refspecs' => [], 'all' => false, 'tags' => false];
        for ($i++, $n = count($args); $i < $n; $i++) {
            $t = $args[$i]['text'];
            if (in_array($t, ['-n', '--dry-run', '-d', '--delete'], true)) {
                return null; // publishes nothing
            } elseif (in_array($t, ['--all', '--mirror', '--branches'], true)) {
                $push['all'] = true;
            } elseif ($t === '--tags') {
                $push['tags'] = true;
            } elseif (substr($t, 0, 7) === '--repo=') {
                $push['remote'] = substr($t, 7);
            } elseif (in_array($t, self::PUSH_VALUED, true)) {
                $push['remote'] = $t === '--repo' && isset($args[$i + 1]) ? $args[$i + 1]['text'] : $push['remote'];
                $i++;
            } elseif (substr($t, 0, 1) !== '-' && $push['remote'] === '') {
                $push['remote'] = $t;
            } elseif (substr($t, 0, 1) !== '-') {
                $push['refspecs'][] = $t;
            }
        }
        return $push;
    }

    private static function gh(array $args, $dir)
    {
        $group = isset($args[0]) ? $args[0]['text'] : '';
        $sub = isset($args[1]) ? $args[1]['text'] : '';
        if (!isset(self::GH_WRITES[$group]) || !in_array($sub, self::GH_WRITES[$group], true)) {
            return null;
        }
        $valued = array_merge(['-R', '--repo'], self::GH_VALUED[$group]);
        $plain = [];
        $flags = [];
        for ($i = 2, $n = count($args); $i < $n; $i++) {
            $t = $args[$i]['text'];
            if (preg_match('/^(--[a-z-]+)=(.*)$/s', $t, $m)) {
                $flags[] = [$m[1], $m[2]];
            } elseif (in_array($t, $valued, true) && isset($args[$i + 1])) {
                $flags[] = [$t, $args[++$i]['text']];
            } elseif (preg_match('/^(-[A-Za-z])(.+)$/s', $t, $m) && in_array($m[1], $valued, true)) {
                $flags[] = [$m[1], $m[2]]; // -bTEXT
            } elseif (substr($t, 0, 1) === '-' && $t !== '-') {
                $flags[] = [$t, null];
            } else {
                $plain[] = $t;
            }
        }
        $gh = ['kind' => 'gh', 'dir' => $dir, 'what' => "gh $group $sub", 'gist' => $group === 'gist', 'repo' => '', 'texts' => [], 'files' => []];
        foreach ($flags as $f) {
            if (in_array($f[0], ['-R', '--repo'], true)) {
                $gh['repo'] = (string) $f[1];
            } elseif (in_array($f[0], self::TEXT_FLAGS, true)) {
                $gh['texts'][] = (string) $f[1];
            } elseif (in_array($f[0], self::FILE_FLAGS, true)) {
                $gh['files'][] = (string) $f[1];
            }
        }
        // gist create <files>; release create <tag> <assets>: the files that are published.
        foreach ($group === 'gist' ? $plain : ($group === 'release' ? array_slice($plain, 1) : []) as $f) {
            $gh['files'][] = (string) preg_replace('/#.*$/s', '', $f); // a release asset's #label
        }
        return $gh;
    }
}
