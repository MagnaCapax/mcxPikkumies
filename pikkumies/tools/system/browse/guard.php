<?php
/**
 * guard.php: the browse unit's CDP guard. Holds one connection to the browser's DevTools endpoint, attaches to every
 * target before it runs, and decides every request (WebGuardDecision): the panel login only for the account's own
 * prefix asked for by its own page or by the CLI, the neighbours' paths blocked, the login stripped everywhere else,
 * every 401 challenge cancelled. The supervisor starts it before the first navigation and treats its exit as the
 * browser's stop, because a browser whose guard is gone runs unchecked.
 *
 * php guard.php --cdp ws://127.0.0.1:<port>/devtools/browser/<id> --log <file> --self <account> --host <panel host>...
 *               [--intent <file>] [--auth-file <file>] [--ready <file>]
 *  --log        JSON Lines of blocked requests: time, reason, first path segment, method, type (never a full URL or
 *               a query string). Created 0600.
 *  --host       a host name the panel is reached by (repeatable, at least one). Required: a guessed name that missed
 *               the one the browser uses would leave the neighbours' paths unfenced.
 *  --intent     the file the wrapper writes the CLI's next URL to (WebGuardIntent); without it the CLI's own
 *               navigation never gets the login.
 *  --auth-file  one line, the Authorization header value ("Basic <base64>"); a regular file this account owns, 0600.
 *               Read once at start; never on the command line, never logged. Without it nothing gets the login
 *               (the own panel answers 401, which is cancelled) and the fence works the same.
 *  --ready      written (0600, the guard's pid) once the browser has answered the attach and every target that
 *               already existed has its interception on; the supervisor waits for it before the first navigation.
 * Exit: 0 on SIGTERM or SIGINT, 1 when it cannot start, 2 when the browser connection drops or closes.
 */
require_once dirname(__DIR__, 3) . '/lib/Cli.php'; // CliError, thrown by SecretFile
require_once dirname(__DIR__, 3) . '/lib/SecretFile.php';
require_once dirname(__DIR__, 3) . '/lib/Host.php';
require_once __DIR__ . '/WebGuardDecision.php';
require_once __DIR__ . '/WebGuardIntent.php';
require_once __DIR__ . '/WebGuardEvents.php';
require_once __DIR__ . '/CdpSocket.php';

/** The options, checked; throws with the reason. */
function webGuardOptions(array $argv)
{
    $o = ['hosts' => []];
    for ($i = 1; $i < count($argv); $i++) {
        $k = $argv[$i];
        if (!in_array($k, ['--cdp', '--auth-file', '--log', '--self', '--host', '--intent', '--ready'], true) || !isset($argv[$i + 1])) {
            throw new Exception("unknown option or missing value: $k");
        }
        $v = $argv[++$i];
        if ($k === '--host') {
            $o['hosts'][] = $v;
        } else {
            $o[substr($k, 2)] = $v;
        }
    }
    foreach (['cdp', 'log', 'self'] as $need) {
        if (!isset($o[$need]) || $o[$need] === '') {
            throw new Exception("--$need is required");
        }
    }
    if (!$o['hosts'] || in_array('', $o['hosts'], true)) {
        throw new Exception('--host is required: the panel host name the browser uses');
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $o['self'])) {
        throw new Exception('--self is not an account name');
    }
    $u = parse_url($o['cdp']);
    $host = is_array($u) && isset($u['host']) ? trim($u['host'], '[]') : '';
    if (!is_array($u) || !isset($u['scheme'], $u['port']) || $u['scheme'] !== 'ws' || !in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
        throw new Exception('--cdp must be a ws:// address on this machine\'s loopback with a port');
    }
    $o['port'] = (int) $u['port'];
    return $o;
}

/** The Authorization header value from $file: one line, a scheme and a token, nothing else. */
function webGuardAuth($file)
{
    $raw = SecretFile::read($file, 'write the login file again (one line, 0600)');
    $v = trim((string) $raw);
    if ($raw === false || !preg_match('#^[A-Za-z]+ [A-Za-z0-9+/=._~-]+$#', $v)) {
        throw new Exception("$file does not hold one Authorization header value (\"Basic <base64>\")");
    }
    return $v;
}

/** Writes $file 0600 through a temporary file and a rename, so the supervisor never reads half of it. */
function webGuardReady($file)
{
    $old = umask(0077);
    $ok = @file_put_contents($file . '.tmp', getmypid() . "\n") !== false && @rename($file . '.tmp', $file);
    umask($old);
    if (!$ok) {
        throw new Exception("cannot write the ready file $file");
    }
}

function webGuardLog($file, array $rec)
{
    $old = umask(0077);
    @file_put_contents($file, json_encode(['ts' => gmdate('c')] + $rec, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    umask($old);
}

try {
    $o = webGuardOptions($argv);
    $auth = isset($o['auth-file']) ? webGuardAuth($o['auth-file']) : null;
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $owner = Host::ownsListener($o['port'], [$uid]);
    if ($owner !== 'own') {
        throw new Exception("the DevTools port {$o['port']} is " . ($owner === 'other' ? "another account's" : ($owner === 'none' ? 'not listening' : 'of unknown owner'))
            . '; not connecting');
    }
    $intent = isset($o['intent']) ? function ($url) use ($o) {
        return WebGuardIntent::take($o['intent'], $url, time());
    } : null;
    $events = new WebGuardEvents($o['self'], $o['hosts'], $auth, $intent);
    $ws = new CdpSocket($o['cdp']);
} catch (Exception $e) {
    fwrite(STDERR, 'browse guard: ' . $e->getMessage() . (isset($e->hint) && $e->hint !== '' ? ' (' . $e->hint . ')' : '') . "\n");
    exit(1);
}

$stop = false;
$readyDone = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $onSignal = function () use (&$stop) {
        $stop = true;
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}
foreach ($events->start() as $c) {
    $ws->send($c);
}
fwrite(STDERR, 'browse guard: watching the browser for ' . $o['self'] . "\n");
while (!$stop) {
    $r = [$ws->stream()];
    $w = $x = null;
    if (!@stream_select($r, $w, $x, 1)) {
        continue; // timeout, or a signal interrupted the wait
    }
    foreach ($ws->read() as $m) {
        $out = $events->handle($m);
        foreach ($out['send'] as $c) {
            $ws->send($c);
        }
        foreach ($out['log'] as $rec) {
            webGuardLog($o['log'], $rec);
        }
    }
    if ($ws->closed) {
        fwrite(STDERR, "browse guard: the browser connection ended\n");
        exit(2);
    }
    if ($events->broken()) {
        fwrite(STDERR, "browse guard: the browser refused to attach to its targets\n");
        exit(1);
    }
    if (!$readyDone && $events->ready()) {
        $readyDone = true;
        if (isset($o['ready'])) {
            try {
                webGuardReady($o['ready']);
            } catch (Exception $e) {
                fwrite(STDERR, 'browse guard: ' . $e->getMessage() . "\n");
                exit(1);
            }
        }
    }
}
exit(0);
