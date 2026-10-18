<?php
/** lib/Launch.php: argv routing for bin/pikkumies (ADR-0022). */
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureSettings.php';
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
        check(Launch::missingKeyMessage(['endpoint' => 'https://e/v1', 'key' => 'k', 'model' => '']) === '', 'a key present: no message');
        $noKey = Launch::missingKeyMessage(['endpoint' => 'https://e/v1', 'key' => '', 'model' => '']);
        check(strpos($noKey, 'Not starting') === 0 && strpos($noKey, '~/.virsilipasKey') !== false && strpos($noKey, '~/.pikkumiesKey') !== false, 'no key: says where it comes from: ' . $noKey);
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/\n    case 'run':(.*?)\n    case /s", $bin, $m) === 1 && strpos($m[1], 'if (Launch::isTty() && Launch::isSession($passArgs))') !== false, 'run must ask only on a TTY');
        check(strpos($m[1], 'Launch::isSession($passArgs) && ($noKey = Launch::missingKeyMessage(Provider::resolve(Config::load()))) !== \'\'') !== false
            && strpos($m[1], 'missingKeyMessage') < strpos($m[1], 'HermesInstall::lock('), 'run must stop a session without a key before Hermes starts');
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
        check(strpos($note, 'performance telemetry on (a setting only: nothing is sent)') !== false && strpos($note, 'kept forever') !== false, 'settings note: ' . $note);
        // shown before the terms are accepted: it must say what performance logging keeps, never that nothing is logged (ADR-0041)
        check(strpos($note, 'performance logging on (when on, it keeps on this machine only, never sent: the words of memory searches, result counts and lesson saves') !== false
            && stripos($note, 'logged') === false, 'settings note must say what is kept: ' . $note);
        $off = Launch::settingsNote(Config::merge(Config::defaults(), ['privacy' => ['telemetry' => false], 'sessions' => ['retentionDays' => 30]]));
        check(strpos($off, 'telemetry off') !== false && strpos($off, '30 days') !== false, 'settings note follows the config: ' . $off);
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($bin, 'promptWrite(Launch::settingsNote($firstConfig));') !== false, 'the first interactive start must show the settings');
    },
    'launch: `configure` alone routes to configure; `configure my plex` stays a prompt (ADR-0052)' => function () {
        check(Launch::route(['pikkumies', 'configure']) === ['configure', []], 'bare configure');
        check(in_array('configure', Launch::SUBCOMMANDS, true), 'configure must be a subcommand');
        list($cmd, $args) = Launch::route(['pikkumies', 'configure', 'my', 'plex']);
        check($cmd === 'run' && $args === ['chat', '--query=configure my plex'], 'configure with words must stay a prompt: ' . json_encode([$cmd, $args]));
    },
    'launch: `configure undo` is the undo subcommand; other words after configure, or `--` first, stay a prompt (ADR-0059)' => function () {
        check(Launch::route(['pikkumies', 'configure', 'undo']) === ['configure', ['undo']], 'configure undo');
        check(Launch::route(['pikkumies', 'configure', 'undo', 'it']) === ['run', ['chat', '--query=configure undo it']], 'more words: a prompt');
        check(Launch::route(['pikkumies', '--', 'configure', 'undo']) === ['run', ['chat', '--query=configure undo']], '-- first: a prompt');
        check(Launch::route(['pikkumies', 'undo']) === ['run', ['chat', '--query=undo']], 'undo alone is a prompt');
    },
    'launch: `pikkumies configure undo` from a shell with no terminal makes a free change, refuses an asked one, and starts no session (a throwaway tree)' => function () {
        $tmp = realpath(testTempDir('pk-launch-undo'));
        try {
            @mkdir("$tmp/pikkumies/local/state", 0700, true);
            foreach (['bin', 'lib', 'tools'] as $d) {
                exec('cp -R ' . escapeshellarg(dirname(__DIR__) . "/pikkumies/$d") . ' ' . escapeshellarg("$tmp/pikkumies/$d"));
            }
            file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export(['lowBalance' => ['disabled' => true]], true) . ";\n");
            file_put_contents("$tmp/pikkumies/local/state/configure-changes.jsonl", json_encode(['key' => 'lowBalance.disabled', 'old' => false, 'new' => true]) . "\n");
            // PATH without git: should the routing ever regress into a session, it cannot start building Hermes in the background.
            $undo = function () use ($tmp) {
                return testWithEnv(['PIKKUMIES_CONFIG' => null, 'PIKKUMIES_DEPTH' => null, 'PATH' => dirname(PHP_BINARY)], function () use ($tmp) {
                    return runPhp("$tmp/pikkumies/bin/pikkumies", ['configure', 'undo']);
                });
            };
            list($code, $out) = $undo();
            $local = require "$tmp/pikkumies/local/config.php";
            check($code === 0 && $local['lowBalance']['disabled'] === false && strpos($out, 'lowBalance.disabled true -> false (free)') !== false, "undo: $code $out " . json_encode($local));
            // A forged asked change (a one-day history) needs the person's answer: without a terminal it is refused.
            file_put_contents("$tmp/pikkumies/local/state/configure-changes.jsonl", json_encode(['key' => 'sessions.retentionDays', 'old' => 1, 'new' => 0]) . "\n");
            list($code, $out) = $undo();
            check($code === 1 && strpos($out, 'no terminal') !== false && !isset((require "$tmp/pikkumies/local/config.php")['sessions']), "asked, no terminal: $code $out");
            check(!is_dir("$tmp/pikkumies/local/hermes"), 'undo must start no session and generate nothing');
        } finally {
            testRmTree($tmp);
        }
    },
    'launch: configure without a terminal prints the settings review and starts nothing; on one it runs a session with the configure skill' => function () {
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/bin/pikkumies', ['configure']);
        check($code === 0 && strpos($out, 'sessions.retentionDays') !== false && strpos($out, 'terms.accepted') !== false, 'no TTY: the review must print, exit 0: ' . $code . ' ' . $out);
        check(strpos($out, 'Not starting') === false && strpos($out, 'runtime is not ready') === false && strpos($out, 'restored in your crontab') === false
            && preg_match('/\nok, ' . count(ConfigureSettings::INFO) . ' shown\n$/', $out) === 1, 'no TTY: only the review, every setting, nothing may start: ' . $out);
        check(Launch::CONFIGURE_ARGS[0] === 'chat' && array_slice(Launch::CONFIGURE_ARGS, 1, 2) === ['-s', 'pikkumies-configure'] && Launch::isSession(Launch::CONFIGURE_ARGS), 'configure args: ' . json_encode(Launch::CONFIGURE_ARGS));
        check(strpos((string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/skill.md'), "\nname: pikkumies-configure\n") !== false, 'the preloaded skill name must be the unit\'s');
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/\n    case 'configure':(.*?)\n    case 'run':/s", $bin, $m) === 1, "configure must sit right before case 'run'");
        check(strpos($m[1], 'if (!Launch::isTty(STDIN) || !Launch::isTty(STDOUT))') !== false && strpos($m[1], ' review --human') !== false, 'configure must print the review without a terminal');
        $tail = (string) strstr($m[1], '$passArgs = Launch::CONFIGURE_ARGS;');
        check($tail !== '' && strpos($tail, 'break;') === false && strpos($tail, 'exit(') === false, 'configure on a terminal must fall through into run, with its checks: ' . $tail);
    },
    'launch: first start ends with the review warnings and one line on how to configure, never a session of its own' => function () {
        $hint = Launch::setupHint([['key' => 'agent.name', 'value' => 'K'], ['key' => 'sessions.retentionDays', 'value' => 0, 'warning' => 'kept forever']]);
        check($hint === "  sessions.retentionDays: kept forever\nRun `pikkumies configure` or ask me to review your setup.\n", 'hint: ' . json_encode($hint));
        check(Launch::setupHint([]) === "Run `pikkumies configure` or ask me to review your setup.\n", 'no warnings: just the line');
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($bin, "Config::acceptTerms();\n                    // What the settings review warns about") !== false && strpos($bin, 'promptWrite(Launch::setupHint(ConfigureSettings::review(') !== false, 'the hint must follow the accepted terms');
    },
    'launch: the start banner gets the model given with -m, so its token floor is for that model' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $set = strpos($bin, "if ((\$runModel = RunFlags::model(\$passArgs)) !== '') {\n                \$creditsProv['model'] = \$runModel;");
        $use = strpos($bin, 'CreditsLine::text($creditsProv, ');
        check($set !== false && $use !== false && $set < $use, 'the banner must use the -m model');
        check(RunFlags::model(Launch::route(['pikkumies', '-m', 'X', 'hi'])[1]) === 'X', 'the model the banner reads is the routed one');
    },
];
