<?php
/**
 * Services: the person's other machines (seedboxes, backup boxes, workstations) and how Pikkumies reaches them
 * (ADR-0084). The list is local/state/services.jsonl, one service per line; each service's key, public key and pinned
 * host key live in local/secrets/services/<nick>/ (0700/0600, inside the secret fence like backup remote's, ADR-0049).
 * Every ssh call is an argv (never a shell string built from text): -F /dev/null, the service's own known_hosts with
 * StrictHostKeyChecking=yes, its own key only, `--` before a host checked against a strict pattern, and the person's
 * ssh.deny (and ssh.blockUnknown with ssh.allow) from lib/SshLists.php applied before any connection (ADR-0068): a
 * program connecting by itself is out of the ssh-connect hook's sight, so the lists are checked here.
 */
require_once __DIR__ . '/Cli.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/SshLists.php';
require_once __DIR__ . '/ToolProc.php';
require_once __DIR__ . '/PathName.php';

class Services
{
    const PROVIDERS = ['pmss', 'feral', 'whatbox', 'bytesized', 'ultra', 'seedhost', 'generic'];
    const TRANSPORTS = ['pikkumies', 'tunnel', 'shell'];
    const CLIENTS = ['rtorrent', 'qbittorrent', 'deluge', 'transmission'];
    const NICK_RE = '/^[a-z0-9][a-z0-9_-]{0,31}\z/';
    const HOST_RE = '/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}\z/';
    const USER_RE = '/^[a-z_][a-z0-9_.-]{0,31}\z/';
    /** One remote command word for the forced command (serve.php tokenizes the same way): no quote, space or shell character. */
    const TOKEN_RE = '/^[A-Za-z0-9._:\/@%+=,~-]{1,512}\z/';

    /** Test seams: the local directory and the settings file read for ssh.deny/ssh.allow. */
    public static $localDir = null;
    public static $configFile = null;

    public static function localDir()
    {
        return rtrim(self::$localDir !== null ? self::$localDir : Config::localDir(), '/');
    }

    public static function path()
    {
        return self::localDir() . '/state/services.jsonl';
    }

    public static function secretDir($nick)
    {
        return self::localDir() . '/secrets/services/' . self::nick($nick);
    }

