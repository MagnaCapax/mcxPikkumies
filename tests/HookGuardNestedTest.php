<?php
/**
 * An agent starting another agent with its safety layers off (lib/NestedAgent.php, ADR-0020 amendment 2026-10-18):
 * the guard's block rules for every flag and variable spelling, the depth the launcher exports, the guard turning a
 * question into a block in an agent started by an agent with yolo on, and `pikkumies run` refusing the flags only
 * when started from inside an agent. guardKindTable() is defined in HookGuardContainersTest.php.
 */
if (!function_exists('guardKindTable')) {
    require __DIR__ . '/HookGuardContainersTest.php';
}

if (!function_exists('nestedRun')) {
    /** Run $script with $args and $stdin under the current environment changed by $env (null removes a name): [code, stdout]. */
    function nestedRun($script, array $args, $stdin, array $env)
    {
        $vars = getenv();
        foreach ($env as $k => $v) {
            if ($v === null) {
                unset($vars[$k]);
            } else {
                $vars[$k] = $v;
            }
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $vars);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out];
    }

    /** The guard's decoded answer for one tool call at depth $depth with HERMES_YOLO_MODE $yolo (null: unset), or null. */
    function nestedGuard($tool, array $input, $depth, $yolo)
    {
        list(, $out) = nestedRun(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [],
            json_encode(['tool_name' => $tool, 'tool_input' => $input]), ['PIKKUMIES_DEPTH' => $depth, 'HERMES_YOLO_MODE' => $yolo]);
        $d = json_decode($out, true);
        return is_array($d) ? $d : null;
    }
}

$launchers = ['pikkumies run', 'hermes', '~/.mcxPikkumies/pikkumies/hermes/venv/bin/hermes', 'python3 -m hermes_cli.main', 'python -m hermes_cli'];
// Blocked anywhere after the launcher (-z too: chat has no -z); --oneshot only before Hermes' chat subcommand (chat --oneshot only
// answers and exits).
$flags = ['--yolo', '--yo', '--safe-mode', '--saf', '--ignore-rules', '--ignore-r', '--ignore-user-config', '--ignore-u', '--accept-hooks', '--acc',
    '--profile work', '--profile=work', '-p work'];
