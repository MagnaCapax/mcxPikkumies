<?php
/**
 * lib/Launch.php: install flags and the small first-run prompts, beyond the happy-path coverage
 * already in tests/LaunchTest.php and tests/LaunchRoutingTest.php (ADR-0022, ADR-0032).
 */

return [
    "docs: README.md's streamlined-install example names every flag in Launch::INSTALL_FLAGS" => function () {
        // #19 a new flag added to code but not the example strands PMSS installers on stale docs.
        $readme = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/README.md');
        foreach (array_keys(Launch::INSTALL_FLAGS) as $flag) {
            check(strpos($readme, "--$flag=") !== false, "README.md's install example is missing --$flag=");
        }
    },
    'launch: askTerms accepts the short forms "y" and "n" identically to "yes"/"no"' => function () {
        // #44 the short-answer path had zero coverage; a refactor dropping the aliases would silently
        // break real users who type the short form.
        $feed = function (array $lines) {
            return function () use (&$lines) {
                return $lines ? array_shift($lines) : false;
            };
        };
        $write = function ($t) {
        };
        check(Launch::askTerms($feed(["y\n"]), $write) === true, '"y" must accept, same as "yes"');
        check(Launch::askTerms($feed(["n\n"]), $write) === false, '"n" must refuse, same as "no"');
    },
    'launch: installFlags keeps the later value when a flag repeats, and keeps a value containing "=" whole before rejecting it' => function () {
        // #45 last-wins is implicit in the foreach-overwrite in Launch::installFlags(); a refactor
        // (e.g. to array_unique or a first-wins guard) could silently change this.
        list($set, , $err) = Launch::installFlags(['--name=First', '--name=Second']);
        check($err === [] && $set === ['agent.name' => 'Second'], 'the later --name must win: ' . json_encode($set));
        // #46 confirms the flag regex captures everything after the FIRST "=" as the value, so a value
        // that itself contains "=" reaches Config::parse() whole (and only then fails as an invalid name).
        list($set, , $err) = Launch::installFlags(['--name=A=B']);
        check($set === [] && count($err) === 1 && strpos($err[0], '--name:') === 0, 'a value containing "=" must reach parse() whole and be refused as one error: ' . json_encode($err));
    },
    'launch: installFlags is case-sensitive, treats an empty value as an error (never a silent no-op), and never reads the next word as a flag\'s value' => function () {
        // #47 [a-z-]+ is deliberately lowercase-only; case-insensitive drift would silently widen the
        // documented flag set.
        list($set, , $err) = Launch::installFlags(['--NAME=Helper']);
        check($set === [] && count($err) === 1 && strpos($err[0], 'unknown flag --NAME=Helper') === 0, 'an uppercase flag name must be unknown, not matched case-insensitively: ' . json_encode($err));
        // #48 guards "flag omitted" (fine, no error) vs "flag given empty" (must error): an empty value
        // must never silently no-op past Config::parse()'s own empty-string handling per key type.
        list($set, , $err) = Launch::installFlags(['--telemetry=', '--retention-days=']);
        check($set === [] && count($err) === 2, 'an empty flag value must be 2 errors, not a silent no-op: ' . json_encode($err));
        // #49 install flags only ever take --key=value form (ADR-0032); confirmed against the real
        // source this session: a bare --name with no "=" produces an unknown-flag error for BOTH words
        // (--name AND the next word), not one error as a first guess assumed — proving the next word
        // is never read as --name's value (it is independently rejected, never fed to Config::parse).
        list($set, , $err) = Launch::installFlags(['--name', 'Helper']);
        check($set === [] && count($err) === 2, 'a bare --name with no "=" must be 2 unknown-flag errors: ' . json_encode($err));
        foreach ($err as $e) {
            check(strpos($e, 'agent.name:') === false, "the next word must never reach Config::parse() as --name's value: $e");
        }
    },
    "launch: isTty treats a non-resource or invalid argument as 'not a terminal', never a fatal error" => function () {
        // #50 existing coverage (tests/LaunchTest.php) only passes a real php://temp stream; cron-tick
        // and docs-fill depend on this degrading safely for any other input, including null and a plain
        // string. Reasoned: isTty()'s own is_resource() guard is exactly what makes this safe — removing
        // it would hand a non-resource straight to stream_isatty()/posix_isatty(), which take a resource
        // and would throw a TypeError instead of returning false.
        check(Launch::isTty(null) === false, 'null must be "not a terminal", not a fatal error');
        check(Launch::isTty('not-a-stream') === false, 'a plain string must be "not a terminal", not a fatal error');
    },
    'launch: install --name without "=" is never dispatched as install; the whole line becomes a prompt (D134)' => function () {
        // --name only ever takes the --key=value form (installFlags() #49 above already proves the
        // parser rejects a bare --name); this confirms the OTHER layer: route() itself never even
        // reaches installFlags() for this case, because allFlags() sees "Helper" as a non-flag word
        // after "--name" and falls through to a plain prompt instead of the install subcommand.
        list($cmd, $args) = Launch::route(['pikkumies', 'install', '--name', 'Helper']);
        check($cmd === 'run' && $args === ['chat', '--query=install --name Helper'], 'a bare --name followed by its value must stay a prompt, not install: ' . json_encode([$cmd, $args]));
        // Contrast: the documented --name=value form keeps install (already covered in LaunchTest.php);
        // confirmed here only to show the two forms take different routing branches for the same intent.
        list($cmd2, $args2) = Launch::route(['pikkumies', 'install', '--name=Helper']);
        check($cmd2 === 'install' && $args2 === ['--name=Helper'], 'the --name=value form must still dispatch to install: ' . json_encode([$cmd2, $args2]));
    },
    'install layout: ~/pikkumies is a symlink to Config::root(), created only when missing, never a copy (D145)' => function () {
        // Config::root() cannot be test-seamed (it is dirname(__DIR__) of the real lib/Config.php),
        // and a dynamic install run would also start the real background docs-fill fetch over the
        // network (bin/pikkumies's own startDocsFill()) and write the real pikkumies/local/config.php
        // of whichever checkout runs the test — both unsafe for a hermetic, no-network test. The
        // static check below reads the exact source bin/pikkumies install dispatches to.
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match("/\n    case 'install':(.*?)\n        break;/s", $bin, $m) === 1, 'install case not found in bin/pikkumies');
        $install = $m[1];
        check(strpos($install, "\$link = \$home . '/pikkumies';") !== false, 'the ~/pikkumies link path must be derived from $home: ' . $install);
        check(preg_match('/if \(!file_exists\(\$link\)\) \{\s*@symlink\(Config::root\(\), \$link\);\s*\}/', $install) === 1,
            'the layout step must be exactly "if not already there, symlink to Config::root()": ' . $install);
        foreach (['copy(', 'rename(', 'mkdir($link'] as $op) {
            check(strpos($install, $op) === false, "the ~/pikkumies layout step must never $op (a copy, not a symlink): " . $install);
        }
    },
];
