<?php
/**
 * BorgRepo: how the borg unit runs borg (borg 1.1.16 and 1.2.4, the Debian 11 and 12 versions;
 * README.md here). BORG_REPO names the repository, so archives are written `::name`
 * (docs/usage/general/environment.rst.inc:7-10). Borg reads the key's secret through
 * BORG_PASSCOMMAND, `cat <file>`, using its output with trailing newlines stripped
 * (environment.rst.inc:16-18). The tool drops BORG_PASSPHRASE from the environment, because borg
 * would read it before BORG_PASSCOMMAND, which it splits with shlex (src/borg/crypto/key.py:425-445, 1.2.4).
 */
class BorgRepo
{
    private $store;
    private $script;

    public function __construct(BackupStore $store, $script)
    {
        $this->store = $store;
        $this->script = $script;
    }

    public function bin()
    {
        return ToolProc::need('borg', 'borg is not installed here. PMSS installs borgbackup on Debian 10-12 (scripts/lib/update/dpkg/'
            . 'selections-debian12.txt:34); its Debian 13 list has none. This tool never installs anything.');
    }

    /** borg <args> against the logged-in repository; $cwd for extract; $gentle: nice 19 and idle I/O. */
    public function run(array $args, $onLine = null, $cwd = null, $gentle = false)
    {
        $this->bin();
        list($repo, $file) = $this->store->usable($this->script);
        $cat = ToolProc::need('cat', 'coreutils provides it');
        $argv = array_merge(['borg'], $args);
        // borg splits the command shell-style; escapeshellarg quoting survives that.
        $env = ['BORG_REPO' => $repo, 'BORG_PASSCOMMAND' => escapeshellarg($cat) . ' ' . escapeshellarg($file),
            'BORG_PASSPHRASE' => null, 'BORG_PASSPHRASE_FD' => null];
        return ToolProc::run($gentle ? ToolProc::gentle($argv) : $argv, $env, $onLine, $cwd);
    }

    public function must(array $args, $cwd = null, $gentle = false)
    {
        $r = $this->run($args, null, $cwd, $gentle);
        if ($r['code'] !== 0) {
            $locked = preg_match('/\\block(ed)?\\b|LockTimeout|LockError/i', $r['err'])
                ? ' (the repository is locked: another borg is running, or one was killed)' : '';
            throw new CliError('borg ' . $args[0] . " failed$locked: " . ToolProc::tail($r['err']),
                'see the error; `stats` checks the repository and the login');
        }
        return $r;
    }

    public function json(array $args)
    {
        $d = json_decode($this->must($args)['out'], true);
        return is_array($d) ? $d : [];
    }

    /** `borg --version` prints "borg 1.2.4" (src/borg/archiver.py:3018, 1.2.4); no repository needed. */
    public function version()
    {
        $this->bin();
        $r = ToolProc::run(['borg', '--version']);
        return preg_match('/^borg (\d+\.\d+(\.\d+)?)/', trim($r['out']), $m) ? $m[1] : null;
    }

    /**
     * An archive as the shared record: `id` is the name (borg addresses archives by name),
     * `snapshot_id` the archive id. `borg list --json` gives id, name, start
     * (docs/internals/frontends.rst, "borg list --last 1 --json", 1.2.4).
     */
    public static function record(array $a)
    {
        $start = isset($a['start']) ? (int) strtotime((string) preg_replace('/\.\d+$/', '', (string) $a['start'])) : null;
        return ['id' => (string) $a['name'], 'time' => $start, 'host' => isset($a['hostname']) ? $a['hostname'] : null,
            'paths' => [], 'tags' => [], 'snapshot_id' => isset($a['id']) ? (string) $a['id'] : null];
    }

    /** The shared keep options as borg prune flags (docs/usage/prune.rst.inc, 1.1.16 and 1.2.4). */
    public static function keepFlags(array $keep)
    {
        $flags = [];
        foreach ($keep as $k => $v) {
            $flags[] = "--$k";
            $flags[] = $v;
        }
        return $flags;
    }

    /**
     * "Would prune:" lines of `prune --dry-run --list` (stderr): the archive as name, time and
     * [id] (archiver.py:1597-1616 in 1.2.4, :1660 in 1.1.16; helpers/parseformat.py:287-292
     * format_archive; helpers/time.py:92-96, "%a, %Y-%m-%d %H:%M:%S").
     */
    public static function wouldPrune($stderr)
    {
        $out = [];
        $kept = 0;
        foreach (explode("\n", (string) $stderr) as $line) {
            $line = rtrim($line);
            if (strpos($line, 'Keeping') === 0) {
                $kept++;
            } elseif (preg_match('/^Would prune:\s+(.+?)\s+\w{3}, (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) \[([0-9a-f]{64})\]$/', $line, $m)) {
                $out[] = ['id' => $m[1], 'time' => (int) strtotime($m[2]), 'host' => null, 'paths' => [], 'tags' => [],
                    'snapshot_id' => $m[3]];
            }
        }
        return ['remove' => $out, 'keep' => $kept];
    }

    /** A path as borg stores it (no leading /) as an argv list; one starting with - would be read as an option. */
    public static function stored($path)
    {
        $p = $path === null ? '' : ltrim((string) $path, '/');
        if ($p !== '' && $p[0] === '-') {
            throw new CliError("not a stored path: $path", 'paths come from `show <name> --files`');
        }
        return $p === '' ? [] : [$p];
    }

    /** borgmatic's default config locations (borgmatic 1.7.7 borgmatic/config/collect.py:12-20): paths only. */
    public static function borgmaticConfigs()
    {
        $base = getenv('XDG_CONFIG_HOME') ?: getenv('HOME') . '/.config';
        return array_values(array_filter(['/etc/borgmatic/config.yaml', '/etc/borgmatic.d', "$base/borgmatic/config.yaml",
            "$base/borgmatic.d"], 'file_exists'));
    }
}