$zFlags = ['-z "tidy up"', '-zgo', '"-zdo it now"', '-wz go'];
$topFlags = ['--oneshot "tidy up"', '--oneshot=tidy', '--ones "tidy"'];
$why = 'starts another agent with approvals';
$envWhy = 'switch an agent';
return [
    'nested guard: every launcher spelling with every approval, hook or rule flag is blocked' => function () use ($launchers, $flags, $zFlags, $topFlags, $why) {
        $rows = [];
        foreach ($launchers as $l) {
            foreach ($flags as $f) {
                $rows[] = ["$l $f", 'block', $why];
                $rows[] = ["$l chat $f", 'block', $why];
            }
            foreach ($zFlags as $f) {
                $rows[] = ["$l $f", 'block', $why];
                $rows[] = ["$l chat -q hello $f", 'block', $why];
            }
            foreach ($topFlags as $f) {
                $rows[] = ["$l $f", 'block', $why];
                $rows[] = ["$l -m some-model $f", 'block', $why];
                $rows[] = ["$l chat -q hello $f", 'none'];
            }
        }
        $rows[] = ['hermes profile use work', 'block', $why];
        $rows[] = ['pikkumies run profile use work', 'block', $why];
        $rows[] = ['cd ~/x && "hermes" "--yolo" chat', 'block', $why];
        $rows[] = ['nohup hermes -z "rename the files" > /dev/null 2>&1 &', 'block', $why];
        $rows[] = ['env -i hermes chat -q hi', 'block', $why];
        guardKindTable($rows);
        guardKindTable([['import subprocess; subprocess.run(["hermes", "--yolo", "chat"])', 'block', $why],
            ["import subprocess; subprocess.run(['pikkumies', 'run', '-z', 'go'])", 'block', $why],
            ['from hermes_cli import main; main(["--safe-mode"])', 'block', $why]], 'execute_code');
        $d = nestedGuard('process_manage', ['action' => 'submit', 'session_id' => 's1', 'data' => 'hermes --yolo'], null, null);
        check($d !== null && $d['action'] === 'block' && strpos($d['message'], $why) !== false, 'a background shell starting a yolo agent was not blocked: ' . json_encode($d));
    },
    'nested guard: a chat-looking flag value does not hide a top-level -z or --oneshot; attached -z values are caught' => function () use ($why) {
        $rows = [];
        foreach (['-m', '--usage-file', '-t', '-s', '--resume', '-r', '--model', '--mod', '-c', '--in', '--prov'] as $vf) {
            $rows[] = ["hermes $vf chat -z x", 'block', $why];
            $rows[] = ["hermes $vf chat --oneshot x", 'block', $why];
            $rows[] = ["pikkumies run $vf chat -z x", 'block', $why];
        }
        $rows[] = ['hermes -zgo', 'block', $why];
        $rows[] = ['hermes "-zdo it now"', 'block', $why];
        $rows[] = ['hermes -wm chat --oneshot x', 'block', $why];
        $rows[] = ['hermes -mchat chat -q x --oneshot', 'none'];
        $rows[] = ['hermes --model=chat chat -q x --oneshot', 'none'];
        $rows[] = ['hermes --reasoning high chat -q x --oneshot', 'none'];
        guardKindTable($rows);
    },
    'nested guard: pikkumies without run is a prompt, so its words are never read as Hermes flags' => function () {
        guardKindTable([
            ['pikkumies -z go', 'none'],
            ['pikkumies -- find files with -z flag', 'none'],
            ['pikkumies "summarize -z option of tar"', 'none'],
            ['pikkumies why does --yolo exist', 'none'],
            ['pikkumies -m some-model explain --oneshot', 'none'],
        ]);
    },
    'nested guard: setting the switch variables, or changing or removing HERMES_HOME or PIKKUMIES_DEPTH, is blocked' => function () use ($envWhy) {
        $rows = [];
        foreach (NestedAgent::SWITCHES as $name) {
            $rows[] = ["$name=1 hermes chat -q hi", 'block', $envWhy];
            $rows[] = ["export $name=1", 'block', $envWhy];
            $rows[] = ["env $name=true pikkumies run chat -q hi", 'block', $envWhy];
        }
        foreach (NestedAgent::HOMES as $name) {
            $rows[] = ["$name=/tmp/h hermes", 'block', $envWhy];
            $rows[] = ["unset $name; hermes chat -q hi", 'block', $envWhy];
            $rows[] = ["env -u $name pikkumies run chat -q hi", 'block', $envWhy];
            $rows[] = ["env --unset=$name pikkumies", 'block', $envWhy];
        }
        guardKindTable($rows);
        $code = [];
        foreach (NestedAgent::SWITCHES as $name) {
            $code[] = ["import os; os.environ[\"$name\"] = \"1\"", 'block', $envWhy];
            $code[] = ["import os, subprocess; subprocess.run(['x'], env={**os.environ, '$name': '1'})", 'block', $envWhy];
            $code[] = ["import os; os.putenv(\"$name\", \"1\")", 'block', $envWhy];
            $code[] = ["import os; os.environ.update($name='1')", 'block', $envWhy];
        }
        $code[] = ['import os; os.environ.pop("PIKKUMIES_DEPTH")', 'block', $envWhy];
        $code[] = ['import os; del os.environ["HERMES_HOME"]', 'block', $envWhy];
        guardKindTable($code, 'execute_code');
    },
    'nested guard: an approvals mode write, and /yolo or /approvals off|smart typed into a session, are blocked' => function () use ($envWhy) {
        guardKindTable([
            ['hermes config set approvals.mode off', 'block', $envWhy],
            ['pikkumies run config set approvals.cron_mode approve', 'block', $envWhy],
            ['python3 -m hermes_cli.main config set "approvals.single_query_mode" approve', 'block', $envWhy],
            ["yq -i '.approvals.mode = \"off\"' ~/pikkumies/local/hermes/config.yaml", 'block', $envWhy],
            ['hermes chat -q "/yolo"', 'block', $envWhy],
            ['hermes chat --query=/approvals off', 'block', $envWhy],
            ['hermes config set display.skin mono', 'none'],
            ['grep -n approvals.mode ~/pikkumies/local/hermes/config.yaml', 'none'],
        ]);
        foreach (['/yolo', "/approvals off\n", '/approvals smart', "hello\n/yolo"] as $data) {
            $d = nestedGuard('process_manage', ['action' => 'write', 'session_id' => 's1', 'data' => $data], null, null);
            check($d !== null && $d['action'] === 'block' && strpos($d['message'], $envWhy) !== false, 'typed into a session, not blocked: ' . json_encode($data));
        }
        foreach (['/approvals manual', '/help', 'ls /srv/yolo-backups'] as $data) {
            check(nestedGuard('process_manage', ['action' => 'write', 'session_id' => 's1', 'data' => $data], null, null) === null, "blocked: $data");
        }
    },
    'nested guard: -z or -p options of other commands, and reading the variables, are not mistaken for an agent start' => function () {
        guardKindTable([
            ['cd ~/pikkumies && tar -zxf x.tgz', 'none'],
            ['cd ~/pikkumies && tar -xf x.tgz -z', 'none'],
            ['cd ~/pikkumies; gzip -dc a.gz | sort -z', 'none'],
            ['cd ~/pikkumies && mkdir -p local/notes', 'none'],
            ['grep -r HERMES_HOME= .', 'none'],
            ['grep -rn "HERMES_YOLO_MODE=" ~/pikkumies/docs', 'none'],
            ['env | grep ^PIKKUMIES_DEPTH=', 'none'],
            ['printenv | grep -E "^(HERMES_HOME|PIKKUMIES_DEPTH)="', 'none'],
            ['hermes chat -q "x" --oneshot', 'none'],
            ['pikkumies run chat -q "x" --oneshot', 'none'],
        ]);
    },
    'nested guard: ordinary agent and tool commands, reading the variables, and look-alike flags get no answer' => function () {
        guardKindTable([
            ['pikkumies run chat -q "summarise the logs"', 'none'],
            ['pikkumies doctor', 'none'],
            ['which hermes', 'none'],
            ['hermes --help', 'none'],
            ['hermes sessions list', 'none'],
            ['php ~/pikkumies/tools/arr/autobrr/autobrr.php toggle 3 --on', 'none'],
            ['php ~/.mcxPikkumies/pikkumies/tools/system/credits/credits.php line --tmux', 'none'],
            ['cd ~/pikkumies && tar -xzf backup.tgz', 'none'],
            ['grep -z needle haystack', 'none'],
            ['echo $HERMES_YOLO_MODE', 'none'],
            ['[ "$HERMES_YOLO_MODE" = 1 ] && echo on', 'none'],
            ['ls $HERMES_HOME/skills', 'none'],
            ['echo depth $PIKKUMIES_DEPTH', 'none'],
            ['git log --oneline', 'none'],
        ]);
        guardKindTable([['import os; print(os.environ.get("HERMES_HOME", ""))', 'none'],
            ['import os; print(os.environ.get("PIKKUMIES_DEPTH"))', 'none']], 'execute_code');
    },
    'nested guard: an agent started by an agent blocks what it would ask, yolo or not; at the top level the question stays' => function () {
        $rm = ['command' => 'rm ~/old.log'];
        foreach ([['2', '1'], ['3', 'true'], ['2', null], ['2', '0'], ['9', null]] as $c) {
            $d = nestedGuard('terminal', $rm, $c[0], $c[1]);
            check($d !== null && $d['action'] === 'block' && strpos($d['message'], 'started by another agent') !== false
                && strpos($d['message'], 'rm ~/old.log') !== false, 'depth ' . $c[0] . ' yolo ' . $c[1] . ' did not block: ' . json_encode($d));
        }
        foreach ([[null, null], [null, '1'], ['1', '1'], ['1', null], ['0', '1']] as $c) {
            $d = nestedGuard('terminal', $rm, $c[0], $c[1]);
            check($d !== null && $d['action'] === 'approve', 'depth ' . var_export($c[0], true) . ' yolo ' . var_export($c[1], true) . ' did not ask: ' . json_encode($d));
        }
        $job = ['action' => 'create', 'schedule' => '0 3 * * *', 'script' => 'rm ~/x'];
        $d = nestedGuard('cronjob_manage', $job, '2', null);
        check($d !== null && $d['action'] === 'block', 'a nested agent could schedule a script: ' . json_encode($d));
        check(nestedGuard('cronjob_manage', $job, '1', '1')['action'] === 'approve', 'the top-level schedule question changed');
        $d = nestedGuard('terminal', ['command' => 'rm -rf ~/a'], '2', '1');
        check($d !== null && $d['action'] === 'block' && strpos($d['message'], 'recursive force delete') !== false, 'a block rule changed in a nested agent: ' . json_encode($d));
        check(nestedGuard('terminal', ['command' => 'ls ~'], '2', '1') === null, 'an ordinary command got an answer in a nested agent');
    },
    'nested agent: depth parsing, the exported child depth and the nested test' => function () {
        foreach (['' => 0, '0' => 0, '1' => 1, ' 2 ' => 2, 'x' => 1, '-1' => 1, '100000' => 99] as $raw => $want) {
            check(NestedAgent::depth((string) $raw) === $want, "depth('$raw') is " . NestedAgent::depth((string) $raw) . ", want $want");
        }
        foreach (['' => 1, '1' => 2, '5' => 6, '99' => 99, 'junk' => 2] as $raw => $want) {
            check(NestedAgent::childDepth((string) $raw) === $want, "childDepth('$raw') is " . NestedAgent::childDepth((string) $raw));
        }
        check(NestedAgent::unattended('2') && NestedAgent::unattended('4') && NestedAgent::unattended('7'), 'depth 2 or more not seen');
        check(!NestedAgent::unattended('1') && !NestedAgent::unattended('') && !NestedAgent::unattended('0'), 'depth 0 or 1 counted as nested');
    },
    'nested agent: `pikkumies run` refuses the flags only when started from inside an agent' => function () {
        foreach ([['--yolo'], ['--yo'], ['-z', 'go'], ['-m', 'some-model', '--oneshot', 'go'], ['-zhi'], ['-wz', 'go'], ['--oneshot=go'], ['chat', '--safe-mode'], ['--sa'], ['--ignore-rules'], ['chat', '-q', 'x', '--ignore-user-config'], ['--accept-hooks'], ['--profile=work'], ['chat', '-p', 'work'],
            ['profile', 'use', 'work'], ['config', 'set', 'approvals.mode', 'off'], ['-m', 'chat', '-z', 'go'], ['--usage-file', 'chat', '--oneshot', 'x'],
            ['-s', 'chat', '-z'], ['--resume', 'chat', '-z'], ['-t', 'chat', '-z'], ['--mod', 'chat', '--oneshot', 'x'], ['-c', 'chat', '--oneshot', 'x'],
            ['chat', '-z'], ['chat', '-q', '-z this one'], ['chat', '-q', 'x', '-zdo it now'], ['-wm', 'chat', '--oneshot', 'x']] as $args) {
            check(NestedAgent::refusal($args, '') === '', 'top-level run refused: ' . json_encode($args));
            $why = NestedAgent::refusal($args, '1');
            check($why !== '' && strpos($why, 'unset PIKKUMIES_DEPTH') !== false, 'nested run not refused: ' . json_encode($args));
        }
        foreach ([[], ['chat', '--query=--yolo please'], ['sessions', 'list'], ['chat', '-s', 'pikkumies-configure'], ['--yes'], ['chat', '-q', 'x', '--oneshot'], ['-mchat', 'chat', '--oneshot'], ['--model=chat', 'chat', '-q', 'x', '--oneshot'], ['profile', 'list'],
            ['config', 'set', 'display.skin', 'mono']] as $args) {
            check(NestedAgent::refusal($args, '3') === '', 'nested run refused without a flag: ' . json_encode($args));
        }
    },
    'nested agent: bin/pikkumies refuses before anything starts, and exports the depth to every Hermes it starts' => function () {
        $file = dirname(__DIR__) . '/pikkumies/bin/pikkumies';
        list($code, $out) = nestedRun($file, ['run', '--yolo'], '', ['PIKKUMIES_DEPTH' => '1']);
        check($code === 2 && strpos($out, 'Not starting') !== false && strpos($out, '--yolo') !== false, "nested --yolo run not refused ($code): $out");
        $bin = (string) file_get_contents($file);
        $run = strpos($bin, "    case 'run':");
        $refuse = strpos($bin, 'NestedAgent::refusal($passArgs)', (int) $run);
        check($run !== false && $refuse !== false && $refuse < strpos($bin, 'hermesBin()', $run), 'the refusal does not come first in run');
        // HERMES_HOME and HERMES_MANAGED_DIR come from HermesConfig::env() alone (ADR-0062), the depth right after.
        $homes = substr_count($bin, 'foreach (HermesConfig::env() as $envName => $envValue)');
        check($homes === 2 && strpos($bin, "putenv('HERMES_HOME") === false && preg_match_all('/foreach \(HermesConfig::env\(\) as \$envName => \$envValue\) \{[^\n]*\n\s*putenv\(\$envValue === null \? \$envName : "\$envName=\$envValue"\);\s*\}\s*putenv\(\'PIKKUMIES_DEPTH=\' \. NestedAgent::childDepth\(\)\);/', $bin) === 2,
            'run and cron-tick do not both export PIKKUMIES_DEPTH where they set the Hermes environment');
    },
];
