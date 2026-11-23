<?php
/**
 * End-to-end use of the `hermes` resume entry (bin/hermes, ADR-0022 amendment 2026-11-23): a person in a shell on an account
 * types what Hermes printed at the end of a session. A copy of the runtime is installed in a throwaway HOME, the launcher is
 * real, Hermes is a stand-in that logs the words it was started with, and the provider is a local server that logs every
 * request, so "no model request" is read from its log. Every case prints a CASE block with what it saw.
 */
if (!function_exists('coProviderStart')) {
    require __DIR__ . '/CoreTest.php';
}

/** [dir, home, provider process, provider base, provider dir]: the runtime copy with its stand-in Hermes, and an empty HOME beside it. */
function hsEWorld()
{
    $dir = testTempDir('hs-e2e');
    $src = dirname(__DIR__) . '/pikkumies';
    foreach (['bin', 'lib', 'tools', 'hooks', 'doctrine', 'config'] as $part) {
        exec('cp -R ' . escapeshellarg("$src/$part") . ' ' . escapeshellarg("$dir/$part"), $o, $code);
        check($code === 0, "could not copy $part");
    }
    foreach (["hermes/.hermes/bin", 'include', 'local', 'home/.local/bin', 'phpbin'] as $d) {
        mkdir("$dir/$d", 0700, true);
    }
    symlink(PHP_BINARY, "$dir/phpbin/php");
    exec('git -C ' . escapeshellarg("$dir/hermes") . ' init -q 2>&1', $o, $code);
    exec('git -C ' . escapeshellarg("$dir/hermes") . ' -c user.name=t -c user.email=t@example.invalid commit -q --allow-empty -m fixture 2>&1', $o, $code2);
    check($code === 0 && $code2 === 0, 'could not make the stand-in Hermes checkout: ' . implode(' ', $o));
    file_put_contents("$dir/include/.hermes-ready", 'fixture');
    file_put_contents("$dir/hermes/.hermes/bin/hermes", '#!' . PHP_BINARY . "\n<?php file_put_contents(" . var_export("$dir/calls.jsonl", true)
        . ", json_encode(array_slice(\$argv, 1)) . \"\\n\", FILE_APPEND);\n");
    chmod("$dir/hermes/.hermes/bin/hermes", 0700);
    file_put_contents("$dir/local/config.php", '<?php return ' . var_export([
        'autoUpdate' => ['disabled' => true], 'cron' => ['disabled' => true], 'halt' => ['disabled' => true],
        'terms' => ['accepted' => true], 'provider' => ['keyFile' => '', 'virsilipasKeyFile' => '', 'model' => 'fixture'],
    ], true) . ';');
    // The stand-in is no real Hermes checkout: record it as verified from a process with the launcher's own environment, so the start check passes as it does after a real check.
    $code = 'foreach (glob($argv[1] . "/lib/*.php") as $l) { require_once $l; } HermesInstall::markVerified(HermesInstall::head());';
    exec('HOME=' . escapeshellarg("$dir/home") . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($dir) . ' 2>&1', $o, $code3);
    check($code3 === 0 && is_file("$dir/local/hermes-verified.json"), 'could not record the stand-in as verified: ' . implode(' ', $o));
    list($proc, $base, $serverDir) = coProviderStart('
file_put_contents(PK_DIR . "/requests", $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND);
if (substr($_SERVER["REQUEST_URI"], -7) === "/models") { echo "{\"data\": [{\"id\": \"fixture\"}]}"; return; }
http_response_code(404); echo "{}";
');
    return ['dir' => $dir, 'home' => "$dir/home", 'proc' => $proc, 'base' => $base, 'server' => $serverDir];
}

function hsEDone(array $w)
{
    coProviderServerDone($w['proc'], $w['server']);
    testRmTree($w['dir']);
}

/** The account's own `pikkumies` link in ~/.local/bin, as an earlier install left it. */
function hsEInstalled(array $w)
{
    symlink("{$w['dir']}", "{$w['home']}/pikkumies");
    symlink("{$w['home']}/pikkumies/bin/pikkumies", "{$w['home']}/.local/bin/pikkumies");
}

/** Runs $cmd in a shell of the account; with $pty on a terminal, as a person types it. [exit code, output, stderr]. */
function hsERun(array $w, $cmd, $pty = false, array $env = [])
{
    $e = array_merge(getenv(), [
        'HOME' => $w['home'], 'PATH' => "{$w['home']}/.local/bin:{$w['dir']}/phpbin:" . getenv('PATH'),
        'PIKKUMIES_ENDPOINT' => $w['base'], 'PIKKUMIES_KEY' => 'fixture-key', 'PIKKUMIES_DOCS_FILL_NOW' => '1', 'PIKKUMIES_SKIP_HERMES' => '1',
        'TERM' => 'dumb',
    ]);
    unset($e['PIKKUMIES_CONFIG'], $e['PIKKUMIES_DEPTH'], $e['PIKKUMIES_RUN']);
    $e = array_merge($e, $env);
    $full = $pty ? 'script -qec ' . escapeshellarg($cmd) . ' /dev/null' : $cmd;
    $p = proc_open('sh -c ' . escapeshellarg($full), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $w['home'], $e);
    fwrite($pipes[0], $pty ? "\n\n" : '');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

/** The word lists the stand-in Hermes was started with, in order. */
function hsECalls(array $w)
{
    $calls = [];
    foreach (file("{$w['dir']}/calls.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $words = json_decode($line, true);
        if ($words[0] !== 'pm') { // `pm gc` is the daily update's housekeeping on the tree, not a session
            $calls[] = $words;
        }
    }
    return $calls;
}

/** The provider's request log; the stand-in never calls a model, so a chat or completion request would show here. */
function hsEModelRequests(array $w)
{
    $log = (string) @file_get_contents("{$w['server']}/requests");
    return preg_match_all('#/(chat|completions|responses|messages)#', $log);
}

/** True when $words contains $flag directly followed by $value. */
function hsEHas(array $words, $flag, $value)
{
    $i = array_search($flag, $words, true);
    return $i !== false && isset($words[$i + 1]) && $words[$i + 1] === $value;
}

/** Prints the case block the manifest evidence is made from, then runs $fn (which returns the observed text). */
function hsECase($id, $journey, callable $fn)
{
    $w = hsEWorld();
    echo "=== CASE $id\njourney: $journey\n";
    try {
        $observed = $fn($w);
        echo "observed: $observed\n=== PASS $id\n";
    } catch (Exception $x) {
        echo "observed: FAILED: " . $x->getMessage() . "\n=== FAIL $id\n";
        throw $x;
    } finally {
        hsEDone($w);
    }
}

/** Resume forms typed on a terminal; checks the stand-in Hermes got the right session words and nothing spent credits. */
function hsEResumeCase($id, $typed, callable $accept, $label)
{
    hsECase($id, "a customer finishes a session and types the printed command: $label", function ($w) use ($typed, $accept) {
        hsEInstalled($w);
        list($code, $out) = hsERun($w, 'pikkumies post-update >/dev/null 2>&1; ' . $typed, true);
        $calls = hsECalls($w);
        check(is_link("{$w['home']}/.local/bin/hermes"), 'the entry is not on PATH after the update');
        check($code === 0 && count($calls) === 1 && $accept($calls[0]), "exit $code, Hermes was started with " . json_encode($calls) . " output " . json_encode($out));
        check(hsEModelRequests($w) === 0, 'a model request was made');
        return "typed `$typed` -> exit $code, Hermes started once with " . json_encode($calls[0]) . '; model requests 0';
    });
}

$hsEId = 'S20261123_101010_abc123';
$hsETitle = 'my backup session';

return [
    'hermes e2e UC01: hermes --resume <id> on a terminal continues that session' => function () use ($hsEId) {
        hsEResumeCase('UC01', "hermes --resume $hsEId", function ($c) use ($hsEId) {
            return $c[0] === 'chat' && hsEHas($c, '--resume', $hsEId);
        }, 'hermes --resume');
    },
    'hermes e2e UC02: hermes -c "<title>" on a terminal continues the session by title' => function () use ($hsETitle) {
        hsEResumeCase('UC02', 'hermes -c ' . escapeshellarg($hsETitle), function ($c) use ($hsETitle) {
            return $c[0] === 'chat' && hsEHas($c, '-c', $hsETitle);
        }, 'hermes -c with a title');
    },
    'hermes e2e UC03: hermes --tui --resume <id> opens the terminal UI on that session' => function () use ($hsEId) {
        hsEResumeCase('UC03', "hermes --tui --resume $hsEId", function ($c) use ($hsEId) {
            return in_array('--tui', $c, true) && hsEHas($c, '--resume', $hsEId);
        }, 'hermes --tui --resume');
    },
    'hermes e2e UC04: hermes --tui -c "<title>" opens the terminal UI on the titled session' => function () use ($hsETitle) {
        hsEResumeCase('UC04', 'hermes --tui -c ' . escapeshellarg($hsETitle), function ($c) use ($hsETitle) {
            return in_array('--tui', $c, true) && hsEHas($c, '-c', $hsETitle);
        }, 'hermes --tui -c with a title');
    },
    'hermes e2e UC05: a title with quotes, $() and backticks reaches Hermes as one word and runs nothing' => function () {
        hsECase('UC05', 'a customer resumes a session whose title holds shell syntax', function ($w) {
            hsEInstalled($w);
            $title = 'say "hi" $(touch ' . $w['dir'] . '/pwned) `touch ' . $w['dir'] . '/pwned2` ; end';
            list($code) = hsERun($w, 'pikkumies post-update >/dev/null 2>&1; hermes -c ' . escapeshellarg($title), true);
            $calls = hsECalls($w);
            check($code === 0 && count($calls) === 1 && hsEHas($calls[0], '-c', $title), "exit $code, got " . json_encode($calls));
            check(!file_exists("{$w['dir']}/pwned") && !file_exists("{$w['dir']}/pwned2"), 'the title was run by a shell');
            return 'title reached Hermes as one word, nothing created; Hermes words ' . json_encode($calls[0]);
        });
    },
    'hermes e2e UC06: the resume form from a script with no terminal (ssh command, cron) still continues the session' => function () use ($hsEId) {
        hsECase('UC06', 'a script runs the printed resume command with no terminal', function ($w) use ($hsEId) {
            hsEInstalled($w);
            list($code, $out, $err) = hsERun($w, "pikkumies post-update >/dev/null 2>&1; hermes --resume $hsEId </dev/null");
            $calls = hsECalls($w);
            check(count($calls) >= 1 && $calls[0][0] === 'chat' && hsEHas($calls[0], '--resume', $hsEId), "exit $code, got " . json_encode($calls) . " $err");
            return "exit $code, Hermes started " . count($calls) . ' time(s), first with ' . json_encode($calls[0]);
        });
    },
    'hermes e2e UC07: every other Hermes command is refused with one line naming pikkumies and starts nothing' => function () {
        hsECase('UC07', 'a customer types other Hermes commands from habit or a script', function ($w) {
            hsEInstalled($w);
            hsERun($w, 'pikkumies post-update >/dev/null 2>&1');
            $seen = [];
            foreach (['hermes sessions list', 'hermes update', 'hermes gateway run', 'hermes', 'hermes chat -q hello', 'hermes -- --resume S1'] as $cmd) {
                list($code, $out, $err) = hsERun($w, $cmd);
                check($code === 2 && substr_count(trim($err), "\n") === 0 && strpos($err, 'pikkumies') !== false && trim($out) === '', "$cmd: exit $code out " . json_encode($out) . ' err ' . json_encode($err));
                $seen[] = "`$cmd` -> exit 2";
            }
            check(hsECalls($w) === [], 'Hermes was started: ' . json_encode(hsECalls($w)));
            check(hsEModelRequests($w) === 0, 'a model request was made');
            return implode('; ', $seen) . '; Hermes started 0 times; model requests 0';
        });
    },
    'hermes e2e UC08: ids and titles that look like options, extra words and a profile suffix are refused' => function () {
        hsECase('UC08', 'a customer or a script passes option-shaped or extended resume words', function ($w) {
            hsEInstalled($w);
            hsERun($w, 'pikkumies post-update >/dev/null 2>&1');
            $seen = [];
            foreach (['hermes --resume -x', 'hermes -c -x', "hermes --resume ''", "hermes --resume 'a b'", 'hermes --resume S1 extra',
                'hermes --resume S1 -p work', 'hermes -p work --resume S1', 'hermes --tui --tui -c t'] as $cmd) {
                list($code, $out, $err) = hsERun($w, $cmd);
                check($code === 2 && strpos($err, 'pikkumies') !== false, "$cmd: exit $code out " . json_encode($out) . ' err ' . json_encode($err));
                $seen[] = "`$cmd` -> exit 2";
            }
            check(hsECalls($w) === [] && hsEModelRequests($w) === 0, 'Hermes started or a model was asked');
            return implode('; ', $seen) . '; Hermes started 0 times; model requests 0';
        });
    },
    'hermes e2e UC09: bare -c, --continue, --tui and --resume with no id are refused' => function () {
        hsECase('UC09', 'a customer types the short forms Hermes never printed', function ($w) {
            hsEInstalled($w);
            hsERun($w, 'pikkumies post-update >/dev/null 2>&1');
            $seen = [];
            foreach (['hermes -c', 'hermes --continue', 'hermes --tui', 'hermes --resume', 'hermes --tui --resume'] as $cmd) {
                list($code, , $err) = hsERun($w, $cmd);
                check($code === 2 && strpos($err, 'pikkumies') !== false, "$cmd: exit $code err " . json_encode($err));
                $seen[] = "`$cmd` -> exit 2";
            }
            check(hsECalls($w) === [], 'Hermes was started');
            return implode('; ', $seen) . '; Hermes started 0 times';
        });
    },
    'hermes e2e UC10: an account installed before the entry existed gets it from the next update, and it works' => function () use ($hsEId) {
        hsECase('UC10', 'the daily update runs on an existing install without a hermes entry', function ($w) use ($hsEId) {
            hsEInstalled($w);
            check(!file_exists("{$w['home']}/.local/bin/hermes") && is_link("{$w['home']}/.local/bin/pikkumies"), 'setup');
            list($code, $out) = hsERun($w, 'pikkumies post-update');
            check(strpos($out, 'hermes resume entry: ') !== false && strpos($out, 'linked ') !== false, "post-update said: $out");
            check(readlink("{$w['home']}/.local/bin/hermes") === "{$w['home']}/pikkumies/bin/hermes", 'link target ' . readlink("{$w['home']}/.local/bin/hermes"));
            list(, $out2) = hsERun($w, 'pikkumies post-update');
            check(strpos($out2, 'already linked') !== false, "second update said: $out2");
            check(!file_exists("{$w['home']}/.bashrc") && !file_exists("{$w['home']}/.profile"), 'a shell file was written');
            list($c) = hsERun($w, "hermes --resume $hsEId", true);
            check($c === 0 && count(hsECalls($w)) === 1, "resume after the update: exit $c " . json_encode(hsECalls($w)));
            return 'post-update linked ~/.local/bin/hermes -> ~/pikkumies/bin/hermes, second update already linked, no rc file written, resume worked';
        });
    },
    'hermes e2e UC11: an account with its own hermes link keeps it, and the update says so' => function () {
        hsECase('UC11', 'a customer has their own hermes symlink in ~/.local/bin', function ($w) {
            hsEInstalled($w);
            file_put_contents("{$w['dir']}/own-hermes", "#!/bin/sh\necho own hermes\n");
            chmod("{$w['dir']}/own-hermes", 0700);
            symlink("{$w['dir']}/own-hermes", "{$w['home']}/.local/bin/hermes");
            list($code, $out) = hsERun($w, 'pikkumies post-update');
            check(strpos($out, 'links elsewhere; left alone') !== false, "post-update said: $out");
            check(readlink("{$w['home']}/.local/bin/hermes") === "{$w['dir']}/own-hermes", 'the link was retargeted');
            list(, $ran) = hsERun($w, 'hermes');
            check(trim($ran) === 'own hermes', 'the own command no longer runs: ' . $ran);
            return 'link still points at their own script, post-update printed "links elsewhere; left alone", `hermes` still runs their script';
        });
    },
    'hermes e2e UC12: a real file named hermes in ~/.local/bin is never overwritten' => function () {
        hsECase('UC12', 'a customer keeps a script called hermes in ~/.local/bin', function ($w) {
            hsEInstalled($w);
            file_put_contents("{$w['home']}/.local/bin/hermes", "#!/bin/sh\necho own script\n");
            chmod("{$w['home']}/.local/bin/hermes", 0700);
            list($code, $out) = hsERun($w, 'pikkumies post-update');
            check(strpos($out, 'exists and is not a symlink; left alone') !== false, "post-update said: $out");
            list(, $ran) = hsERun($w, 'hermes');
            check(!is_link("{$w['home']}/.local/bin/hermes") && trim($ran) === 'own script', 'the file changed: ' . $ran);
            return 'file untouched, post-update printed "exists and is not a symlink; left alone", `hermes` still runs their script';
        });
    },
    'hermes e2e UC13: a fresh install puts pikkumies and the resume entry on PATH without touching shell files' => function () use ($hsEId) {
        hsECase('UC13', 'a customer runs pikkumies install on an account with nothing yet', function ($w) use ($hsEId) {
            list($code, $out) = hsERun($w, escapeshellarg("{$w['dir']}/bin/pikkumies") . ' install');
            check(strpos($out, 'hermes resume entry: ') !== false, "install said (exit $code): $out");
            check(is_link("{$w['home']}/.local/bin/pikkumies") && is_link("{$w['home']}/.local/bin/hermes"), 'a link is missing');
            check(!file_exists("{$w['home']}/.bashrc") && !file_exists("{$w['home']}/.profile"), 'a shell file was written although ~/.local/bin was on PATH');
            list($c) = hsERun($w, "hermes --resume $hsEId", true);
            check($c === 0 && count(hsECalls($w)) === 1, "resume after install: exit $c " . json_encode(hsECalls($w)));
            return "install exit $code, both links made, no shell file, resume worked";
        });
    },
    'hermes e2e UC14: doctor says there are no Hermes launchers and does not list the resume entry as a leak' => function () {
        hsECase('UC14', 'a customer runs pikkumies doctor after the entry is linked', function ($w) {
            hsEInstalled($w);
            hsERun($w, 'pikkumies post-update >/dev/null 2>&1');
            check(is_link("{$w['home']}/.local/bin/hermes"), 'setup');
            list($code, $out) = hsERun($w, 'pikkumies doctor');
            check(strpos($out, 'hermes on PATH: no Hermes launchers in ~/.local/bin (cli.expose_on_path: false)') !== false, "doctor said: $out");
            check(strpos($out, 'run this Hermes without the Pikkumies config') === false, 'the entry was listed as a leak');
            return 'doctor line: "hermes on PATH: no Hermes launchers in ~/.local/bin (cli.expose_on_path: false)", no leak warning';
        });
    },
    'hermes e2e UC15: inside an agent terminal call, hermes is the entry: other commands are refused' => function () {
        hsECase('UC15', 'the agent runs shell commands from inside a Pikkumies session', function ($w) {
            hsEInstalled($w);
            hsERun($w, 'pikkumies post-update >/dev/null 2>&1');
            list($code, $out) = hsERun($w, 'command -v hermes; hermes sessions list; echo "exit=$?"', false, ['PIKKUMIES_DEPTH' => '2']);
            check(strpos($out, "{$w['home']}/.local/bin/hermes\n") === 0 && strpos($out, 'exit=2') !== false, "got: $out");
            check(hsECalls($w) === [] && hsEModelRequests($w) === 0, 'Hermes started or a model was asked');
            return 'command -v hermes -> ~/.local/bin/hermes; `hermes sessions list` exit 2; Hermes started 0 times; model requests 0';
        });
    },
];
