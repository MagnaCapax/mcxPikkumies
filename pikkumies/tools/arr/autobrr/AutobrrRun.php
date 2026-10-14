<?php
/**
 * AutobrrRun: autobrr's verb table: the category verbs (AutobrrVerbs), `toggle` (PUT filters/{id}/enabled,
 * internal/http/filter.go:60,268-295) and `login` (the user's own API key, typed at a terminal). Restart: the PMSS
 * panel's way (`--stop-app=autobrr`, `--start-app=autobrr`: install-media-stack.sh:258-263) or the user's Docker
 * container; autobrr reports no start time, so a restart counts when a new process listens on the port (a new
 * socket inode in /proc/net/tcp) and answers.
 */
class AutobrrRun
{
    public static function run(array $argv, $usage, AutobrrSecret $secret, callable $prompt)
    {
        $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $home = ArrLocate::home($uid);
        $locate = function ($lenient = false) use ($home, $family, $uid, $secret) {
            if ($home === '' || !is_dir($home)) {
                throw new CliError('no home folder of this user found (HOME, or the passwd entry)', 'run the tool as the seedbox user');
            }
            $cfg = Config::load();
            $over = isset($cfg['arr']['autobrr']) && is_array($cfg['arr']['autobrr']) ? $cfg['arr']['autobrr'] : [];
            return AutobrrConfig::find($home, $family, $uid, $secret, $over, $lenient);
        };
        $t = (int) getenv('PIKKUMIES_ARR_TIMEOUT');
        $timeout = $t > 0 && $t <= 60 ? $t : 15;
        $fresh = function () use ($locate, $timeout) {
            return new ArrClient($locate(), $locate, '', $timeout);
        };
        $client = null;
        $c = function () use (&$client, $fresh) {
            return $client !== null ? $client : ($client = call_user_func($fresh));
        };
        $verbs = [
            'list' => function ($args, $opt) use ($c) {
                $s = isset($opt['search']) && is_string($opt['search']) ? strtolower($opt['search']) : null;
                return array_values(array_filter(array_map(['AutobrrVerbs', 'filter'], self::filters($c())), function ($f) use ($s) {
                    return $s === null || strpos(strtolower($f['name']), $s) !== false;
                }));
            },
            'show' => function ($args, $opt) use ($c) {
                return array_map(function ($id) use ($c) {
                    return AutobrrVerbs::filterFull((array) $c()->call('GET', "filters/$id", [], null, ['missing' => "no filter $id"]));
                }, self::resolve($c(), $args));
            },
            'stats' => function ($args, $opt) use ($c) {
                return [AutobrrVerbs::stats($c())];
            },
            'health' => function ($args, $opt) use ($c) {
                return AutobrrVerbs::health($c());
            },
            'history' => function ($args, $opt) use ($c) {
                list($rows, $note, $total) = ArrPages::get($c(), 'release', ['offset', 'limit', 'data', 'count'], [], $opt);
                return ['records' => array_map(['AutobrrVerbs', 'release'], $rows), 'status' => ['releases' => $total] + $note];
            },
            'restart' => function ($args, $opt) use ($locate, $timeout) {
                $where = $locate(true);
                $live = function () use ($locate, $timeout) { // healthz/liveness needs no key: only the key may be missing
                    return new ArrClient($locate('key'), function () use ($locate) {
                        return $locate('key');
                    }, '', $timeout);
                };
                return [ArrRestart::run(AutobrrConfig::APP, $where, $live, function () use ($where, $live) {
                    return self::started($where, $live);
                })];
            },
            'toggle' => function ($args, $opt) use ($c) {
                return AutobrrChange::toggle($c(), $args, $opt);
            },
            'login' => function ($args, $opt) use ($locate, $secret, $prompt, $timeout) {
                return AutobrrChange::login($locate, $secret, $prompt, $timeout);
            },
        ];
        Cli::run($argv, $verbs, $usage, ['on', 'off'], ['search']);
    }

    private static function filters(ArrClient $c)
    {
        return array_values(array_filter((array) $c->call('GET', 'filters', [], null, ['max' => ArrClient::LIST_MAX]), 'is_array'));
    }

    /** Filter ids as `list` prints them, each checked against the live list; one unknown id fails the call. */
    public static function resolve(ArrClient $c, array $args)
    {
        if (!$args) {
            throw new CliError('name at least one filter id', 'ids are the `id` values `list` prints');
        }
        $have = array_flip(array_map('intval', array_column(self::filters($c), 'id')));
        $bad = array_filter(array_unique($args), function ($a) use ($have) {
            return !ctype_digit((string) $a) || !isset($have[(int) $a]);
        });
        if ($bad) {
            throw new CliError('nothing done: no filter ' . implode(', ', $bad), 'ids are the `id` values `list` prints');
        }
        return array_values(array_map('intval', array_unique($args)));
    }

    /**
     * What tells a restarted autobrr from the old one, once it answers: Docker's container start time, else the
     * listening socket (a new process opens a new one). null while it does not answer. $live: fn() => a client that
     * may have no key (healthz needs none, server.go:226).
     */
    public static function started(array $where, callable $live)
    {
        try {
            $cl = call_user_func($live);
            $cl->call('GET', 'healthz/liveness', [], null, ['timeout' => 5, 'text' => true]);
        } catch (CliError $e) {
            return null;
        }
        if ($where['source'] === 'docker') {
            list($code, $out) = ArrShell::run(['docker', 'inspect', '-f', '{{.State.StartedAt}}', 'autobrr'],
                ['DOCKER_HOST' => 'unix://' . $where['docker']]);
            $t = $code === 0 ? strtotime(trim($out)) : false;
            return $t === false ? null : ['at' => $t, 'id' => (string) $t];
        }
        $w = $cl->where();
        $inode = self::listener($w['port'], $w['uid']);
        return $inode === '' ? null : ['at' => 0, 'id' => $inode];
    }

    /** The inode of the socket listening on $port for $uid (/proc/net/tcp, tcp6 field 10), '' when none. */
    public static function listener($port, $uid, ?array $tables = null)
    {
        $tables = $tables !== null ? $tables : [@file_get_contents('/proc/net/tcp'), @file_get_contents('/proc/net/tcp6')];
        foreach ($tables as $table) {
            foreach (explode("\n", (string) $table) as $line) {
                $f = preg_split('/\s+/', trim($line));
                if (count($f) >= 10 && $f[3] === '0A' && strtoupper(substr($f[1], -5)) === sprintf(':%04X', $port)
                    && (int) $f[7] === (int) $uid) {
                    return $f[9];
                }
            }
        }
        return '';
    }
}
