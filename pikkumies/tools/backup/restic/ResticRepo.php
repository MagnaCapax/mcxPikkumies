<?php
/**
 * ResticRepo: how the restic unit runs restic and reads its JSON (restic 0.11.0 and 0.14.0, the
 * Debian 11 and 12 versions; README.md here). The repository and the password file go in
 * RESTIC_REPOSITORY and RESTIC_PASSWORD_FILE (doc/040_backup.rst, "Environment Variables");
 * RESTIC_PASSWORD and RESTIC_PASSWORD_COMMAND are removed from the environment, because restic
 * prefers RESTIC_PASSWORD over the file and refuses a file together with a command
 * (cmd/restic/global.go:281, 338-340, 0.11.0); so is RESTIC_REPOSITORY_FILE, which restic refuses
 * together with a repository (global.go:105, 395, 0.11.0).
 */
class ResticRepo
{
    private $store;
    private $script;
    private $version = false;

    public function __construct(BackupStore $store, $script)
    {
        $this->store = $store;
        $this->script = $script;
    }

    public function bin()
    {
        return ToolProc::need('restic', 'restic is not installed here. PMSS installs it on Debian 10-12 (scripts/lib/update/dpkg/'
            . 'selections-debian12.txt:1389); its Debian 13 list has none. This tool never installs anything: the user installs it'
            . ' or asks the provider.');
    }

    /** "0.14.0" from "restic 0.14.0 compiled with ... on linux/amd64" (cmd/restic/cmd_version.go:24, 0.11.0), or null. */
    public function version()
    {
        if ($this->version === false) {
            $this->bin();
            $v = ToolProc::run(['restic', 'version']);
            $this->version = preg_match('/^restic (\d+\.\d+(\.\d+)?)/', $v['out'], $m) ? $m[1] : null;
        }
        return $this->version;
    }

    /** Runs restic against the logged-in repository. $gentle: nice 19 and idle I/O. */
    public function run(array $args, $onLine = null, $gentle = false)
    {
        $this->bin();
        list($repo, $file) = $this->store->usable($this->script);
        $argv = array_merge(['restic'], $args);
        $env = ['RESTIC_REPOSITORY' => $repo, 'RESTIC_PASSWORD_FILE' => $file, 'RESTIC_PASSWORD' => null, 'RESTIC_PASSWORD_COMMAND' => null,
            'RESTIC_REPOSITORY_FILE' => null];
        return ToolProc::run($gentle ? ToolProc::gentle($argv) : $argv, $env, $onLine);
    }

    public function must(array $args, $gentle = false)
    {
        $r = $this->run($args, null, $gentle);
        if ($r['code'] !== 0) {
            throw new CliError('restic ' . $args[0] . ' failed: ' . ToolText::tail($r['err']),
                'see the error; `stats` checks the repository and the login');
        }
        return $r;
    }

    public function json(array $args)
    {
        $d = json_decode($this->must($args)['out'], true);
        return is_array($d) ? $d : [];
    }

    /**
     * A snapshot as the shared record. Fields of `snapshots --json`: id, short_id, time, paths, hostname,
     * username, tags (internal/restic/snapshot.go:18-28, cmd/restic/cmd_snapshots.go:303-304, 0.14.0).
     */
    public static function record(array $s)
    {
        return ['id' => isset($s['short_id']) ? $s['short_id'] : substr((string) $s['id'], 0, 8), 'time' => self::time($s['time']),
            'host' => isset($s['hostname']) ? $s['hostname'] : null, 'paths' => isset($s['paths']) ? $s['paths'] : [],
            'tags' => isset($s['tags']) ? $s['tags'] : [], 'snapshot_id' => (string) $s['id']];
    }

    /** RFC 3339 with nanoseconds -> unix seconds. */
    public static function time($t)
    {
        return (int) strtotime((string) preg_replace('/\.\d+/', '', (string) $t));
    }
}
