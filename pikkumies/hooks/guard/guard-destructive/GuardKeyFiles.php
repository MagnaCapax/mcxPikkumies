<?php
/**
 * The AI key rule of the guard, beside the name rule in guard-destructive.php ($secretPatterns) and in the way of
 * GuardMediaCreds (ADR-0101 key guard): the agent's terminal, code and file tools never read the model key files,
 * ~/.virsilipasKey, ~/.pikkumiesKey, or the Hermes .env files Pikkumies writes (local/hermes/.env,
 * local/hermes-managed/.env). Pikkumies hands the key to Hermes itself; `credits balance` answers balance questions
 * without it. A call is blocked when
 * - a word resolves to one of those files: ~, $HOME and ${HOME} as the home folder, a relative path against the last cd
 *   the command makes or the payload's cwd (`cd ~/pikkumies/local/hermes-managed && cat .env`);
 * - a glob would expand to one: each token with * ? [ or { is resolved the same way, its folder part expanded with
 *   glob() (bounded: at most GuardMediaCreds::MAX_DIRS folders, else blocked), and its last part matched against the
 *   file's name with fnmatch, whether the file exists here or not (`cat ~/.v*Key`);
 * - it searches content recursively from the home folder itself (GuardMediaCreds::homeSearch), unless its exclude options
 *   cover every key file name (.virsilipasKey, .pikkumiesKey, .env) (`grep -rh ^key= ~` is blocked). A search rooted in a
 *   subfolder that holds none of them is allowed.
 * checkStateDirs() keeps the same three ways (relative path, cd or cwd, glob) away from the halt snapshot and the
 * scheduled-job record (local/state/halt, local/state/ask), which the name rule alone missed (CWE review 2026-10-20).
 * Out of its sight (a safety prompt, not security, AGENTS.md): a name or path built in a variable, in code from pieces,
 * or by a program that reads files itself.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';
require_once __DIR__ . '/GuardMediaCreds.php';

class GuardKeyFiles extends GuardMediaCreds
{
    const WHY_KEY = 'a command on the AI key (~/.virsilipasKey, ~/.pikkumiesKey, the Hermes .env); what the agent reads goes to the model. Pikkumies passes the key to Hermes itself; for the balance use credits balance';

    /** The files this rule keeps the agent away from: the local dir both as it is and through the ~/pikkumies link the installer makes. */
    public static function files($home, $localDir)
    {
        $out = [$home . '/.virsilipasKey', $home . '/.pikkumiesKey'];
        foreach (array_unique([$localDir, $home . '/pikkumies/local']) as $local) {
            $out[] = $local . '/hermes/.env';
            $out[] = $local . '/hermes-managed/.env';
        }
        return $out;
    }

    /** The block message, or null. $cmds: the forms of the command (HookPayload texts); $cwd: the payload's cwd. */
    public static function checkKeys($tool, array $input, array $cmds, $cwd, $home, $localDir)
    {
        $home = self::real($home);
        if ($home === '') {
            return null;
        }
        $files = [];
        foreach (self::files($home, self::real($localDir)) as $f) {
            $files[] = [self::real(dirname($f)), PathName::base($f)];
        }
        foreach ($cmds as $cmd) {
            $base = self::base($cmd, $cwd, $home);
            foreach (array_slice(self::words($cmd), 0, 128) as $w) {
                $path = self::resolve($w['text'], $base, $home);
                if ($path === null || $path === '') {
                    continue;
                }
                $path = self::fold($path);
                $cut = strrpos($path, '/');
                $dir = $cut === 0 ? '/' : substr($path, 0, $cut);
                $last = substr($path, $cut + 1);
                $globbed = $w['glob'] || $w['qglob'];
                $dirs = $globbed && strpbrk($dir, '*?[{') !== false ? glob($dir, GLOB_ONLYDIR | GLOB_NOSORT | GLOB_BRACE) : [$dir];
                if (!is_array($dirs) || count($dirs) > self::MAX_DIRS) {
                    return self::WHY_KEY; // too wide to check: the command can name a narrower folder
                }
                foreach ($dirs as $d) {
                    $d = self::real($d);
                    foreach ($files as $f) {
                        if ($d === $f[0] && ($last === $f[1] || ($globbed && fnmatch($last, $f[1], FNM_PERIOD)))) {
                            return self::WHY_KEY;
                        }
                    }
                }
            }
            if (!self::keysExcluded($cmd) && self::homeSearch($tool, $input, $cmd, $cwd, $home)) {
                return 'a content search from the home folder itself, which would read the AI key files into the model. Search a subfolder instead';
            }
        }
        return null;
    }

    const WHY_STATE = 'a command on the halt guard\'s and the ask review\'s records of what the user asked (~/pikkumies/local/state/halt/, state/ask/), the only evidence the ask review trusts; the agent never needs them';

    /** The folders whose records the ask review reads as the user's own words: the halt snapshot and the scheduled-job record. */
    public static function stateDirs($home, $localDir)
    {
        $out = [];
        foreach (array_unique([$localDir, $home . '/pikkumies/local']) as $local) {
            foreach (['/state/halt', '/state/ask'] as $sub) {
                $out[] = self::fold($local . $sub);
                $out[] = self::fold(self::real($local . $sub));
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The block message, or null, for a command that reaches the state folders by a relative path, a cd, the payload's
     * cwd or a glob, the ways the name rule in guard-destructive.php ($secretPatterns) cannot see (ADR-0101: an agent
     * that could write there could forge the evidence that clears a scheduled job's delete). A glob counts when it could
     * expand into one, whether the folder exists yet or not.
     */
    public static function checkStateDirs(array $cmds, $cwd, $home, $localDir)
    {
        $home = self::real($home);
        if ($home === '') {
            return null;
        }
        $dirs = self::stateDirs($home, self::real($localDir));
        $hit = function ($path) use ($dirs) {
            $path = self::fold($path);
            $forms = array_unique([$path, self::fold(self::real($path))]);
            $glob = strpbrk($path, '*?[{') !== false;
            foreach ($dirs as $d) {
                foreach ($forms as $p) {
                    if ($p === $d || strpos($p, $d . '/') === 0) {
                        return true;
                    }
                }
                // The path or a folder above it could expand to the state folder (a * never crosses a /).
                for ($a = $path; $glob && $a !== '/' && $a !== '.' && $a !== ''; $a = dirname($a)) {
                    if (fnmatch($a, $d, FNM_PATHNAME)) {
                        return true;
                    }
                }
            }
            return false;
        };
        foreach ($cmds as $cmd) {
            $base = self::base($cmd, $cwd, $home);
            if ($base !== '' && $hit($base)) {
                return self::WHY_STATE;
            }
            foreach (array_slice(self::words($cmd), 0, 128) as $w) {
                $path = self::resolve($w['text'], $base, $home);
                if ($path !== null && $path !== '' && $hit($path)) {
                    return self::WHY_STATE;
                }
            }
        }
        return null;
    }

    /** True when the search's exclude options (as GuardMediaCreds reads them) cover every key file name. */
    private static function keysExcluded($cmd)
    {
        $re = '#(?<![\w-])(--exclude|--ignore|--glob|--iglob|-g|(?:!|-not)\s+-i?name)(=|\s+)(["\']?)(!?)([^\s"\']+)\3#';
        preg_match_all($re, (string) $cmd, $m, PREG_SET_ORDER);
        $left = ['.virsilipasKey' => true, '.pikkumiesKey' => true, '.env' => true];
        foreach ($m as $hit) {
            $neg = in_array($hit[1], ['--exclude', '--ignore'], true) || $hit[4] === '!' || preg_match('/^(!|-not)\s/', $hit[1]);
            foreach (array_keys($left) as $name) {
                if ($neg && (fnmatch($hit[5], $name, FNM_PERIOD) || fnmatch($hit[5], $name))) {
                    unset($left[$name]);
                }
            }
        }
        return !$left;
    }

    /** /./ and dir/../ folded, so a path written around the file still names it. */
    private static function fold($path)
    {
        $out = [];
        foreach (explode('/', $path) as $i => $part) {
            if ($part === '' && $i > 0 || $part === '.') {
                continue;
            }
            if ($part === '..' && $out && end($out) !== '') {
                array_pop($out);
                continue;
            }
            $out[] = $part;
        }
        return implode('/', $out) === '' ? '/' : implode('/', $out);
    }
}
