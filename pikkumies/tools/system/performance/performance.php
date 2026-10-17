<?php
/**
 * performance: why is it slow, measured from this account (README.md, USAGE.md, ADR-0035). Five verbs:
 *   check    the account's limits and use, the server's load and pressure, the user's own transfer sessions,
 *            with a verdict from a fixed rule table (PerfRouter); `--tick` writes one silent sample (PerfSamples)
 *   net      the route to a target with mtr (PerfNet)
 *   disk     a light disk probe: 64 KiB written, up to 256 KiB read (PerfDisk)
 *   heavy    load tests, only on the user's yes through the guard (PerfHeavy)
 *   samples  the samples a scheduled `check --tick` wrote (PerfSamples)
 * This file checks the arguments (a bad one exits 2 before anything runs), takes the one-run-at-a-time lock,
 * removes scratch left by dead runs, and calls the verb's class with the checked parameters. A verb whose class
 * file or method is not there yet answers "not built yet" (exit 1) and runs nothing. Loading this file reads
 * nothing and runs nothing.
 */
$perfLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'Cgroup', 'Detect', 'Quota', 'Http'] as $perfClass) {
    require_once "$perfLib/$perfClass.php";
}
require_once __DIR__ . '/PerfProbe.php';
foreach (['PerfAccount', 'PerfHost', 'PerfProcs', 'PerfSessions', 'PerfRouter', 'PerfSamples', 'PerfNet', 'PerfDisk', 'PerfHeavy'] as $perfPart) {
    if (is_file(__DIR__ . "/$perfPart.php")) {
        require_once __DIR__ . "/$perfPart.php";
    }
}

$usage = <<<'TXT'
usage: php performance.php <verb> [arguments] [--human]
  check [--symptom S] [--share [--with-ip]]   limits, use, server load, own sessions, verdict (about 2 s, nothing written but the run log)
        S: slow-download, slow-upload, capped, stalls, torrents, lag, everything
  check --tick                                write one sample, print nothing (the scheduled job runs this)
  net --target ssh|IP|HOST [--count N]        route test with mtr; N 1-1000, default 20 (quick), 1000 = the wiki procedure
  disk [--no-read]                            light disk probe: 16 small synced writes, 64 small direct reads
  heavy --test disk-read|disk-write|download --url U|iperf3 --target H|speedtest
                                              load test; asks the user first; result is your share now, not the ceiling
  samples [--hours N]                         per-hour medians of the samples; N 1-720, default 24
Output: JSON Lines; --human for people. See USAGE.md.
TXT;

/** verb => [class, static method]; the method gets (PerfProbe $probe, array $params) and returns records. */
$perfVerbs = [
    'check' => ['PerfRouter', 'check'],
    'net' => ['PerfNet', 'net'],
    'disk' => ['PerfDisk', 'disk'],
    'heavy' => ['PerfHeavy', 'heavy'],
    'samples' => ['PerfSamples', 'samples'],
];
const PERF_TICK = ['PerfSamples', 'tick'];
const PERF_SYMPTOMS = ['slow-download', 'slow-upload', 'capped', 'stalls', 'torrents', 'lag', 'everything'];
const PERF_HEAVY_TESTS = ['disk-read', 'disk-write', 'download', 'iperf3', 'speedtest'];
/** Options each verb takes (the global ones, --human --limit ..., are Cli's). */
const PERF_VERB_OPTIONS = [
    'check' => ['symptom', 'share', 'with-ip', 'tick'],
    'net' => ['target', 'count'],
    'disk' => ['no-read'],
    'heavy' => ['test', 'url', 'target'],
    'samples' => ['hours'],
];

/** A host for net/heavy: an IPv4/IPv6 literal or a hostname that cannot be read as an option; `ssh` only where allowed. */
function perfTarget($t, $allowSsh)
{
    $t = is_string($t) ? $t : '';
    if ($allowSsh && $t === 'ssh') {
        return $t;
    }
    if ($t !== 'ssh' && (filter_var($t, FILTER_VALIDATE_IP) !== false || preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $t))) {
        return $t;
    }
    throw new PerfUsageError("not a target: an IP address or a hostname" . ($allowSsh ? ', or ssh' : '') . " was expected", $allowSsh
        ? 'ask the user: "test the route to the connection you are on?", then --target ssh; or --target <IP or hostname>'
        : 'the server to test against, e.g. --target iperf.example.net');
}

/** A whole number in [$min, $max] or $default when absent. */
function perfInt(array $opt, $name, $min, $max, $default)
{
    if (!isset($opt[$name])) {
        return $default;
    }
    $v = $opt[$name];
    if (!is_string($v) || !ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
        throw new PerfUsageError("--$name takes a whole number from $min to $max", "e.g. --$name $default");
    }
    return (int) $v;
}

