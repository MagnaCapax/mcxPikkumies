<?php
/**
 * JackettRun: Jackett's verb table (tools/arr/README.md, README.md here). Calls: Torznab t=indexers (list, show, stats),
 * GET results with no query (test, contacts the trackers), GET /health (health, stats; no key needed:
 * HealthcheckController.cs:8-14). Jackett keeps no history (no such route: README.md), so `history` says so.
 * Restart: the user's own Docker container only; no PMSS installer manages Jackett (install-media-stack.sh:101).
 */
class JackettRun
{
    public static function run(array $argv, $usage)
    {
        $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $home = ArrLocate::home($uid);
        $locate = function ($lenient = false) use ($home, $family, $uid) {
            if ($home === '' || !is_dir($home)) {
                throw new CliError('no home folder of this user found (HOME, or the passwd entry)', 'run the tool as the seedbox user');
            }
            $cfg = Config::load();
            $over = isset($cfg['arr']['jackett']) && is_array($cfg['arr']['jackett']) ? $cfg['arr']['jackett'] : [];
            return JackettConfig::find($home, $family, $uid, $over, $lenient);
        };
        $timeout = Arr::timeout();
        $fresh = function () use ($locate, $timeout) {
            return new ArrClient($locate(), $locate, '', $timeout);
        };
        $client = null;
        $c = function () use (&$client, $fresh) {
            return $client !== null ? $client : ($client = call_user_func($fresh));
        };
        Cli::run($argv, self::verbs($c, $locate, $timeout), $usage, [], ['search']);
    }

    public static function verbs(callable $c, callable $locate, $timeout)
    {
        return [
            'list' => function ($args, $opt) use ($c) {
                $s = isset($opt['search']) && is_string($opt['search']) ? strtolower($opt['search']) : null;
                $rows = array_map(['JackettIndexers', 'short'], JackettIndexers::all($c()));
                return array_values(array_filter($rows, function ($r) use ($s) {
                    return $s === null || strpos(strtolower($r['name'] . ' ' . $r['id']), $s) !== false;
                }));
            },
            'show' => function ($args, $opt) use ($c) {
                return JackettIndexers::resolve(JackettIndexers::all($c()), $args);
            },
            'stats' => function ($args, $opt) use ($c, $timeout) {
                $w = $c()->where();
                $warnings = self::health(self::anonymous($w, $timeout));
                return [['app' => 'Jackett', 'version' => null, 'started_at' => null, 'found' => $w['source'],
                    'config' => str_replace($w['home'], '~', $w['config']), 'port' => $w['port'], 'url_base' => $w['urlBase'],
                    'process' => 'answering', 'indexers' => count(JackettIndexers::all($c())), 'health_warnings' => count($warnings),
                    'note' => 'Jackett shows its version and start time only to a logged-in admin; this tool uses the API key only']];
            },
            'health' => function ($args, $opt) use ($c, $timeout) {
                $rows = self::health(self::anonymous($c()->where(), $timeout));
                $note = 'Jackett has no health checks of its own; /health answered OK. `test` checks whether each indexer answers';
                return ['records' => $rows, 'status' => $rows ? [] : ['note' => $note]];
            },
            'history' => function ($args, $opt) {
                throw new CliError('not available in Jackett: it keeps no history of searches or grabs (its API has no such list)',
                    '`test` shows whether each indexer answers now; Jackett\'s own log is under View logs in its web page');
            },
            'restart' => function ($args, $opt) use ($locate, $timeout) {
                $live = function () use ($locate, $timeout) { // config read again each time: the Docker port may change
                    return self::anonymous($locate('key'), $timeout);
                };
                return [ArrRestart::run(JackettConfig::APP, $locate(true), $live, function () use ($live) {
                    return self::started($live);
                })];
            },
            'test' => function ($args, $opt) use ($c) {
                return ['records' => JackettIndexers::test($c(), JackettIndexers::all($c()), $args),
                    'status' => ['note' => 'each indexer ran an empty search; an answer can come from Jackett\'s result cache']];
            },
            'login-check' => function ($args, $opt) use ($locate, $timeout) {
                return JackettLogin::check($locate, $timeout);
            },
        ];
    }

    /** A client with no key at all: /health needs none (HealthcheckController.cs:8), so none is sent. */
    private static function anonymous(array $where, $timeout)
    {
        $w = ['key' => ''] + $where;
        return new ArrClient($w, function () use ($w) {
            return $w;
        }, '', $timeout);
    }

    /** /health as warning records: none when it says OK. */
    private static function health(ArrClient $anon)
    {
        $h = $anon->call('GET', 'health', [], null, ['timeout' => 10]);
        $status = is_array($h) ? ArrRecords::s($h, 'status') : '';
        return $status === 'OK' ? [] : [['source' => 'Jackett', 'type' => 'error', 'message' => 'health answered: '
            . ArrRecords::cut($status !== '' ? $status : 'no status', 100), 'wiki' => '']];
    }

    /** What tells a restarted Jackett from the old one once /health answers: Docker's container start time; null before. */
    private static function started(callable $live)
    {
        try {
            $cl = call_user_func($live);
            $cl->call('GET', 'health', [], null, ['timeout' => 5]);
        } catch (CliError $e) {
            return null;
        }
        $w = $cl->where();
        if ($w['source'] !== 'docker') {
            return ['at' => 0, 'id' => 'answering']; // ArrRestart refuses outside Docker before this matters
        }
        list($code, $out) = ArrShell::run(['docker', 'inspect', '-f', '{{.State.StartedAt}}', 'jackett'],
            ['DOCKER_HOST' => 'unix://' . $w['docker']]);
        $t = $code === 0 ? strtotime(trim($out)) : false;
        return $t === false ? null : ['at' => $t, 'id' => (string) $t];
    }
}
