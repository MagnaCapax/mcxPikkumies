<?php
/** lib/Launch.php: argv routing for bin/pikkumies (ADR-0022). */
return [
    'launch: no args is a plain interactive session, same as `run`' => function () {
        list($cmd, $args) = Launch::route(['pikkumies']);
        check($cmd === 'run' && $args === [], 'expected bare run: ' . json_encode([$cmd, $args]));
    },
    'launch: help and its aliases print usage, never a prompt' => function () {
        foreach (['help', '--help', '-h'] as $w) {
            list($cmd, $args) = Launch::route(['pikkumies', $w]);
            check($cmd === 'help' && $args === [], "$w misrouted: " . json_encode([$cmd, $args]));
        }
    },
    'launch: a known subcommand wins, and its own words pass through unchanged' => function () {
        foreach (['install', 'update', 'post-update', 'detect', 'doctor', 'generate', 'cron-tick', 'docs-fill'] as $w) {
            list($cmd, $args) = Launch::route(['pikkumies', $w]);
            check($cmd === $w && $args === [], "$w misrouted: " . json_encode([$cmd, $args]));
        }
        list($cmd, $args) = Launch::route(['pikkumies', 'run', 'sessions', 'list']);
        check($cmd === 'run' && $args === ['sessions', 'list'], 'explicit run passthrough changed: ' . json_encode($args));
        list($cmd, $args) = Launch::route(['pikkumies', 'run']);
        check($cmd === 'run' && $args === [], 'explicit run with no args changed: ' . json_encode($args));
    },
    'launch: free text becomes a seeded prompt, words rejoined with single spaces' => function () {
        list($cmd, $args) = Launch::route(['pikkumies', 'check', 'my', 'stats', 'and', 'can', 'we', 'increase', 'performance']);
        check($cmd === 'run' && $args === ['chat', '--query=check my stats and can we increase performance'], 'free text misrouted: ' . json_encode([$cmd, $args]));
    },
    'launch: a prompt that starts with a Pikkumies subcommand word stays a prompt' => function () {
        // Natural requests ("update my plex", "install radarr please", "detect what is slow") must
        // never start a self-update or re-run the installer.
        foreach ([['update', 'my', 'plex'], ['install', 'radarr', 'please'], ['detect', 'what', 'is', 'slow'], ['doctor', 'my', 'jellyfin'], ['cron-tick', 'the', 'rename']] as $words) {
            list($cmd, $args) = Launch::route(array_merge(['pikkumies'], $words));
            check($cmd === 'run' && $args === ['chat', '--query=' . implode(' ', $words)], 'subcommand word swallowed a prompt: ' . json_encode([$cmd, $args]));
        }
        // Flags after a subcommand keep it a subcommand.
        list($cmd, $args) = Launch::route(['pikkumies', 'detect', '--refresh']);
        check($cmd === 'detect' && $args === ['--refresh'], 'subcommand with flags misrouted: ' . json_encode([$cmd, $args]));
        list($cmd, $args) = Launch::route(['pikkumies', '--', 'update', 'the', 'kernel']);
        check($cmd === 'run' && $args === ['chat', '--query=update the kernel'], '-- did not force a prompt: ' . json_encode([$cmd, $args]));
    },
    'launch: -- with nothing after it, or only blank words, is a plain session' => function () {
        list($cmd, $args) = Launch::route(['pikkumies', '--']);
        check($cmd === 'run' && $args === [], '-- alone should be plain run: ' . json_encode($args));
        list($cmd, $args) = Launch::route(['pikkumies', '--', '', ' ']);
        check($cmd === 'run' && $args === [], '-- with only blank words should be plain run: ' . json_encode($args));
    },
    'launch: quoting is never re-split: a word with spaces/quotes/$( ) stays one argv entry, one prompt word' => function () {
        list(, $args) = Launch::route(['pikkumies', 'rm -rf /; echo "hi" $(whoami)']);
        check($args === ['chat', '--query=rm -rf /; echo "hi" $(whoami)'], 'a single argv word was split or evaluated: ' . json_encode($args));
    },
    'launch: install and post-update both start the background documentation fill' => function () {
        $bin = file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        foreach (['install', 'post-update'] as $case) {
            check(preg_match("/\n    case '" . preg_quote($case, '/') . "':(.*?)\n        break;/s", $bin, $m) && strpos($m[1], 'startDocsFill()') !== false, "$case does not start the docs fill");
        }
        check(substr_count($bin, "' docs-fill > '") === 1, 'the docs-fill command line is not in one place');
    },
    'launch: a prompt that starts like a flag stays one --query token' => function () {
        list($cmd, $args) = Launch::route(['pikkumies', '--', '-v', 'means', 'what']);
        check($cmd === 'run' && $args === ['chat', '--query=-v means what'], 'flag-like prompt split: ' . json_encode($args));
    },
    'launch: `tmux` and `tmux N` route to tmux; other words after tmux are a prompt (ADR-0032)' => function () {
        check(Launch::route(['pikkumies', 'tmux']) === ['tmux', []], 'bare tmux');
        check(Launch::route(['pikkumies', 'tmux', '3']) === ['tmux', ['3']], 'tmux 3');
        check(Launch::route(['pikkumies', 'tmux', '12']) === ['tmux', ['12']], 'a number goes to tmux, which says 1-9');
        check(in_array('tmux', Launch::SUBCOMMANDS, true), 'tmux must be a subcommand');
        list($cmd, $args) = Launch::route(['pikkumies', 'tmux', 'kill', 'my', 'session']);
        check($cmd === 'run' && $args === ['chat', '--query=tmux kill my session'], 'tmux with words must stay a prompt: ' . json_encode([$cmd, $args]));
    },
    'launch: tmux plan creates session or window pmN only when missing, then selects and attaches' => function () {
        $cmd = "'php' 'pikkumies'";
        $new = Launch::tmuxPlan('2', null, false, $cmd);
        check($new === [['tmux', 'new-session', '-d', '-s', 'pikkumies', '-n', 'pm2', $cmd], ['tmux', 'select-window', '-t', '=pikkumies:pm2'], ['tmux', 'attach-session', '-t', '=pikkumies']], 'no session: ' . json_encode($new));
        $win = Launch::tmuxPlan('3', ['pm1', 'bash'], false, $cmd);
        check($win[0] === ['tmux', 'new-window', '-d', '-t', '=pikkumies:', '-n', 'pm3', $cmd] && count($win) === 3, 'missing window: ' . json_encode($win));
        $has = Launch::tmuxPlan('1', ['pm1'], true, $cmd);
        check($has === [['tmux', 'select-window', '-t', '=pikkumies:pm1'], ['tmux', 'switch-client', '-t', '=pikkumies']], 'existing window inside tmux: ' . json_encode($has));
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/\n    case 'tmux':(.*?)exit\(\\\$code\);/s", $bin, $m) === 1, 'tmux case missing');
        check(strpos($m[1], 'tmux is not installed') !== false && strpos($m[1], "preg_match('/^[1-9]$/'") !== false, 'tmux case must check tmux and N 1-9');
        check(strpos($m[1], "array_map('escapeshellarg'") !== false, 'tmux commands must be quoted');
    },
    'launch: install flags give every setting and the terms; unknown or bad flags are errors' => function () {
        check(Launch::route(['pikkumies', 'install', '--name=Little Helper', '--accept-terms', '--telemetry=off']) === ['install', ['--name=Little Helper', '--accept-terms', '--telemetry=off']], 'install flags must keep install');
        list($set, $terms, $err) = Launch::installFlags(['--name=Little Helper', '--accept-terms', '--telemetry=off', '--perf-logging=on', '--retention-days=30']);
        check($err === [] && $terms === true, 'errors: ' . json_encode($err));
        check($set === ['agent.name' => 'Little Helper', 'privacy.telemetry' => false, 'privacy.perfLogging' => true, 'sessions.retentionDays' => 30], 'settings: ' . json_encode($set));
        list($set, $terms, $err) = Launch::installFlags(['--telemetry=maybe', '--accept-terms=yes', '--terms-accepted', '--retention-days=-1', '--name=']);
        check(count($err) === 4 && $terms === false, 'bad flags must be errors: ' . json_encode($err));
        check($set === ['agent.name' => ''], 'an empty --name leaves the name unset');
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/bin/pikkumies', ['install', '--bogus']);
        check($code === 2 && strpos($out, 'install stopped, nothing changed') !== false, 'a bad install flag must stop before anything: ' . $out);
    },
    'launch: first start asks only on a terminal, and only interactive sessions' => function () {
        $mem = fopen('php://temp', 'r+');
        check(Launch::isTty($mem) === false, 'a pipe or file is not a terminal');
        fclose($mem);
        check(Launch::isSession([]) && Launch::isSession(['chat', '--query=hi']) && !Launch::isSession(['sessions', 'list']), 'isSession');
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/\n    case 'run':(.*?)\n    case /s", $bin, $m) === 1 && strpos($m[1], 'if (Launch::isTty() && Launch::isSession($passArgs))') !== false, 'run must ask only on a TTY');
        check(preg_match("/\n    case 'install':(.*?)\n        break;/s", $bin, $m) === 1 && strpos($m[1], "!isset(\$installSettings['agent.name']) && Launch::isTty()") !== false, 'install must ask the name only on a TTY and without --name');
        foreach (['cron-tick', 'docs-fill'] as $case) {
            check(preg_match("/\n    case '$case':(.*?)\n    case /s", $bin, $m) === 1 && strpos($m[1], 'askName') === false && strpos($m[1], 'askTerms') === false, "$case must never ask");
        }
    },
    'launch: the name prompt loops until a valid name; Enter keeps Pikkumies; end of input asks nothing more' => function () {
        $feed = function (array $lines) {
            return function () use (&$lines) {
                return $lines ? array_shift($lines) : false;
            };
        };
        $said = '';
        $write = function ($t) use (&$said) {
            $said .= $t;
        };
        check(Launch::askName($feed(["\n"]), $write) === 'Pikkumies', 'Enter keeps Pikkumies');
        check(Launch::askName($feed(["-bad\n", "Helper\n"]), $write) === 'Helper', 'invalid then valid');
        check(Launch::askName($feed([]), $write) === null, 'end of input');
        check(strpos($said, 'letters, digits') !== false, 'invalid name must be explained');
    },
    'launch: terms show both links and accept only yes; no or end of input refuses' => function () {
        $feed = function (array $lines) {
            return function () use (&$lines) {
                return $lines ? array_shift($lines) : false;
            };
        };
        $said = '';
        $write = function ($t) use (&$said) {
            $said .= $t;
        };
        check(Launch::askTerms($feed(["maybe\n", "yes\n"]), $write) === true, 'asks until yes');
        check(strpos($said, 'https://pulsedmedia.com/terms.php') !== false && strpos($said, 'https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Privacy_Policy') !== false, 'both links shown');
        check(Launch::askTerms($feed(["no\n"]), $write) === false, 'no refuses');
        check(Launch::askTerms($feed([]), $write) === false, 'end of input refuses');
        $note = Launch::settingsNote(Config::defaults());
        check(strpos($note, 'performance telemetry on') !== false && strpos($note, 'kept forever') !== false && strpos($note, 'nothing is sent') !== false, 'settings note: ' . $note);
        $off = Launch::settingsNote(Config::merge(Config::defaults(), ['privacy' => ['telemetry' => false], 'sessions' => ['retentionDays' => 30]]));
        check(strpos($off, 'telemetry off') !== false && strpos($off, '30 days') !== false, 'settings note follows the config: ' . $off);
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($bin, 'promptWrite(Launch::settingsNote($firstConfig));') !== false, 'the first interactive start must show the settings');
    },
];
