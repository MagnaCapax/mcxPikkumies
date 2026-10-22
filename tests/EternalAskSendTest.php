<?php
return [
    'eternal ask: send refuses root, caps sends, reuses nonce and pastes its own named buffer' => function () {
        $root = testTempDir('eternal-ask-send');
        $source = dirname(__DIR__) . '/pikkumies';
        exec('cp -R ' . escapeshellarg($source . '/lib') . ' ' . escapeshellarg($root) . '/');
        @mkdir($root . '/tools/system', 0700, true);
        exec('cp -R ' . escapeshellarg($source . '/tools/system/credits') . ' ' . escapeshellarg($root . '/tools/system') . '/');
        @mkdir($root . '/fake-bin', 0700);
        @mkdir($root . '/include', 0700);
        @mkdir($root . '/hermes/.hermes/bin', 0700, true);
        @mkdir($root . '/local/hermes/terminal-sessions', 0700, true);
        file_put_contents($root . '/enabled', "1\n");
        file_put_contents($root . '/include/.hermes-ready', "ready\n");
        file_put_contents($root . '/hermes/.hermes/bin/hermes', "#!/bin/sh\nexit 0\n");
        chmod($root . '/hermes/.hermes/bin/hermes', 0700);
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' init -q');
        exec('git -C ' . escapeshellarg($root . '/hermes') . ' -c user.name=Fixture -c user.email=fixture@example.invalid commit -q --allow-empty -m seed');
        $pane = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $root . '/bin/pikkumies', 'run', '--eternal', 'pm1']));
        $fake = <<<'PHP'
#!/usr/bin/env php
<?php
$root = ROOT_PATH;
$pane = PANE_COMMAND;
$args = array_slice($argv, 1);
$verb = $args[0];
if ($verb === 'display-message') { echo $pane . "|/dev/pts/42|0\n"; exit; }
if ($verb === 'send-keys') { file_put_contents($root . '/keys', "Enter\n", FILE_APPEND); exit; }
if ($verb === 'capture-pane') {
    $held = is_file($root . '/hold') && is_file($root . '/armed') && count(file($root . '/keys', FILE_IGNORE_NEW_LINES) ?: []) < (int) file_get_contents($root . '/hold');
    echo $held ? "───\n❯ hello\n───\n" : "───\n❯\n───\n"; exit;
}
if ($verb === 'load-buffer') { file_put_contents($root . '/buffer', stream_get_contents(STDIN)); }
if ($verb === 'paste-buffer') { file_put_contents($root . '/pastes', implode(' ', $args) . "\n", FILE_APPEND); touch($root . '/armed'); }
exit(0);
PHP;
        $fake = str_replace(['ROOT_PATH', 'PANE_COMMAND'], [var_export($root, true), var_export($pane, true)], $fake);
        file_put_contents($root . '/fake-bin/tmux', $fake);
        chmod($root . '/fake-bin/tmux', 0700);
        $runner = $root . '/runner.php';
        file_put_contents($runner, '<?php foreach (glob(__DIR__ . "/lib/*.php") as $file) require_once $file; Eternal::$hostMarker = __DIR__ . "/enabled"; HermesInstall::markVerified(HermesInstall::head()); Config::acceptTerms(); EternalRecord::write("pm1", ["sessionId" => "", "fails" => 0, "lastStart" => time() - 2]); file_put_contents(__DIR__ . "/local/hermes/terminal-sessions/tty-dev-pts-42", "fixture-session\n"); list($code, $message) = EternalAsk::send("hello", $argv[1] === "nonce" ? "abcdefgh" : "", $argv[1] === "root" ? 0 : 1000); echo $message; exit($code);');
        $old = [];
        foreach (['PATH', 'PIKKUMIES_ENDPOINT', 'PIKKUMIES_KEY', 'TMUX'] as $name) { $old[$name] = getenv($name); }
        putenv('PATH=' . $root . '/fake-bin:' . $old['PATH']);
        putenv('PIKKUMIES_ENDPOINT=https://example.invalid/v1');
        putenv('PIKKUMIES_KEY=fixture-key');
        putenv('TMUX=/ordinary/socket,1,0');
        // The cap counts sends per wall-clock minute (EternalAsk::send); near a minute boundary the counted sends
        // would straddle it and the sixth one pass (CI, 2026-10-20). Start in a fresh minute instead.
        if (time() % 60 > 50) {
            sleep(61 - time() % 60);
        }
        try {
            list($code, $out) = runPhp($runner, ['root']);
            check($code === 1 && strpos($out, 'not root') !== false && !is_file($root . '/pastes'), 'root reached tmux');
            // The input drops the first Enter: ask presses it again while the pasted text is still held.
            file_put_contents($root . '/hold', "2\n");
            list($code, $out) = runPhp($runner, ['nonce']);
            check($code === 0 && strpos($out, 'Sent') !== false, 'first send failed: ' . $out);
            check(count(file($root . '/keys', FILE_IGNORE_NEW_LINES)) === 2, 'held text was not submitted again');
            @unlink($root . '/hold');
            @unlink($root . '/armed');
            $paste = (string) file_get_contents($root . '/pastes');
            check(preg_match('/paste-buffer -p -d -b pkask-[a-f0-9]{16} -t/', $paste) === 1, 'paste did not select its named buffer: ' . $paste);
            check(file_get_contents($root . '/buffer') === 'hello', 'question changed in buffer');
            list($code, $out) = runPhp($runner, ['nonce']);
            check($code === 0 && strpos($out, 'Already sent') !== false && file_get_contents($root . '/pastes') === $paste, 'nonce sent twice');
            // Text that never leaves the input is reported as not sent, not as sent.
            file_put_contents($root . '/hold', "99\n");
            list($code, $out) = runPhp($runner, ['plain']);
            check($code === 1 && strpos($out, 'Could not send') !== false, 'unsent text reported as sent: ' . $out);
            @unlink($root . '/hold');
            @unlink($root . '/armed');
            for ($n = 0; $n < 4; $n++) { list($code) = runPhp($runner, ['plain']); check($code === 0, 'send before cap failed'); }
            list($code, $out) = runPhp($runner, ['plain']);
            check($code === 4 && strpos($out, 'rate capped') !== false, 'sixth send bypassed cap');
        } finally {
            foreach ($old as $name => $value) { $value === false ? putenv($name) : putenv($name . '=' . $value); }
            testRmTree($root);
        }
    },
];
