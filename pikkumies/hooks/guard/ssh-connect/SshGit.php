<?php
/**
 * SshGit: the SSH remotes a git command names (ADR-0068). Any word that is an ssh://, git+ssh:// or ssh+git:// URL, or the
 * scp-like user@host:path form, names a host; so does a plain host:path where git expects a repository (the first
 * argument of clone, fetch, pull, push and ls-remote), never elsewhere, where host:path is a revision and a path
 * (HEAD:README). core.sshCommand and the ext:: transport are routes this check cannot follow. A remote stored in the
 * repository (git push origin) is out of its sight.
 */
class SshGit
{
    const REPOSITORY_FIRST = ['clone', 'fetch', 'pull', 'push', 'ls-remote'];
    /** Options of git and of those commands that take the next word as their value. */
    const VALUED = ['-c', '-C', '--git-dir', '--work-tree', '--namespace', '-b', '--branch', '-o', '--origin', '--config', '--depth', '-u',
        '--upload-pack', '--receive-pack', '--exec', '--reference', '--separate-git-dir', '-j', '--jobs', '--template', '--filter'];

    public static function read(array $args)
    {
        $found = ['conns' => [], 'routes' => []];
        $hosts = [];
        $sub = null;
        $first = true;
        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $t = $args[$i]['text'];
            if (stripos($t, 'core.sshcommand') !== false || stripos($t, 'ext::') === 0) {
                $found['routes'][] = "git $t";
            }
            if ($t !== '' && $t[0] === '-') {
                $i += in_array($t, self::VALUED, true) && isset($args[$i + 1]) ? 1 : 0;
                if (in_array($t, ['-c', '--config'], true) && stripos($args[$i]['text'], 'core.sshcommand') !== false) {
                    $found['routes'][] = "git $t {$args[$i]['text']}";
                }
                continue;
            }
            $repository = $sub !== null && $first && in_array($sub, self::REPOSITORY_FIRST, true);
            if (SshUnreadable::matches('#^(?:git\+ssh|ssh\+git|ssh)://([^/]*)#i', $t, $m)) {
                SshPrograms::literal($args[$i]); // a variable in the URL: unreadable
                $hosts[] = SshPrograms::hop($m[1]);
            } elseif (strpos($t, '://') === false && (SshUnreadable::matches('#^[^@/:\s]+@#', $t) || $repository)) {
                $h = SshPrograms::remoteHost(SshPrograms::literal($args[$i]));
                if ($h !== null) {
                    $hosts[] = $h;
                }
            }
            $first = $sub === null;
            $sub = $sub === null ? $t : $sub;
        }
        foreach (array_unique($hosts) as $h) {
            $found['conns'][] = ['program' => 'git', 'names' => [$h], 'routes' => []];
        }
        return $found;
    }
}
