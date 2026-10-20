<?php
/**
 * ServicesRemote: `remote <nick|--all|--tag T> <category/tool> <verb> [args] [-- tool options]` (ADR-0084). Runs one
 * whitelisted tool verb on each service through its forced command (serve.php), several at once (Services::fanout), and
 * prints that tool's own records with a `service` field, so remote figures read like local ones. The words are composed
 * here and checked twice (here and by serve); there is no remote shell. A verb that changes something goes to one
 * service at a time and needs --intent with the user's own words, recorded in local/state/intents.jsonl.
 */
class ServicesRemote
{
    public static function run(array $args, array $opt)
    {
        $many = !empty($opt['all']) || isset($opt['tag']);
        if (count($args) < ($many ? 2 : 3)) {
            throw new CliError('remote takes <nick> <category/tool> <verb> [args], or --all / --tag T before <category/tool>',
                'e.g. remote hdd1 torrent-clients/rtorrent list -- --limit 5; remote --tag seedbox system/system-status account');
        }
        $nicks = $many ? self::targets($opt) : [Services::nick(array_shift($args))];
        $unit = (string) array_shift($args);
        $verb = (string) array_shift($args);
        if ($verb === 'export') {
            throw new CliError('export is not run through remote: it prints the whole .torrent, tracker passkey included, which would reach the model',
                'moves fetch the .torrent themselves (move tool); compare torrents with show --files --trackers');
        }
        if (!ServeGate::allowed($unit, $verb)) {
            throw new CliError("$unit $verb is not something another box's serve allows", 'allowed: ' . json_encode(ServeGate::TOOLS));
        }
        $stdin = null;
        if (in_array($verb, ServeGate::CHANGING, true)) {
            if ($many || count($nicks) !== 1) {
                throw new CliError("$verb changes something: name one service, not --all or --tag", 'run it once per service');
            }
            self::intent($opt, $nicks[0], $unit, $verb);
        }
        if ($verb === 'add') {
            list($stdin, $args) = self::torrent($args);
        }
        $words = array_merge(['tool', $unit, $verb], $args);
        foreach ($words as $w) {
            if (!preg_match(Services::TOKEN_RE, (string) $w)) {
                throw new CliError('a word holds a character another box\'s serve refuses: ' . json_encode($w) . '; nothing was sent',
                    'only letters, digits and . _ : / @ % + = , ~ - pass: give a torrent by its id, not a name with spaces');
            }
        }
        $results = Services::fanout($nicks, function ($nick) use ($words, $stdin) {
            $svc = Services::get($nick);
            if ($svc['transport'] !== 'pikkumies') {
                throw new CliError("service $nick is transport {$svc['transport']}: no Pikkumies serve there", 'remote needs Pikkumies on that box (transport pikkumies)');
            }
            return Services::jsonl($nick, $words, $stdin);
        }, 4, 300);
        $records = [];
        $failed = [];
        foreach ($results as $nick => $recs) {
            foreach ((array) $recs as $r) {
                if (!is_array($r)) {
                    continue;
                }
                if (array_key_exists('ok', $r)) {
                    if ($r['ok'] !== true) {
                        $failed[] = $nick;
                        $records[] = ['service' => $nick, 'ok' => false, 'error' => isset($r['error']) ? $r['error'] : 'failed', 'hint' => isset($r['hint']) ? $r['hint'] : ''];
                    }
                    continue;
                }
                $records[] = ['service' => $nick] + $r;
            }
        }
        return ['records' => $records, 'status' => ['services' => array_keys($results), 'failed' => $failed]];
    }

    private static function targets(array $opt)
    {
        $out = [];
        foreach (Services::all() as $s) {
            if (!empty($opt['all']) || in_array((string) $opt['tag'], isset($s['tags']) ? (array) $s['tags'] : [], true)) {
                $out[] = $s['nick'];
            }
        }
        if (!$out) {
            throw new CliError('no service matches' . (isset($opt['tag']) ? " tag {$opt['tag']}" : ''), '`services list` shows the services and their tags');
        }
        return $out;
    }

    private static function intent(array $opt, $nick, $unit, $verb)
    {
        $intent = isset($opt['intent']) && is_string($opt['intent']) ? trim(preg_replace('/\s+/', ' ', Cli::cleanString($opt['intent']))) : '';
        if (strlen($intent) < 8) {
            throw new CliError("$verb on $nick changes that box: --intent \"<the user's own words>\" is needed (at least 8 characters)", 'quote what the user asked for; it is recorded with the call');
        }
        $dir = Services::localDir() . '/state';
        @mkdir($dir, 0700, true);
        $line = json_encode(['ts' => gmdate('c'), 'source' => 'services remote', 'service' => $nick, 'tool' => $unit, 'verb' => $verb,
            'intent' => substr($intent, 0, 500)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $old = umask(0077);
        $ok = @file_put_contents("$dir/intents.jsonl", $line, FILE_APPEND | LOCK_EX) === strlen($line);
        umask($old);
        if (!$ok) {
            throw new CliError('could not record the intent; nothing was sent', 'check that pikkumies/local is writable');
        }
    }

    /** add: the first argument is a local .torrent (inside the home), sent on stdin as `add -`. */
    private static function torrent(array $args)
    {
        $file = isset($args[0]) ? (string) $args[0] : '';
        $real = $file !== '' ? realpath($file) : false;
        if ($real === false || !is_file($real) || !ServeGate::inside($real, HomePath::home()) || filesize($real) > 16777216) {
            throw new CliError('remote add takes a local .torrent file inside your home (at most 16 MiB)', 'magnets and links are not sent to another box; download the .torrent first');
        }
        $data = (string) file_get_contents($real);
        if (substr($data, 0, 1) !== 'd' || strpos($data, '4:info') === false) {
            throw new CliError("$file is not a .torrent file", 'pass the .torrent itself');
        }
        $args[0] = '-';
        return [$data, $args];
    }
}
