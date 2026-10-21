<?php
/**
 * ServicesRemote: `remote <nick|--all|--tag T> <category/tool> <verb> [args] [-- tool options]` (ADR-0084). Runs one
 * whitelisted tool verb on each service through its forced command (serve.php), several at once (Services::fanout), and
 * prints that tool's own records with a `service` field, so remote figures read like local ones. The words are composed
 * here and checked twice (here and by serve); there is no remote shell. A verb that changes something goes to one
 * service at a time and needs --intent with the user's own words, recorded in local/state/intents.jsonl.
 *
 * transport tunnel (a box without Pikkumies, ADR-0105): there is no serve, so the read verbs of torrent-clients/rtorrent
 * (list, show, stats, traffic) run here, in this process, against that box's own rTorrent RPC over HTTPS (clients.rtorrent:
 * url, user, auth = secret:<handle>, through RtorrentHttpRpc), and system/system-status account reads Bytesized's read-only
 * accounts API (account_auth). The records are the local ones (RtorrentClient's), with `service`. A verb that changes
 * something is refused for such a box before anything is recorded or sent. transport shell stays refused.
 */
class ServicesRemote
{
    /** What a tunnel service answers here: unit => read verbs (ADR-0105). */
    const TUNNEL_READS = ['torrent-clients/rtorrent' => ['list', 'show', 'stats', 'traffic'], 'system/system-status' => ['account']];
    /** Options a tunnel read takes after `--` (the ones serve allows for these verbs; --files is refused with its reason). */
    const TUNNEL_OPTIONS = ['state', 'search', 'sort', 'reverse', 'limit', 'offset', 'fields', 'trackers', 'files'];
    /** Bytesized's read-only accounts API (bytesized-hosting.com/pages/api): the key goes as ?api_key=, nothing else is sent. */
    const BYTESIZED_HOST = 'bytesized-hosting.com';
    const BYTESIZED_PATH = '/api/v1/accounts.json';
    /** The documented fields kept; login_password (the slot's login, also in that reply) never leaves this class. */
    const BYTESIZED_FIELDS = ['server_name', 'paid_till', 'bandwidth_quota', 'total_bandwidth', 'pretty_bw_quota', 'disk_quota', 'total_storage',
        'pretty_disk_quota', 'disk_quota_percentage', 'memory_usage'];

