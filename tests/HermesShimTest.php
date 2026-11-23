<?php
/** The `hermes` resume entry (bin/hermes, lib/HermesShim.php, PathInstall::linkBeside; ADR-0022 amendment 2026-11-23). */

/** A copy of the entry and its class next to a launcher that only prints the words it was given, so what the entry hands over can be read. */
function hermesShimFixture($t)
{
    mkdir("$t/pk/bin", 0700, true);
    mkdir("$t/pk/lib", 0700, true);
    $src = dirname(__DIR__) . '/pikkumies';
    copy("$src/bin/hermes", "$t/pk/bin/hermes");
    copy("$src/lib/HermesShim.php", "$t/pk/lib/HermesShim.php");
    file_put_contents("$t/pk/bin/pikkumies", "<?php\necho json_encode(array_slice(\$argv, 1)), \"\\n\";\nexit(7);\n");
    return "$t/pk/bin/hermes";
}

return [
    'hermes shim: the four forms Hermes prints become launcher words, and a title stays one word' => function () {
        $cases = [
            [['--resume', 'S1'], ['--resume', 'S1']],
            [['-c', 'my title'], ['run', 'chat', '-c', 'my title']],
            [['--tui', '--resume', 'S1'], ['run', '--tui', '--resume', 'S1']],
            [['--tui', '-c', 'my title'], ['run', '--tui', '-c', 'my title']],
            [['-c', 'say "hi" $(id) `id` ; x'], ['run', 'chat', '-c', 'say "hi" $(id) `id` ; x']],
        ];
        foreach ($cases as $c) {
            check(HermesShim::route($c[0]) === [$c[1], ''], json_encode($c[0]) . ' gave ' . json_encode(HermesShim::route($c[0])));
        }
    },
    'hermes shim: every other input is refused with one line naming pikkumies and hands over no words' => function () {
        $refused = [
            [], ['sessions', 'list'], ['update'], ['--tui'], ['-c'], ['--continue'], ['--resume'], ['--tui', '--resume'],
            ['--resume', ''], ['--resume', ' '], ['--resume', '-x'], ['-c', '-x'], ['--resume', 'a b'], ['--resume', 'a', 'b'],
            ['-c', 'a', 'b'], ['--tui', '--tui', '-c', 'a'], ['--resume', 'S1', '-p', 'other'], ['-p', 'other', '--resume', 'S1'],
            ['chat', '-q', 'hello'], ['--resume', "a\0b"], ['gateway', 'run'], ['--', '--resume', 'S1'],
        ];
        foreach ($refused as $words) {
            list($out, $line) = HermesShim::route($words);
            check($out === [] && strpos($line, 'pikkumies') !== false && strpos($line, "\n") === false, json_encode($words) . ' was not refused: ' . json_encode([$out, $line]));
        }
    },
    'hermes shim: bin/hermes hands the printed forms to the launcher as separate words, and passes its exit code on' => function () {
        $t = testTempDir('pk-shim');
        try {
            $bin = hermesShimFixture($t);
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' --tui -c ' . escapeshellarg('t $(touch ' . $t . '/pwned)') . ' 2>&1', $out, $code);
            check($out === [json_encode(['run', '--tui', '-c', 't $(touch ' . $t . '/pwned)'])] && $code === 7, json_encode([$out, $code]));
            check(!file_exists("$t/pwned"), 'a title was run by a shell');
            $out = [];
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' --resume 20261114_101010_abc123 2>&1', $out, $code);
            check($out === [json_encode(['--resume', '20261114_101010_abc123'])], json_encode($out));
        } finally {
            testRmTree($t);
        }
    },
    'hermes shim: bin/hermes on anything else exits 2, prints the one line on stderr, and never reaches the launcher' => function () {
        $t = testTempDir('pk-shim');
        try {
            $bin = hermesShimFixture($t);
            foreach ([['sessions', 'list'], ['-c'], ['--continue'], ['update'], []] as $words) {
                $out = [];
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $words)) . ' 2>&1', $out, $code);
                check($code === 2 && count($out) === 1 && strpos($out[0], 'pikkumies --resume') !== false, json_encode($words) . ' gave ' . json_encode([$code, $out]));
            }
        } finally {
            testRmTree($t);
        }
    },
    'hermes shim: linkBeside links only where the pikkumies link is, never over a real file or a link that points elsewhere, and the doctor does not list it' => function () {
        $t = testTempDir('pk-shim');
        HermesInstall::$root = "$t/pk";
        try {
            $home = "$t/home";
            check(PathInstall::linkBeside($home, 'hermes', "$t/pk/bin/hermes") === [], 'no pikkumies link: nothing is linked');
            check(!file_exists("$home/.local/bin") && !file_exists("$home/bin"), 'a bin directory was made for nothing');
            mkdir("$home/bin", 0700, true);
            symlink("$t/pk/bin/pikkumies", "$home/bin/pikkumies");
            $r = PathInstall::linkBeside($home, 'hermes', "$t/pk/bin/hermes");
            check(count($r) === 1 && readlink("$home/bin/hermes") === "$t/pk/bin/hermes" && !file_exists("$home/.local/bin"), json_encode($r));
            check(strpos(PathInstall::linkBeside($home, 'hermes', "$t/pk/bin/hermes")[0], 'already linked') !== false, 'a second run is a no-op');
            mkdir("$t/h3/.local/bin", 0700, true);
            symlink("$t/pk/bin/pikkumies", "$t/h3/.local/bin/pikkumies");
            PathInstall::linkBeside("$t/h3", 'hermes', "$t/pk/bin/hermes");
            check(is_link("$t/h3/.local/bin/hermes") && HermesInstall::pathLaunchers("$t/h3") === [], 'the doctor lists the resume entry as a Hermes launcher');
            unlink("$home/bin/hermes");
            symlink('/opt/own/hermes', "$home/bin/hermes");
            $r = PathInstall::linkBeside($home, 'hermes', "$t/pk/bin/hermes");
            check(readlink("$home/bin/hermes") === '/opt/own/hermes' && strpos($r[0], 'left alone') !== false, 'the person\'s own link was retargeted: ' . json_encode($r));
            unlink("$home/bin/hermes");
            file_put_contents("$home/bin/hermes", "own script\n");
            $r = PathInstall::linkBeside($home, 'hermes', "$t/pk/bin/hermes");
            check(file_get_contents("$home/bin/hermes") === "own script\n" && strpos($r[0], 'left alone') !== false, 'a real file was overwritten: ' . json_encode($r));
        } finally {
            HermesInstall::$root = null;
            testRmTree($t);
        }
    },
    'hermes shim: install links the entry beside pikkumies, adds no PATH line for it, and without a resume target behaves as before' => function () {
        $t = testTempDir('pk-shim');
        try {
            $msg = PathInstall::install("$t/h1", "/usr/bin:$t/h1/.local/bin", "$t/pk/bin/pikkumies", "$t/pk/bin/hermes");
            check(is_link("$t/h1/.local/bin/pikkumies") && readlink("$t/h1/.local/bin/hermes") === "$t/pk/bin/hermes" && strpos($msg, 'hermes resume entry') !== false, $msg);
            check(!is_file("$t/h1/.bashrc"), 'a dotfile was touched although the directory was on PATH');
            PathInstall::install("$t/h2", "/usr/bin:$t/h2/.local/bin", "$t/pk/bin/pikkumies");
            check(!file_exists("$t/h2/.local/bin/hermes"), 'the entry was linked without being asked for');
        } finally {
            testRmTree($t);
        }
    },
];
