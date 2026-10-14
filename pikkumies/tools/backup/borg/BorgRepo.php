<?php
/**
 * BorgRepo: how the borg unit runs borg (borg 1.1.16 and 1.2.4, the Debian 11 and 12 versions;
 * README.md here). BORG_REPO names the repository, so archives are written `::name`
 * (docs/usage/general/environment.rst.inc:7-10). Borg reads the key's secret through
 * BORG_PASSCOMMAND, `cat <file>`, using its output with trailing newlines stripped
 * (environment.rst.inc:16-18). The tool drops BORG_PASSPHRASE from the environment, because borg
 * would read it before BORG_PASSCOMMAND, which it splits with shlex (src/borg/crypto/key.py:425-445, 1.2.4).
 * borg prints times (start, end, mtime, the "Would prune" lines) in local time without a zone, so it runs with
 * TZ=UTC0 and the tool reads them as UTC (a host zone shifted them by its offset in the 2026-10-14 smoke run).
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
            'BORG_PASSPHRASE' => null, 'BORG_PASSPHRASE_FD' => null, 'TZ' => 'UTC0'];
        return ToolProc::run($gentle ? ToolProc::gentle($argv) : $argv, $env, $onLine, $cwd);
    }

    public function must(array $args, $cwd = null, $gentle = false)
    {
        $r = $this->run($args, null, $cwd, $gentle);
        if ($r['code'] !== 0) {
            $locked = preg_match('/\\block(ed)?\\b|LockTimeout|LockError/i', $r['err'])
                ? ' (the repository is locked: another borg is running, or one was killed)' : '';
            throw new CliError('borg ' . $args[0] . " failed$locked: " . ToolText::tail($r['err']),
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
        return ['id' => (string) $a['name'], 'time' => isset($a['start']) ? self::utc($a['start']) : null,
            'host' => isset($a['hostname']) ? $a['hostname'] : null,
            'paths' => [], 'tags' => [], 'snapshot_id' => isset($a['id']) ? (string) $a['id'] : null];
    }

    /** A borg time (UTC, as borg runs with TZ=UTC0) as unix time; a time that carries its own zone keeps it. Else null. */
    public static function utc($t)
    {
        if (!preg_match('/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d/', (string) $t)) {
            return null;
        }
        try {
            return (new DateTime((string) $t, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * One `list --json-lines` item as the shared file record. type: restic's words (stat.filemode's first letter in
     * borg). The second name of a hard link is "-" with mode "h...", size 0 and `source` = the first name (seen on borg
     * 1.2.4 and 1.4.0): bytes null and link_to, so a size preview never counts the data twice nor calls it empty.
     */
    public static function item(array $f)
    {
        $types = ['-' => 'file', 'd' => 'dir', 'l' => 'symlink', 'b' => 'dev', 'c' => 'chardev', 'p' => 'fifo', 's' => 'socket'];
        $t = isset($f['type']) ? (string) $f['type'] : '';
        $link = $t === '-' && (!empty($f['source']) || (isset($f['mode']) && substr((string) $f['mode'], 0, 1) === 'h'));
        $r = ['path' => '/' . $f['path'], 'type' => isset($types[$t]) ? $types[$t] : ($t === '' ? null : $t),
            'bytes' => $link || $t === 'd' || !isset($f['size']) ? null : (int) $f['size'], // restic gives folders no size either
            'modified_at' => isset($f['mtime']) ? self::utc($f['mtime']) : null];
        return $link ? $r + ['hardlink' => true, 'link_to' => isset($f['source']) ? '/' . $f['source'] : null] : $r;
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