    /** local/state/tmp (0700): temporary files of these tools, never /tmp. */
    public static function tmpDir()
    {
        $d = self::localDir() . '/state/tmp';
        if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
            throw new CliError("cannot create $d", 'check that pikkumies/local is writable by this account');
        }
        @chmod($d, 0700);
        return $d;
    }

    public static function all()
    {
        $out = [];
        foreach (is_file(self::path()) ? (array) file(self::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $s = json_decode($line, true);
            if (is_array($s) && isset($s['nick']) && is_string($s['nick']) && preg_match(self::NICK_RE, $s['nick'])) {
                $out[$s['nick']] = $s;
            }
        }
        return $out;
    }

    /** The service, or a CliError naming `services list` ($required false: null instead). */
    public static function get($nick, $required = true)
    {
        $all = self::all();
        if (isset($all[(string) $nick])) {
            return $all[(string) $nick];
        }
        if (!$required) {
            return null;
        }
        throw new CliError("no service named $nick", '`services list` shows the names; `services add <nick> --host H --user U` adds one');
    }

    /** Adds or replaces one service (validated), rewriting the list atomically under a lock. Returns the stored record. */
    public static function put(array $svc)
    {
        $svc = self::normalize($svc);
        self::rewrite(function (array $all) use ($svc) {
            $all[$svc['nick']] = $svc;
            return $all;
        });
        return $svc;
    }

    public static function remove($nick)
    {
        $nick = self::nick($nick);
        self::get($nick);
        self::rewrite(function (array $all) use ($nick) {
            unset($all[$nick]);
            return $all;
        });
    }

    private static function rewrite(callable $change)
    {
        $file = self::path();
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
            throw new CliError('cannot create ' . dirname($file), 'check that pikkumies/local is writable by this account');
        }
        $lock = fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new CliError("cannot lock $file", 'another services command may be running; try again');
        }
        try {
            $text = '';
            foreach (call_user_func($change, self::all()) as $s) {
                $text .= json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
            $tmp = $file . '.tmp' . getmypid();
            $old = umask(0077);
            $ok = @file_put_contents($tmp, $text) === strlen($text) && @rename($tmp, $file);
            umask($old);
            if (!$ok) {
                @unlink($tmp);
                throw new CliError("cannot write $file", 'check the disk space and that pikkumies/local is writable');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function nick($n)
    {
        if (!is_string($n) || !preg_match(self::NICK_RE, $n)) {
            throw new CliError('not a service name: ' . (is_string($n) ? $n : gettype($n)), 'lowercase letters, digits, _ and -, at most 32, e.g. hdd1');
        }
        return $n;
    }

    public static function host($h)
    {
        $h = (string) $h;
        if (!preg_match(self::HOST_RE, $h) || SshLists::host($h) === null) {
            throw new CliError("not a host name: $h", 'a name like box.example.com or a dotted IPv4 address; never an option');
        }
        return $h;
    }

    public static function user($u)
    {
        $u = (string) $u;
        if (!preg_match(self::USER_RE, $u)) {
            throw new CliError("not an account name: $u", 'the login name on that machine, e.g. xyzjohn');
        }
        return $u;
    }

    /** A whole, checked service record; unknown keys are dropped. */
    public static function normalize(array $s)
    {
        $tags = [];
        foreach (isset($s['tags']) ? (array) $s['tags'] : [] as $t) {
            // A list written before ADR-0104 can hold an all-digit tag as a number (1080, not "1080"): read it as the
            // string it was typed as, so `services edit` keeps working on such a service.
            $t = is_int($t) ? (string) $t : $t;
            if (!is_string($t) || !preg_match(self::NICK_RE, $t)) {
                throw new CliError('not a tag: ' . json_encode($t), 'lowercase words such as seedbox, backup, webhosting, dedicated, workstation, nas, laptop, media, other');
            }
            $tags[$t] = true;
        }
        $pick = function ($key, array $allowed, $default) use ($s) {
            $v = isset($s[$key]) ? $s[$key] : $default;
            if (!in_array($v, $allowed, true)) {
                throw new CliError("--$key " . json_encode($v) . ' is not one of ' . implode(', ', $allowed), "use one of: " . implode(', ', $allowed));
            }
            return $v;
        };
        $port = isset($s['port']) ? $s['port'] : 22;
        if (!preg_match('/^[0-9]{1,5}\z/', (string) $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new CliError("port $port is not valid", 'a whole number from 1 to 65535');
        }
        $clients = [];
        foreach (isset($s['clients']) && is_array($s['clients']) ? $s['clients'] : [] as $c => $conf) {
            if (!in_array($c, self::CLIENTS, true) || !is_array($conf)) {
                throw new CliError("unknown client $c", 'clients: ' . implode(', ', self::CLIENTS));
            }
            $clients[$c] = array_filter($conf, function ($v) {
                return is_scalar($v) || $v === null;
            });
        }
        $notes = isset($s['notes']) ? Cli::cleanString((string) $s['notes']) : '';
        $home = isset($s['home']) ? (string) $s['home'] : '';
        if ($home !== '' && !preg_match('#^/[A-Za-z0-9._/-]{0,255}\z#', $home)) {
            throw new CliError("not a home directory: $home", 'an absolute path such as /home/xyzjohn');
        }
        // array_map('strval', ...): an all-digit tag (e.g. "1080") is a valid array key, but PHP stores it as the
        // integer 1080, not the string "1080" — written back out, a later `services edit --remove-tag 1080` (a
        // string from argv) would then fail to match it with "not a tag" (ADR-0104 review, M1-class bug).
        $out = ['nick' => self::nick(isset($s['nick']) ? $s['nick'] : null), 'tags' => array_map('strval', array_keys($tags)),
            'provider' => $pick('provider', self::PROVIDERS, 'generic'), 'host' => self::host(isset($s['host']) ? $s['host'] : ''),
            'port' => (int) $port, 'user' => self::user(isset($s['user']) ? $s['user'] : ''), 'home' => $home,
            'transport' => $pick('transport', self::TRANSPORTS, 'pikkumies'), 'clients' => $clients,
            'allow_delete' => !empty($s['allow_delete']), 'notes' => substr($notes, 0, 2000),
            'added_at' => isset($s['added_at']) && is_string($s['added_at']) ? $s['added_at'] : gmdate('c')];
        foreach (['host_key', 'host_key_alg', 'setup_at'] as $k) {
            if (isset($s[$k]) && is_scalar($s[$k])) {
                $out[$k] = $s[$k];
            }
        }
        return $out;
    }

    /** Refuses a host on ssh.deny, and with ssh.blockUnknown one not on ssh.allow (ADR-0068). */
    public static function refuseListed($host)
    {
        $config = SshLists::config(self::$configFile !== null ? self::$configFile : self::localDir() . '/config.php');
        $name = SshLists::host($host);
        $decision = $name === null ? SshLists::DENY : SshLists::decide($config, [$name]);
        if ($decision === SshLists::DENY) {
            throw new CliError("$host is on the SSH deny list (ssh.deny) or cannot be checked against it; nothing was sent",
                'only the person removes a host from that list, by hand in local/config.php (docs/ssh.md)');
        }
        if ($decision !== SshLists::ALLOW && SshLists::blockUnknown($config)) {
            throw new CliError("$host is not on the SSH allow list and ssh.blockUnknown is on; nothing was sent", 'ssh.allow is the person\'s own setting (docs/ssh.md): list this step as skipped, with this reason, in your answer');
        }
    }

    /** The options every call to $svc shares, with the given known_hosts and checking mode. */
    public static function baseArgv(array $svc, $knownHosts, $strict)
    {
        // One plain word, no quotes: ssh splits UserKnownHostsFile on whitespace and expands % and ~ in it, and callers that
        // hand this argv to rsync -e (move) refuse quoted words. So a path with any of these is refused instead.
        if (strpbrk($knownHosts, "\"' \t\n\r%~\\") !== false) {
            throw new CliError('the pikkumies directory path holds a space, a quote, %, ~, a backslash or a line break', 'ssh cannot take it as one plain option value; install Pikkumies under a plain path');
        }
        $alg = isset($svc['host_key_alg']) && in_array($svc['host_key_alg'], ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'rsa-sha2-512'], true)
            ? ['-o', 'HostKeyAlgorithms=' . $svc['host_key_alg']] : [];
        return array_merge([ToolProc::need('ssh', 'install openssh-client'), '-F', '/dev/null', '-p', (string) (int) $svc['port'],
            '-l', self::user($svc['user']), '-o', 'UserKnownHostsFile=' . $knownHosts, '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'StrictHostKeyChecking=' . $strict, '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=4'], $alg);
    }

    /**
     * argv for one call to service $nick with its own key. Transport pikkumies: every word must pass TOKEN_RE (serve.php
     * refuses anything else). shell: each word is single-quoted for the remote shell, so it stays data. tunnel: no remote
     * command; $opt['tunnel'] = ['local' => port|path, 'remote' => port|path] adds -N -L. $opt['suffix']: setup's ".new" files.
     */
    public static function sshArgv($nick, array $remoteArgv, array $opt = [])
    {
        $svc = isset($opt['service']) ? $opt['service'] : self::get($nick);
        self::host($svc['host']);
        self::refuseListed($svc['host']);
        $dir = self::secretDir($svc['nick']);
        $suffix = isset($opt['suffix']) ? (string) $opt['suffix'] : '';
        if (!is_file("$dir/key$suffix") || !is_file("$dir/known_hosts$suffix")) {
            throw new CliError("service {$svc['nick']} is not set up (no key or pinned host key)", "run `services setup {$svc['nick']}` (or `pubkey` + `accept` + `test`)");
        }
        $argv = array_merge(self::baseArgv($svc, "$dir/known_hosts$suffix", 'yes'), ['-i', "$dir/key$suffix", '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes', '-o', 'PreferredAuthentications=publickey']);
        if (isset($opt['tunnel'])) {
            if ($svc['transport'] !== 'tunnel' || $remoteArgv) {
                throw new CliError("a tunnel needs transport tunnel and no remote command (service {$svc['nick']} is {$svc['transport']})", 'set --transport tunnel with `services edit`');
            }
            $ends = [];
            foreach (['local', 'remote'] as $k) {
                $v = isset($opt['tunnel'][$k]) ? (string) $opt['tunnel'][$k] : '';
                if (!preg_match('#^([0-9]{1,5}|/[A-Za-z0-9._/-]{1,200})\z#', $v)) {
                    throw new CliError("tunnel $k end must be a port or an absolute socket path: $v", 'e.g. local 127.0.0.1 port 15000, remote /home/u/.rtorrent.socket');
                }
                $ends[$k] = ctype_digit($v) ? "127.0.0.1:$v" : $v;
            }
            return array_merge($argv, ['-N', '-o', 'ExitOnForwardFailure=yes', '-L', $ends['local'] . ':' . $ends['remote'], '--', $svc['host']]);
        }
        if ($svc['transport'] === 'tunnel') {
            throw new CliError("service {$svc['nick']} is a tunnel: it runs no commands", 'use a tunnel, or a service with transport pikkumies');
        }
        $words = [];
        foreach (array_values($remoteArgv) as $w) {
            $w = (string) $w;
            if (strpos($w, "\0") !== false || ($svc['transport'] === 'pikkumies' && !preg_match(self::TOKEN_RE, $w))) {
                throw new CliError('a remote word holds a character the forced command refuses: ' . json_encode($w), 'only letters, digits and . _ : / @ % + = , - pass; names with spaces go as ids');
            }
            $words[] = $svc['transport'] === 'shell' ? "'" . str_replace("'", "'\\''", $w) . "'" : $w;
        }
        return array_merge($argv, ['--', $svc['host']], $words);
    }

    /** One call: ['rc', 'stdout', 'stderr', 'timed_out']. */
    public static function run($nick, array $remoteArgv, $stdin = null, $timeout = 120)
    {
        return self::exec(self::sshArgv($nick, $remoteArgv), $stdin, $timeout);
    }

    /**
     * A remote tool's JSON Lines, each record with "service" => $nick; the remote status line is kept as the last record.
     * A connection that answers no JSON gives one {"ok": false} record with ssh's error tail.
     */
    public static function jsonl($nick, array $remoteArgv, $stdin = null, $timeout = 120)
    {
        $r = self::run($nick, $remoteArgv, $stdin, $timeout);
        $out = [];
        foreach (explode("\n", $r['stdout']) as $line) {
            $rec = trim($line) !== '' ? json_decode($line, true) : null;
            if (is_array($rec)) {
                $out[] = ['service' => (string) $nick] + $rec;
            }
        }
        if (!$out || !array_key_exists('ok', end($out))) {
            $err = $r['timed_out'] ? "no answer within {$timeout}s" : self::sshError($r['stderr'], $r['rc']);
            $out[] = ['service' => (string) $nick, 'ok' => false, 'error' => $err, 'hint' => '`services test ' . $nick . '` checks the connection'];
        }
        return $out;
    }

    /** ssh's error as one line; a changed host key is named as such and ssh's advice to delete the pinned key is dropped. */
    public static function sshError($stderr, $rc)
    {
        if (preg_match('/Host key verification failed|IDENTIFICATION HAS CHANGED|host key .*has changed/i', (string) $stderr)) {
            return 'the host key is not the one pinned at setup; someone may be in between, so nothing was sent (run setup again with --fingerprint)';
        }
        $lines = array_values(array_filter(explode("\n", trim((string) $stderr)), 'strlen'));
        return $lines ? substr(Cli::cleanString(end($lines)), 0, 300) : "exit $rc";
    }

    /**
     * $fn($nick) for each nick, at most $parallel at once (forked processes when pcntl is there, else one after another),
     * each limited to $timeout seconds. A failure or timeout gives that nick an error record; it never stops the others.
     */
    public static function fanout(array $nicks, callable $fn, $parallel = 4, $timeout = 180)
    {
        $nicks = array_values(array_unique(array_map('strval', $nicks)));
        $results = [];
        if (!function_exists('pcntl_fork') || count($nicks) < 2 || $parallel < 2) {
            foreach ($nicks as $n) {
                $results[$n] = self::callOne($fn, $n);
            }
            return $results;
        }
        $tmp = self::tmpDir();
        $running = [];
        $queue = $nicks;
        while ($queue || $running) {
            while ($queue && count($running) < $parallel) {
                $n = array_shift($queue);
                $file = $tmp . '/fanout-' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.json';
                $pid = pcntl_fork();
                if ($pid === 0) {
                    $old = umask(0077);
                    file_put_contents($file, json_encode(self::callOne($fn, $n), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
                    umask($old);
                    // End the child without running the parent's shutdown functions or destructors (exit() would run them
                    // once per child: lock releases, temp cleanup, the test suite's crontab guard).
                    posix_kill(getmypid(), SIGKILL);
                    exit(0);
                }
                if ($pid < 0) {
                    $results[$n] = self::callOne($fn, $n);
                    continue;
                }
                $running[$pid] = ['nick' => $n, 'file' => $file, 'start' => microtime(true)];
            }
            foreach ($running as $pid => $job) {
                $done = pcntl_waitpid($pid, $status, WNOHANG);
                if ($done === 0 && microtime(true) - $job['start'] > $timeout) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                    $results[$job['nick']] = self::errorRecord($job['nick'], "no answer within {$timeout}s", 'the service may be down or slow');
                } elseif ($done !== 0) {
                    $r = json_decode((string) @file_get_contents($job['file']), true);
                    $results[$job['nick']] = is_array($r) ? $r : self::errorRecord($job['nick'], 'the worker ended without a result', 'run it for this service alone');
                } else {
                    continue;
                }
                @unlink($job['file']);
                unset($running[$pid]);
            }
            usleep(20000);
        }
        $ordered = [];
        foreach ($nicks as $n) {
            $ordered[$n] = $results[$n];
        }
        return $ordered;
    }

    private static function callOne(callable $fn, $nick)
    {
        try {
            return call_user_func($fn, $nick);
        } catch (CliError $e) {
            return self::errorRecord($nick, $e->getMessage(), $e->hint);
        } catch (Throwable $e) {
            return self::errorRecord($nick, 'internal error (' . get_class($e) . ')', 'the tool has a bug');
        }
    }

    public static function errorRecord($nick, $error, $hint)
    {
        return [['service' => $nick, 'ok' => false, 'error' => $error, 'hint' => $hint]];
    }

    /**
     * Runs $argv (ToolProc::line: every word escaped, `exec` so signals reach it; in its own session when setsid is there,
     * so a timeout ends the whole group). $stdin: a string or null. Returns ['rc', 'stdout', 'stderr', 'timed_out'].
     */
    public static function exec(array $argv, $stdin = null, $timeout = 120, array $env = [])
    {
        $vars = getenv();
        foreach ($env as $k => $v) {
            if ($v === null) {
                unset($vars[$k]);
            } else {
                $vars[$k] = (string) $v;
            }
        }
        $setsid = ToolProc::which('setsid');
        $p = proc_open('exec ' . ($setsid !== null ? escapeshellarg($setsid) . ' ' : '') . ToolProc::line($argv),
            [0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $vars);
        if (!is_resource($p)) {
            throw new CliError('could not start ' . PathName::base((string) $argv[0]), 'check that it is installed and runnable');
        }
        if ($stdin !== null) {
            stream_set_blocking($pipes[0], false);
        }
        $r = ['rc' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => false];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $s) {
            stream_set_blocking($s, false);
        }
        $pending = $stdin === null ? '' : (string) $stdin;
        $end = microtime(true) + $timeout;
        while ($open || ($stdin !== null && is_resource($pipes[0]))) {
            if (microtime(true) > $end) {
                $r['timed_out'] = true;
                $st = proc_get_status($p);
                if ($setsid !== null && !empty($st['pid']) && function_exists('posix_kill')) {
                    posix_kill(-$st['pid'], 9);
                }
                proc_terminate($p, 9);
                break;
            }
            if ($stdin !== null && is_resource($pipes[0])) {
                $w = $pending !== '' ? @fwrite($pipes[0], substr($pending, 0, 65536)) : 0;
                $pending = (string) substr($pending, (int) $w);
                if ($pending === '' || $w === false) {
                    fclose($pipes[0]);
                }
            }
            $read = array_values($open);
            $wr = $ex = null;
            if ($read && @stream_select($read, $wr, $ex, 0, 100000) === false) {
                break;
            }
            foreach ($open as $k => $s) {
                $chunk = fread($s, 65536);
                if ($chunk === '' || $chunk === false) {
                    if (feof($s)) {
                        unset($open[$k]);
                    }
                    continue;
                }
                $key = $k === 1 ? 'stdout' : 'stderr';
                $r[$key] .= strlen($r[$key]) < ToolProc::MAX_OUT ? $chunk : '';
            }
        }
        foreach ($pipes as $s) {
            if (is_resource($s)) {
                fclose($s);
            }
        }
        $r['rc'] = proc_close($p);
        return $r;
    }
}
