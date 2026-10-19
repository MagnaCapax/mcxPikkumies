<?php
/**
 * BrowseCli: one agent-browser call from Hermes (or the agent's terminal), through the generated agent-browser
 * command. The real CLI always runs inside the namespace (nsenter), never in the host's: agent-browser starts a new
 * daemon when none answers, and one started in the host network namespace would put Chrome's CDP port on the shared
 * 127.0.0.1. The browser starts by itself on the first call (every check and the memory gate run then) and the call is
 * refused with the reason when it cannot; a part found gone stops everything first. Errors go out in agent-browser's own
 * JSON shape when the call asked for --json, so Hermes shows the reason.
 */
class BrowseCli
{
    public static function main(array $args, $sup, $stdin = null)
    {
        $json = in_array('--json', $args, true);
        if ($stdin === null && in_array('batch', $args, true) && !(function_exists('posix_isatty') && posix_isatty(STDIN))) {
            $stdin = (string) stream_get_contents(STDIN);
        }
        $plan = BrowseArgv::plan($args, $stdin);
        if (isset($plan['refuse'])) {
            return self::fail($json, 'refused: ' . $plan['refuse']);
        }
        $pin = BrowseBinary::currentPin();
        if ($pin === null) {
            return self::fail($json, 'the browser is off: the Hermes lock pins no agent-browser for this machine');
        }
        if ($plan['mode'] === 'direct') { // --version / --help: no daemon, nothing to isolate
            $problem = BrowsePaths::ensureDirs();
            $problem = $problem !== '' ? $problem : BrowseBinary::ensure($pin, HermesInstall::target());
            if ($problem !== '') {
                return self::fail($json, $problem);
            }
            $r = $sup->proc->run(array_merge([BrowseBinary::path($pin['version'])], $plan['calls'][0]['argv']), ['PATH' => '/usr/bin:/bin'], 20);
            return self::emit($r);
        }
        $problem = BrowseCheck::problem();
        if ($problem !== '') {
            return self::fail($json, "the browser is off: $problem");
        }
        $s = BrowseState::read();
        if ($s === null || BrowseState::gone($s, $sup->proc) !== '') {
            list($ok, $msg) = $sup->up();
            if (!$ok) {
                return self::fail($json, $msg);
            }
            $s = BrowseState::read();
        }
        $gone = $s === null ? 'not running' : BrowseState::gone($s, $sup->proc);
        if ($gone !== '') { // the guard or the namespace went away between the start and now: fail closed
            $sup->down($gone);
            return self::fail($json, "the browser stopped ($gone); the next call starts it again");
        }
        BrowseState::touch();
        if ($plan['mode'] === 'close') {
            $note = 'the browser is shared by this account\'s sessions and stops itself after 15 idle minutes; `pikkumies browse down` stops it now';
            echo $json ? json_encode(['success' => true, 'data' => ['closed' => false, 'note' => $note], 'error' => null]) . "\n" : "$note\n";
            return 0;
        }
        $env = BrowseSupervisor::cliEnv($sup->identity !== null ? $sup->identity : BrowseSupervisor::identity());
        $bin = BrowseBinary::path($pin['version']);
        $last = count($plan['calls']) - 1;
        foreach ($plan['calls'] as $n => $call) {
            if ($call['intent'] !== null) { // the guard adds the panel login only to a navigation the CLI announced
                HermesConfig::put(BrowsePaths::file('intent'), $call['intent'] . "\n", 0600);
            }
            $r = $sup->proc->run(BrowseIsolation::nsenterArgv($s['inner']['pid'], array_merge([$bin], $call['argv'])), $env, 300, $call['stdin']);
            if ($n === $last || $r['code'] !== 0) {
                return self::emit($r);
            }
        }
        return 0;
    }

    /** status: may it run here (and why not), does it run, the versions. */
    public static function statusRecord()
    {
        $problem = BrowseCheck::problem();
        $pin = BrowseBinary::currentPin();
        $running = BrowseState::running() !== null;
        $idle = BrowseState::idleSeconds(time());
        return ['may_run' => $problem === '', 'off_because' => $problem !== '' ? $problem : null, 'running' => $running,
            'agent_browser' => $pin !== null ? $pin['version'] : null, 'agent_browser_fetched' => $pin !== null && BrowseBinary::verify($pin, false) === '',
            'isolated' => $running, 'guard' => $running ? 'up' : 'down', 'idle_seconds' => $running ? $idle : null,
            'stops_after_idle_seconds' => BrowseSupervisor::IDLE];
    }

    private static function emit(array $r)
    {
        echo $r['out'];
        if ($r['err'] !== '') {
            fwrite(STDERR, $r['err']);
        }
        return (int) $r['code'];
    }

    private static function fail($json, $msg)
    {
        if ($json) {
            echo json_encode(['success' => false, 'data' => null, 'error' => "pikkumies: $msg"], JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            fwrite(STDERR, "pikkumies: $msg\n");
        }
        return 1;
    }
}