    /** Test seam: fn($method, $url, $options) with Http::request's return shape, used for every tunnel request. */
    public static $requester = null;

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
            $svc = Services::get($nicks[0]);
            if ($svc['transport'] === 'tunnel') { // refused before the intent is recorded or anything is sent
                throw new CliError("$verb on {$nicks[0]} is refused: it is transport tunnel, a box without Pikkumies, and there is no management API"
                    . ' there; the provider\'s own panel or the user\'s own SSH session does this; nothing was sent',
                    'report it as not done, with this reason; never turn it into an ssh command of your own (ADR-0105)');
            }
            if ($svc['transport'] !== 'pikkumies') {
                throw new CliError("service {$nicks[0]} is transport {$svc['transport']}: no Pikkumies serve there", 'remote needs Pikkumies on that box (transport pikkumies)');
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
        $results = Services::fanout($nicks, function ($nick) use ($words, $stdin, $unit, $verb, $args) {
            $svc = Services::get($nick);
            if ($svc['transport'] === 'tunnel') {
                return self::tunnel($svc, $unit, $verb, $args);
            }
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

    /**
     * One tunnel service's records for a read verb, each with `service`, computed here from that box's own RPC: the same
     * RtorrentClient methods as the local unit, never Torrents::run, whose `stats` adds this machine's quota and telemetry and
     * whose `show --files` reads this machine's disk. A missing configuration or any failure is one error for this service.
     */
    private static function tunnel(array $svc, $unit, $verb, array $args)
    {
        $nick = $svc['nick'];
        if (!isset(self::TUNNEL_READS[$unit]) || !in_array($verb, self::TUNNEL_READS[$unit], true)) {
            throw new CliError("$unit $verb is not available for $nick (transport tunnel): a box without Pikkumies answers here only rtorrent list, show,"
                . ' stats and traffic through its own RPC, and system-status account at Bytesized', 'qBittorrent and Deluge through their own APIs are a later step (ADR-0105)');
        }
        Services::refuseListed($svc['host']);
        if ($unit === 'system/system-status') {
            return self::bytesized($svc);
        }
        $conf = isset($svc['clients']['rtorrent']) && is_array($svc['clients']['rtorrent']) ? $svc['clients']['rtorrent'] : [];
        if (!isset($conf['url']) || !is_string($conf['url']) || $conf['url'] === '') {
            throw new CliError("$nick: rtorrent's RPC is not configured (no clients.rtorrent.url); nothing was sent",
                "services edit $nick --clients '{\"rtorrent\":{\"url\":\"https://<host>/<path>\",\"user\":\"<login name>\",\"auth\":\"secret:<handle>\"}}' with the address the provider documents");
        }
        list(, $ids, $opt) = Cli::parse(array_merge(['remote', $verb], $args), [$verb], ['files', 'trackers', 'reverse']);
        $bad = array_diff(array_keys($opt), self::TUNNEL_OPTIONS, Cli::GLOBAL_FLAGS);
        if ($bad) {
            throw new CliError("option --" . implode(', --', $bad) . " is not available for $nick (transport tunnel); nothing was sent", 'options: --' . implode(', --', self::TUNNEL_OPTIONS));
        }
        if (!empty($opt['files'])) {
            throw new CliError("show --files is not available for $nick (transport tunnel): it reads the box's own disk to plan a delete, and a tunnel reaches only"
                . ' the client\'s RPC; nothing was sent', 'show <id> --trackers works there');
        }
        require_once dirname(__DIR__, 2) . '/torrent-clients/lib/Torrents.php';
        require_once dirname(__DIR__, 2) . '/torrent-clients/rtorrent/RtorrentClient.php';
        require_once dirname(__DIR__, 2) . '/torrent-clients/rtorrent/HttpRpc.php';
        $user = isset($conf['user']) && is_string($conf['user']) && $conf['user'] !== '' ? $conf['user'] : $svc['user'];
        $auth = isset($conf['auth']) && is_string($conf['auth']) && $conf['auth'] !== '' ? $conf['auth'] : null;
        $c = new RtorrentClient('remote', '', 0, new RtorrentHttpRpc($conf['url'], $svc['host'], $user, $auth, 15, self::$requester));
        if ($verb === 'list') { // --limit and --offset as the local list pages (Cli::emit): --limit 0 is no limit
            $rows = TorrentList::run($c->torrents(), $opt);
            $off = isset($opt['offset']) && ctype_digit((string) $opt['offset']) ? (int) $opt['offset'] : 0;
            $lim = isset($opt['limit']) && ctype_digit((string) $opt['limit']) && (int) $opt['limit'] > 0 ? (int) $opt['limit'] : null;
            $rows = array_slice($rows, $off, $lim);
        } elseif ($verb === 'show') { // as Torrents::run's show, without --files
            $targets = Torrents::resolve($c->torrents(), $ids, 8, 'not found');
            $rows = [];
            foreach ($targets as $t) {
                $rows[] = Torrents::printable(array_replace($t, $c->details($t['native']) + $t));
            }
            if (!empty($opt['trackers'])) {
                $rows = array_merge($rows, TorrentTrackers::records($c, $targets));
            }
        } else {
            $rows = [$verb === 'stats' ? $c->stats() : $c->traffic()]; // stats: the client's own figures; no quota or telemetry of this machine
        }
        $fields = isset($opt['fields']) && is_string($opt['fields']) ? array_flip(explode(',', $opt['fields'])) : null; // as Cli::emit, service kept
        return array_map(function ($r) use ($nick, $fields) {
            return ['service' => $nick] + ($fields !== null ? array_intersect_key($r, $fields) : $r);
        }, $rows);
    }

    /**
     * system-status account for a tunnel service: Bytesized's read-only accounts API with the key from the store (account_auth,
     * pinned to bytesized-hosting.com), read at request time and scrubbed from any error; one record per account with the
     * documented fields, login_password dropped. One key answers for all the user's Bytesized accounts, so only those whose
     * server_name is a label of this service's host are kept. Any other provider publishes no such API: refused with that reason.
     */
    private static function bytesized(array $svc)
    {
        $nick = $svc['nick'];
        if ($svc['provider'] !== 'bytesized') {
            throw new CliError("account is not available for $nick (provider {$svc['provider']}, transport tunnel): no customer account API is published there;"
                . ' only Bytesized has a read-only one', 'the provider\'s own web panel shows the account; report this item as not available');
        }
        $ref = isset($svc['account_auth']) && is_string($svc['account_auth']) ? $svc['account_auth'] : '';
        if ($ref === '') {
            throw new CliError("$nick: Bytesized's accounts API is not configured (no account_auth); nothing was sent",
                "the user stores the API key in their own terminal: secrets set $nick-api --kind apikey --host " . self::BYTESIZED_HOST . "/api/v1/; then services edit $nick --account-auth secret:$nick-api");
        }
        $h = Secrets::handle(Services::secretRef($ref, 'account_auth')); // never echoes a raw value
        Services::refuseListed(self::BYTESIZED_HOST); // the host actually contacted
        $pin = Secrets::pin($h);
        if ($pin === null) {
            throw new CliError("secret $h is not pinned to a host; nothing was sent", "if $h is the user's Bytesized API key, they pin it in their own terminal:"
                . " secrets rotate $h --host " . self::BYTESIZED_HOST . '/api/v1/ (a pin changes only together with a new value)');
        }
        if (!Secrets::pinAllows($pin, self::BYTESIZED_HOST, self::BYTESIZED_PATH)) {
            // No re-pin command: account_auth is agent-writable, and a key pinned elsewhere belongs to another box, not to Bytesized.
            throw new CliError("secret $h is pinned to " . Secrets::pinText($pin) . ', not ' . self::BYTESIZED_HOST . '; nothing was sent',
                "account_auth names a secret the user pinned to another address, so it is not the Bytesized API key: check `services show $nick`");
        }
        $key = Secrets::get($h);
        $hide = [$key, rawurlencode($key)];
        $r = call_user_func(self::$requester !== null ? self::$requester : ['Http', 'request'], 'GET', 'https://' . self::BYTESIZED_HOST . self::BYTESIZED_PATH . '?api_key=' . rawurlencode($key),
            ['timeout' => 15, 'headers' => ['Accept: application/json']]);
        $key = null;
        $code = is_array($r) && isset($r['code']) ? (int) $r['code'] : 0;
        if ($code !== 200) {
            $why = $code === 0 ? 'no answer: ' . str_replace($hide, '[REDACTED]', is_array($r) && isset($r['error']) ? (string) $r['error'] : '') : "HTTP $code";
            throw new CliError("Bytesized's accounts API gave $nick $why", $code === 401 || $code === 403 ? 'the key may be wrong or revoked: the user replaces it with `secrets rotate ' . $h . '` in their own terminal' : 'try again later');
        }
        $d = json_decode((string) $r['body'], true);
        // The page lists the attributes, not the envelope: a list of accounts and a single object are both read.
        $rows = is_array($d) && $d && array_keys($d) === range(0, count($d) - 1) ? $d : [$d];
        $labels = explode('.', (string) SshLists::host($svc['host'])); // e.g. user.priapus.bysh.me: the server is one label
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_intersect_key($row, array_flip(self::BYTESIZED_FIELDS))) {
                continue; // not an account (an error object, say)
            }
            $server = isset($row['server_name']) && is_string($row['server_name']) ? strtolower($row['server_name']) : '';
            $seen[] = preg_match('/^[a-z0-9-]{1,63}\z/', $server) === 1 ? $server : '(unreadable)'; // provider text: names only
            if ($server === '' || !in_array($server, $labels, true)) {
                continue; // another of the user's Bytesized accounts: its own service shows it
            }
            $rec = ['service' => $nick, 'provider' => 'bytesized'];
            foreach (self::BYTESIZED_FIELDS as $k) {
                $rec[$k] = isset($row[$k]) && is_scalar($row[$k]) ? $row[$k] : null;
            }
            $out[] = $rec;
        }
        if (!$out) {
            throw new CliError("Bytesized's accounts API gave no account on this service's server (" . $svc['host'] . ')',
                $seen ? 'the key\'s accounts are on: ' . implode(', ', array_unique($seen)) . '; the service host must name that server (e.g. <user>.<server>.bysh.me)' : 'try again later; the API may have changed');
        }
        return $out;
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