/** Checked parameters for $verb, or PerfUsageError. Nothing is read or run here. */
function perfParams($verb, array $args, array $opt)
{
    if ($args) {
        throw new PerfUsageError("$verb takes no positional arguments", 'see --help');
    }
    $global = array_merge(Cli::GLOBAL_FLAGS, ['limit', 'offset', 'fields']);
    $foreign = array_diff(array_keys($opt), $global, PERF_VERB_OPTIONS[$verb]);
    if ($foreign) {
        throw new PerfUsageError("$verb does not take --" . implode(', --', $foreign) . '; nothing was done', $verb . ' options: --' . implode(', --', PERF_VERB_OPTIONS[$verb]));
    }
    $has = function ($k) use ($opt) {
        return isset($opt[$k]);
    };
    $str = function ($k) use ($opt) {
        if (!isset($opt[$k]) || !is_string($opt[$k]) || $opt[$k] === '') {
            throw new PerfUsageError("--$k needs a value", 'see --help');
        }
        return $opt[$k];
    };
    switch ($verb) {
        case 'check':
            if ($has('tick')) {
                if ($has('symptom') || $has('share') || $has('with-ip')) {
                    throw new PerfUsageError('--tick takes no other option', 'the scheduled job runs exactly: check --tick');
                }
                return ['tick' => true];
            }
            $symptom = $has('symptom') ? $str('symptom') : 'everything';
            if (!in_array($symptom, PERF_SYMPTOMS, true)) {
                throw new PerfUsageError("unknown symptom: $symptom", 'symptoms: ' . implode(', ', PERF_SYMPTOMS));
            }
            if ($has('with-ip') && !$has('share')) {
                throw new PerfUsageError('--with-ip only goes with --share', 'run check --share --with-ip, and only when the user asked for the addresses');
            }
            return ['tick' => false, 'symptom' => $symptom, 'share' => $has('share'), 'with_ip' => $has('with-ip')];
        case 'net':
            if (!$has('target')) {
                throw new PerfUsageError('net needs --target; nothing was run', 'ask the user: "test the route to the connection you are on?", then --target ssh');
            }
            return ['target' => perfTarget($str('target'), true), 'count' => perfInt($opt, 'count', 1, PerfLimits::NET_COUNT_MAX, PerfLimits::NET_QUICK_COUNT)];
        case 'disk':
            return ['read' => !$has('no-read')];
        case 'heavy':
            $test = $has('test') ? $str('test') : '';
            if (!in_array($test, PERF_HEAVY_TESTS, true)) {
                throw new PerfUsageError('heavy needs --test ' . implode('|', PERF_HEAVY_TESTS), 'run disk first; a heavy test only after the user said yes');
            }
            $p = ['test' => $test, 'url' => null, 'target' => null];
            if ($test === 'download') {
                $url = $has('url') ? $str('url') : '';
                if (!preg_match('#^https?://[^\s\x00-\x1f\x7f]{1,2040}$#', $url)) {
                    throw new PerfUsageError('heavy --test download needs --url http(s)://...', 'a file the user named; the download is counted and thrown away');
                }
                $p['url'] = $url;
            } elseif ($has('url')) {
                throw new PerfUsageError('--url goes only with --test download', 'see --help');
            }
            if ($test === 'iperf3') {
                $p['target'] = perfTarget($has('target') ? $str('target') : '', false);
            } elseif ($has('target')) {
                throw new PerfUsageError('--target goes only with --test iperf3', 'see --help');
            }
            return $p;
        case 'samples':
            return ['hours' => perfInt($opt, 'hours', 1, PerfLimits::SAMPLES_HOURS_MAX, PerfLimits::SAMPLES_HOURS_DEFAULT)];
    }
    throw new PerfUsageError("unknown verb: $verb", 'see --help');
}

/** Usage error: the usage on stderr, the status line, exit 2 (as Cli::run does for an unknown option). */
function perfUsageExit(PerfUsageError $e, array $opt, $usage)
{
    fwrite(STDERR, rtrim($usage) . "\n");
    Cli::fail($e->getMessage(), $e->hint, !empty($opt['human']));
    exit(2);
}

/** check --tick: one sample, never any stdout (a scheduled no_agent job sends whatever it prints). */
function perfTick(PerfProbe $probe)
{
    if (!$probe->lock()) {
        exit(0); // another run is going; this sample is skipped, silently
    }
    try {
        $probe->sweep();
        if (!method_exists(PERF_TICK[0], PERF_TICK[1])) {
            fwrite(STDERR, "check --tick is not built yet; nothing was written\n");
            exit(1);
        }
        call_user_func(PERF_TICK, $probe, ['tick' => true]);
        $probe->finish('tick');
    } catch (Throwable $e) {
        fwrite(STDERR, 'check --tick failed: ' . Cli::cleanString($e->getMessage()) . "\n");
        exit(1);
    }
    exit(0);
}

// Test seams: a test may set $perfSeams before including this file (see PerfProbe::__construct). Nothing is read from the environment.
$perfContext = isset($perfSeams) && is_array($perfSeams) ? $perfSeams : [];
$perfHandlers = [];
foreach ($perfVerbs as $perfVerb => $perfEntry) {
    $perfHandlers[$perfVerb] = function ($args, $opt) use ($perfVerb, $perfEntry, $perfContext, $usage) {
        try {
            $params = perfParams($perfVerb, $args, $opt);
        } catch (PerfUsageError $e) {
            perfUsageExit($e, $opt, $usage);
        }
        $probe = new PerfProbe($perfContext);
        if (!empty($params['tick'])) {
            perfTick($probe);
        }
        if ($perfVerb !== 'samples') { // samples only reads its file; every other verb runs alone
            if (!$probe->lock()) {
                throw new CliError('another performance run is in progress; nothing was done', 'wait for it to finish (check takes a few seconds, net up to the time it printed), then run again');
            }
            $probe->sweep();
        }
        if (!method_exists($perfEntry[0], $perfEntry[1])) {
            throw new CliError("$perfVerb is not built yet; nothing was done", 'this pikkumies version does not have it yet');
        }
        try {
            return call_user_func($perfEntry, $probe, $params);
        } catch (PerfUsageError $e) {
            perfUsageExit($e, $opt, $usage); // a verb class found a bad argument before running anything
        } finally {
            if ($perfVerb !== 'samples') {
                $probe->finish($perfVerb);
            }
        }
    };
}

Cli::run($argv, $perfHandlers, $usage, ['share', 'with-ip', 'tick', 'no-read'], ['symptom', 'target', 'count', 'test', 'url', 'hours']);
