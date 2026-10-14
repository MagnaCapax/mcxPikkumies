<?php
/**
 * ArrCommand: POST /command for the few commands a unit's verbs need, then poll GET /command/{id}.
 * Only names in the app's own allowlist (ArrApps 'commands') can be sent, and never one matching
 * ArrApps::NEVER (update, key reset, shutdown, file deletes, log clearing, renames, moves). There is
 * no generic command verb. All commands of one call share one wait, bounded by WAIT, below Hermes'
 * terminal timeout (180 s by default in the pinned release: tools/terminal_tool.py:709,
 * hermes_cli/config_defaults.py:277,294). When the wait runs out the record says the state so far.
 */
class ArrCommand
{
    const WAIT = 60;    // seconds; the agent's terminal call gives up at 180 s
    const FINAL = ['completed', 'failed', 'aborted', 'cancelled', 'orphaned']; // CommandStatus enum of the specs
    const BAD = ['failed', 'aborted', 'cancelled', 'orphaned'];

    /** Sends the commands ([name, body, label] each), waits for all, returns one record each; CliError when any failed. */
    public static function run(ArrClient $c, array $app, array $commands)
    {
        $sent = [];
        foreach ($commands as $cmd) {
            list($name, $body) = $cmd;
            if (!in_array($name, $app['commands'], true) || preg_match(ArrApps::NEVER, $name)) {
                throw new CliError("the command $name is not allowed", 'this tool sends only: ' . implode(', ', $app['commands']));
            }
            $r = $c->call('POST', 'command', [], ['name' => $name] + $body);
            if (!isset($r['id'])) {
                throw new CliError("the app did not return a command id for $name", 'check the app\'s log (System, Logs)');
            }
            $sent[] = ['label' => isset($cmd[2]) ? $cmd[2] : [], 'name' => $name, 'cmd' => $r];
        }
        $wait = (int) getenv('PIKKUMIES_ARR_WAIT'); // test seam: a shorter wait
        $deadline = microtime(true) + ($wait > 0 && $wait < self::WAIT ? $wait : self::WAIT);
        while (self::pending($sent) && microtime(true) < $deadline) {
            usleep(500000);
            foreach ($sent as $i => $s) {
                if (!in_array(self::status($s['cmd']), self::FINAL, true)) {
                    $sent[$i]['cmd'] = $c->call('GET', 'command/' . (int) $s['cmd']['id'], [], null, ['missing' => "the app no longer knows command {$s['cmd']['id']}"]);
                }
            }
        }
        $out = [];
        $bad = [];
        foreach ($sent as $s) {
            $state = self::status($s['cmd']);
            $msg = isset($s['cmd']['message']) && is_string($s['cmd']['message']) ? $c->redact($s['cmd']['message']) : '';
            $r = $s['label'] + ['command_id' => (int) $s['cmd']['id'], 'name' => $s['name'], 'state' => $state, 'message' => $msg];
            if ($state !== 'completed' && !in_array($state, self::BAD, true)) {
                $r['note'] = "still $state after the wait; the app keeps working on it (see `history` later)";
            }
            if (in_array($state, self::BAD, true)) {
                $why = isset($s['cmd']['exception']) && is_string($s['cmd']['exception']) ? strtok($s['cmd']['exception'], "\n") : $msg;
                $bad[] = "{$s['name']} {$r['command_id']} $state" . ($why !== '' ? ': ' . $c->redact($why) : '');
            }
            $out[] = $r;
        }
        if ($bad) {
            throw new CliError(implode('; ', $bad) . (count($bad) < count($out) ? ' (the other commands were sent: ' . count($out) . ' in all)' : ''), 'see the app\'s log (System, Logs)');
        }
        return $out;
    }

    private static function status(array $cmd)
    {
        return isset($cmd['status']) && is_string($cmd['status']) ? $cmd['status'] : 'unknown';
    }

    private static function pending(array $sent)
    {
        foreach ($sent as $s) {
            if (!in_array(self::status($s['cmd']), self::FINAL, true)) {
                return true;
            }
        }
        return false;
    }
}
