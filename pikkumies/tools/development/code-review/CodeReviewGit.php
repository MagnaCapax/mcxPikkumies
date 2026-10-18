<?php
/**
 * CodeReviewGit: the read-only git half of `code-review context`. It runs only `rev-parse`, `diff --numstat` and
 * `status --porcelain`, each word escaped (lib/Update.php exec(); PHP 7.3 proc_open takes no argv array), never a
 * shell string built from what the user typed. The project under review may be someone else's code, so its own git
 * config must not run anything: core.fsmonitor is forced off, external diff and textconv drivers are skipped, and
 * GIT_OPTIONAL_LOCKS=0 keeps `status` from rewriting the index. A ref starting with "-" is refused before git sees it.
 */
class CodeReviewGit
{
    const LINES_TARGET = 150;     // the file size target of the engineering principles (rule 2)
    const COUNT_MAX = 2097152;    // bytes: a bigger file is not read to count its lines

    /** [exit code, stdout, stderr] of git in $dir. */
    public static function git($dir, array $args)
    {
        $env = ['env', 'GIT_OPTIONAL_LOCKS=0', 'GIT_TERMINAL_PROMPT=0'];
        $safe = ['git', '--no-pager', '-c', 'core.fsmonitor=false', '-c', 'core.quotePath=false', '-C', $dir];
        return Update::exec(array_merge($env, $safe, $args));
    }

    /** The project root (git top level) of $path; throws when $path is not inside a git work tree. */
    public static function root($path)
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new CliError("not a directory: $path", 'give --path a directory inside the project to review');
        }
        list($code, $out, $err) = self::git($real, ['rev-parse', '--show-toplevel']);
        if ($code !== 0 || trim($out) === '') {
            throw new CliError("not inside a git work tree: $real", trim(strtok($err . "\n", "\n")) ?: 'run it inside a git checkout, or give --path');
        }
        return rtrim($out, "\n");
    }

    /** The commit a ref names, or a CliError. Never an option: "-x" and "--output=..." are refused here. */
    public static function commit($root, $ref)
    {
        if (!is_string($ref) || $ref === '' || $ref[0] === '-' || strpos($ref, "\0") !== false) {
            throw new CliError('--base needs a git ref (a branch, tag or commit), and a ref never starts with "-"', 'for example --base main or --base HEAD~3');
        }
        list($code, $out) = self::git($root, ['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);
        if ($code !== 0 || trim($out) === '') { // --verify prints exactly one object name or fails
            throw new CliError("no such commit in this project: $ref", 'list refs with git branch -a or git log --oneline');
        }
        return trim($out);
    }

    /**
     * What changed: ['compared' => text, 'files' => [path => [added, removed]]]. With a base: base to the working
     * tree plus untracked files. Without: uncommitted changes plus untracked files, or HEAD~1..HEAD on a clean tree.
     */
    public static function changes($root, $base)
    {
        $untracked = self::untracked($root, $dirty);
        $head = self::git($root, ['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);
        if ($base !== null) {
            $files = self::numstat($root, [self::commit($root, $base)]);
            $compared = "$base to the working tree, untracked files included";
        } elseif ($head[0] !== 0) {
            $files = [];
            $compared = 'no commits yet: every file is new';
            $untracked = array_merge($untracked, self::paths($root)); // nothing to diff against
        } elseif ($dirty) {
            $files = self::numstat($root, ['HEAD']);
            $compared = 'uncommitted changes (HEAD to the working tree), untracked files included';
        } elseif (self::git($root, ['rev-parse', '--verify', '--quiet', 'HEAD~1^{commit}'])[0] === 0) {
            $files = self::numstat($root, ['HEAD~1', 'HEAD']);
            $compared = 'clean tree: the last commit (HEAD~1..HEAD)';
        } else {
            return ['compared' => 'clean tree and HEAD is the first commit: nothing to compare; give --base', 'files' => []];
        }
        foreach ($untracked as $p) {
            $files += [$p => [self::lineCount("$root/$p"), 0]];
        }
        ksort($files);
        return ['compared' => $compared, 'files' => $files];
    }

    /** `git diff --numstat` of $revs (one rev: to the working tree) as [path => [added|null, removed|null]]. */
    private static function numstat($root, array $revs)
    {
        $args = array_merge(['diff', '--numstat', '-z', '--no-renames', '--no-ext-diff', '--no-textconv'], $revs, ['--']);
        list($code, $out, $err) = self::git($root, $args);
        if ($code !== 0) {
            throw new CliError('git diff failed', trim(strtok($err . "\n", "\n")));
        }
        $files = [];
        foreach (explode("\0", $out) as $rec) {
            $f = explode("\t", $rec, 3);
            if (count($f) === 3 && $f[2] !== '') {
                $files[$f[2]] = [$f[0] === '-' ? null : (int) $f[0], $f[1] === '-' ? null : (int) $f[1]]; // "-": binary
            }
        }
        return $files;
    }

    /** Untracked paths; $dirty says whether `status` listed anything at all (staged, modified or untracked). */
    private static function untracked($root, &$dirty)
    {
        list($code, $out, $err) = self::git($root, ['status', '--porcelain', '-z', '--untracked-files=all']);
        if ($code !== 0) {
            throw new CliError('git status failed', trim(strtok($err . "\n", "\n")));
        }
        $tokens = explode("\0", rtrim($out, "\0"));
        $dirty = $out !== '';
        $paths = [];
        for ($i = 0; $i < count($tokens); $i++) {
            $t = $tokens[$i];
            if (substr($t, 0, 3) === '?? ') {
                $paths[] = substr($t, 3);
            } elseif (strlen($t) > 1 && strpbrk(substr($t, 0, 2), 'RC') !== false) {
                $i++; // a rename or copy carries its old path as the next token
            }
        }
        return $paths;
    }

    /** Staged paths of a project without commits (status lists them; "A  path"). */
    private static function paths($root)
    {
        list(, $out) = self::git($root, ['status', '--porcelain', '-z', '--untracked-files=no']);
        $paths = [];
        foreach (explode("\0", rtrim($out, "\0")) as $t) {
            if (strlen($t) > 3) {
                $paths[] = substr($t, 3); // no renames without a commit to rename from
            }
        }
        return $paths;
    }

    /** Lines of a regular file (not a symlink, not binary, at most COUNT_MAX bytes), else null. */
    public static function lineCount($file)
    {
        if (is_link($file) || !is_file($file) || filesize($file) > self::COUNT_MAX) {
            return null;
        }
        $text = (string) @file_get_contents($file);
        if (strpos(substr($text, 0, 8000), "\0") !== false) {
            return null;
        }
        return substr_count($text, "\n") + ($text !== '' && substr($text, -1) !== "\n" ? 1 : 0);
    }
}
