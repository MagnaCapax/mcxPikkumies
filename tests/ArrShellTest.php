<?php
/** lib/ArrShell.php run(): an argument list, stdin written then closed, stdout and stderr read together (no pipe deadlock). */
return [
    'arr shell: a program that writes 200 KB to stderr before stdout finishes, with stdin and both outputs whole' => function () {
        $dir = sys_get_temp_dir() . '/pk-shell-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700);
        try {
            file_put_contents("$dir/run.php", '<?php require ' . var_export(dirname(__DIR__) . '/pikkumies/tools/arr/lib/ArrShell.php', true) . ';
list($code, $out, $err) = ArrShell::run([PHP_BINARY, ' . var_export(__DIR__ . '/fixtures/arr/noisy.php', true) . '], [], null, "typed\n");
echo json_encode([$code, $out, strlen($err), substr($err, -4)]);');
            $t = microtime(true);
            exec('timeout 20 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/run.php"), $lines, $code);
            $r = json_decode(implode('', $lines), true);
            check($code === 0 && $r === [0, 'out:typed', 200004, 'tail'], 'deadlocked or lost output (exit ' . $code . ', ' . round(microtime(true) - $t, 1) . ' s): ' . implode('', $lines));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
