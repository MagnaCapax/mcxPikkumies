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

    /**
     * Sends the commands ([name, body, label] each), waits for all, returns one record each; CliError when any failed.
     * Sending stops at the first POST that fails: that one is `failed`, the rest `not_sent`; the sent ones are still
     * waited for, and the error names what happened to every command.
     */
    public static function run(ArrClient $c, array $app, array $commands)
    {
        foreach ($commands as $cmd) { // all checked before anything is sent
            if (!in_array($cmd[0], $app['commands'], true) || preg_match(ArrApps::NEVER, $cmd[0])) {
                throw new CliError("the command {$cmd[0]} is not allowed", 'this tool sends only: ' . implode(', ', $app['commands']));
            }
        }
        $sent = [];
        $unsent = [];
        $sendError = '';
        foreach (array_values($commands) as $k => $cmd) {
            list($name, $body) = $cmd;
            $label = isset($cmd[2]) ? $cmd[2] : [];
            $none = $label + ['command_id' => 0, 'name' => $name, 'state' => 'not_sent',
                'message' => 'not sent: an earlier command in this call failed'];
            if ($sendError !== '') {
                $unsent[$k] = $none;
                continue;
            }
            try {
                $r = $c->call('POST', 'command', [], ['name' => $name] + $body);
                if (!isset($r['id'])) {
                    throw new CliError("the app did not return a command id for $name", 'check the app\'s log (System, Logs)');
                }
                $sent[$k] = ['label' => $label, 'name' => $name, 'cmd' => $r];
            } catch (CliError $e) {
                $sendError = $c->redact($e->getMessage());
                $unsent[$k] = array_merge($none, ['state' => 'failed', 'message' => "sending failed: $sendError"]);
            }
        }
        $wait = (int) getenv('PIKKUMIES_ARR_WAIT'); // test seam: a shorter wait
        $deadline = microtime(true) + ($wait > 0 && $wait < self::WAIT ? $wait : self::WAIT);
        while (self::pending($sent) && microtime(true) < $deadline) {
            usleep(500000);
            foreach ($sent as $i => $s) {
                if (!isset($s['lost']) && !in_array(self::status($s['cmd']), self::FINAL, true)) {
                    try {
                        $sent[$i]['cmd'] = $c->call('GET', 'command/' . (int) $s['cmd']['id'], [], null,
                            ['missing' => "the app no longer knows command {$s['cmd']['id']}"]);
                    } catch (CliError $e) { // sent, so it may still run: keep it in the report as unknown
                        $sent[$i]['lost'] = $c->redact($e->getMessage());
                        $sendError = $sendError !== '' ? $sendError : $sent[$i]['lost'];
                    }
                }
            }
        }
        $out = [];
        $bad = [];
        for ($k = 0; $k < count($commands); $k++) { // in the order given
            if (isset($unsent[$k])) {
                $out[] = $unsent[$k];
                continue;
            }
            $s = $sent[$k];
            $state = self::status($s['cmd']);
            $msg = isset($s['cmd']['message']) && is_string($s['cmd']['message']) ? $c->redact($s['cmd']['message']) : '';
            if (isset($s['lost'])) {
                $state = 'unknown';
                $msg = "sent, but its state could not be read ({$s['lost']}); the app may still run it";
            }
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
        if ($sendError !== '') {
            throw new CliError('not done as asked: ' . implode(' | ', array_map(function ($r) {
                $for = isset($r['id']) ? " for {$r['id']}" . (empty($r['title']) ? '' : " ({$r['title']})")
                    : (isset($r['ids']) ? ' for ' . implode(',', (array) $r['ids']) : '');
                return $r['name'] . $for . ($r['command_id'] ? " (command {$r['command_id']})" : '') . ": {$r['state']}"
                    . ($r['message'] !== '' ? ", {$r['message']}" : '');
            }, $out)), 'tell the user exactly this, command by command; `history` shows what the app ran');
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
