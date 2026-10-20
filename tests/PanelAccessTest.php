<?php
/**
 * lib/PanelAccess.php and lib/PanelHtpasswd.php (ADR-0079) on a temporary home: nothing is written until the rendered
 * panel config lists the agent, the agent line is added under the shared lock with every other line kept byte for byte,
 * a symlink or hard link is refused, a held lock stops the change, a mismatch rotates, and remove takes the line out.
 */

/** The rendered per-account config, as PMSS's template gives it, with the /user- require set to $require. */
function panelConfText($require)
{
    return "auth.backend = \"htpasswd\"\nauth.backend.htpasswd.userfile = \"/home/exampleuser/.lighttpd/.htpasswd\"\n\n"
        . "auth.require = (\n \"/user-exampleuser/\" =>\n    (\n        \"method\" => \"basic\",\n        \"realm\" => \"Password protected area (exampleuser)\",\n"
        . "        \"require\" => \"$require\"\n    ),\n \"/webdav-exampleuser/\" =>\n    (\n        \"method\" => \"basic\",\n"
        . "        \"realm\" => \"Password protected area (exampleuser)\",\n        \"require\" => \"user=exampleuser\"\n    )\n)\n"
        . "alias.url += (\n \"/user-exampleuser/\" => \"/home/exampleuser/www/\",\n \"/webdav-exampleuser/\" => \"/home/exampleuser/\"\n)\n";
}

/** A home with ~/.lighttpd.conf ($require; null: none) and ~/.lighttpd/.htpasswd ($htpasswd; null: none); $fn($panel, $t, $userLine). */
function panelWorld($require, callable $fn, $htpasswd = true)
{
    $t = testTempDir('pk-panel');
    try {
        mkdir("$t/home/.lighttpd", 0751, true);
        if ($require !== null) {
            file_put_contents("$t/home/.lighttpd.conf", panelConfText($require));
        }
        $userLine = 'exampleuser:' . password_hash('example-password', PASSWORD_BCRYPT, ['cost' => 4]);
        if ($htpasswd !== null) {
            file_put_contents("$t/home/.lighttpd/.htpasswd", $htpasswd === true ? "$userLine\n" : str_replace('USERLINE', $userLine, $htpasswd));
            chmod("$t/home/.lighttpd/.htpasswd", 0640);
        }
        $panel = new PanelAccess("$t/home", 'exampleuser', "$t/local");
        return $fn($panel, $t, $userLine);
    } finally {
        testRmTree($t);
    }
}

function panelSecret($t)
{
    $d = json_decode((string) @file_get_contents("$t/local/secrets/panel-agent"), true);
    return is_array($d) && isset($d['password']) ? $d['password'] : null;
}

function panelAgentLines($t)
{
    return PanelHtpasswd::named(PanelHtpasswd::lines((string) file_get_contents("$t/home/.lighttpd/.htpasswd")), PanelAccess::AGENT);
}

const PANEL_ON = 'user=exampleuser|user=pikkumies.agent';

return [
    'panel access: nothing is written while the panel config does not list the agent (template not updated, no config, comment, webdav only)' => function () {
        panelWorld('user=exampleuser', function ($panel, $t) {
            $before = file_get_contents("$t/home/.lighttpd/.htpasswd");
            check(!$panel->available() && strpos($panel->unavailable(), 'does not accept a second login yet') !== false, $panel->unavailable());
            list($ok, $why) = $panel->ensure();
            check(!$ok && strpos($why, 'second login') !== false, "refused: $why");
            check(file_get_contents("$t/home/.lighttpd/.htpasswd") === $before && !file_exists("$t/home/.lighttpd/.htpasswd.lock")
                && !file_exists("$t/local/secrets/panel-agent") && !file_exists($panel->logFile()), 'nothing written, no lock taken, no secret, no log');
            check(strpos($panel->statusLine(), 'panel access: off (') === 0, $panel->statusLine());
            file_put_contents("$t/home/.lighttpd.conf", "#\"/user-exampleuser/\" => ( \"require\" => \"" . PANEL_ON . "\" )\n" . panelConfText('user=exampleuser'));
            check(!$panel->available(), 'a commented-out rule does not count');
            file_put_contents("$t/home/.lighttpd.conf", str_replace("\"require\" => \"user=exampleuser\"\n    )\n)", "\"require\" => \"" . PANEL_ON . "\"\n    )\n)", panelConfText('user=exampleuser')));
            check(!$panel->available(), 'the agent on the webdav zone alone does not count');
        });
        panelWorld(null, function ($panel) {
            check(strpos($panel->unavailable(), 'no panel configuration') !== false && !$panel->ensure()[0], $panel->unavailable());
        });
        check(PanelAccess::userZoneRequire(panelConfText(PANEL_ON), 'exampleuser') === PANEL_ON, 'the /user- rule is read, not the alias line');
    },
    'panel access: ensure appends one bcrypt line under the lock, keeps the account line byte for byte and stores the secret 0600' => function () {
        panelWorld(PANEL_ON, function ($panel, $t, $userLine) {
            $file = "$t/home/.lighttpd/.htpasswd";
            $inode = fileinode($file);
            check($panel->ensure() === [true, 'created'], 'created');
            $lines = PanelHtpasswd::lines(file_get_contents($file));
            check(count($lines) === 2 && $lines[0] === $userLine, 'the account line first and unchanged: ' . json_encode($lines));
            $secret = panelSecret($t);
            check(is_string($secret) && preg_match('/^[0-9a-f]{64}$/', $secret) && strpos($lines[1], 'pikkumies.agent:$2y$10$') === 0
                && password_verify($secret, substr($lines[1], strlen('pikkumies.agent:'))), 'a $2y$ line that checks out against the stored secret');
            clearstatcache();
            check(fileinode($file) === $inode && (fileperms($file) & 0777) === 0640, 'appended in place: same inode, mode 0640 kept');
            check((fileperms("$t/local/secrets/panel-agent") & 0777) === 0600 && (fileperms("$t/local/secrets") & 0777) === 0700, 'secret 0600 in a 0700 directory');
            $bytes = file_get_contents($file);
            check($panel->ensure() === [true, 'present'] && file_get_contents($file) === $bytes, 'a second ensure changes nothing');
            $status = $panel->statusLine();
            check(strpos($status, 'panel access: ready') === 0 && strpos($status, $secret) === false && strpos($status, '$2y$') === false, $status);
            $log = (string) file_get_contents($panel->logFile());
            check(strpos($log, '"action":"create"') !== false && strpos($log, $secret) === false && strpos($log, '$2y$') === false, $log);
            check($panel->writeHeader("$t/header") === '' && (fileperms("$t/header") & 0777) === 0600
                && file_get_contents("$t/header") === 'Basic ' . base64_encode("pikkumies.agent:$secret") . "\n", 'the guard header, 0600');
            check(strpos($panel->writeHeader("$t/header"), 'cannot create') === 0, 'an existing header file is never written over (O_EXCL)');
        });
    },
    'panel access: a file without a final newline gets the agent on a line of its own; other users\' lines and order are kept' => function () {
        panelWorld(PANEL_ON, function ($panel, $t, $userLine) {
            check($panel->ensure()[0], 'created');
            $lines = PanelHtpasswd::lines(file_get_contents("$t/home/.lighttpd/.htpasswd"));
            check($lines[0] === $userLine && count($lines) === 2 && strpos($lines[1], 'pikkumies.agent:') === 0, json_encode($lines));
        }, 'USERLINE');
        panelWorld(PANEL_ON, function ($panel, $t, $userLine) {
            check($panel->ensure() === [true, 'rotated'], 'two agent lines are one again');
            $lines = PanelHtpasswd::lines(file_get_contents("$t/home/.lighttpd/.htpasswd"));
            check(count($lines) === 3 && $lines[0] === $userLine && $lines[1] === "other:x\r" && strpos($lines[2], 'pikkumies.agent:') === 0, json_encode($lines));
        }, "USERLINE\npikkumies.agent:old1\nother:x\r\npikkumies.agent:old2\n");
    },
    'panel access: a line that does not match the stored secret, or a rotate on request, makes a new login' => function () {
        panelWorld(PANEL_ON, function ($panel, $t, $userLine) {
            check($panel->ensure()[0], 'created');
            SecretFile::write("$t/local/secrets/panel-agent", ['user' => PanelAccess::AGENT, 'password' => str_repeat('ab', 32)], 'test');
            check(strpos($panel->statusLine(), 'needs renewing') !== false, $panel->statusLine());
            check($panel->ensure() === [true, 'rotated'], 'mismatch rotates');
            $agent = panelAgentLines($t);
            $secret = panelSecret($t);
            check(count($agent) === 1 && $secret !== str_repeat('ab', 32) && password_verify($secret, substr($agent[0], 16)), 'new secret, one line that matches');
            check(PanelHtpasswd::lines(file_get_contents("$t/home/.lighttpd/.htpasswd"))[0] === $userLine, 'account line kept');
            check($panel->ensure(true) === [true, 'rotated'] && panelSecret($t) !== $secret, 'a rotate on request (a 401) makes a new secret');
            unlink("$t/local/secrets/panel-agent");
            check($panel->ensure() === [true, 'rotated'] && count(panelAgentLines($t)) === 1, 'a lost secret: a new line replaces the old one');
        });
    },
    'panel access: a symlink, a directory, a hard link or a missing password file is refused and never created or followed' => function () {
        panelWorld(PANEL_ON, function ($panel, $t) {
            file_put_contents("$t/elsewhere", "victim:keep\n");
            unlink("$t/home/.lighttpd/.htpasswd");
            symlink("$t/elsewhere", "$t/home/.lighttpd/.htpasswd");
            list($ok, $why) = $panel->ensure();
            check(!$ok && strpos($why, 'a symlink') !== false && file_get_contents("$t/elsewhere") === "victim:keep\n", "symlink: $why");
            check(!$panel->remove()[0] && file_get_contents("$t/elsewhere") === "victim:keep\n", 'remove refuses it too');
            unlink("$t/home/.lighttpd/.htpasswd");
            link("$t/elsewhere", "$t/home/.lighttpd/.htpasswd");
            list($ok, $why) = $panel->ensure();
            check(!$ok && strpos($why, 'hard links') !== false && file_get_contents("$t/elsewhere") === "victim:keep\n", "hard link: $why");
            unlink("$t/home/.lighttpd/.htpasswd");
            mkdir("$t/home/.lighttpd/.htpasswd");
            check(strpos($panel->ensure()[1], 'not a regular file') !== false, 'directory refused');
        });
        panelWorld(PANEL_ON, function ($panel, $t) {
            list($ok, $why) = $panel->ensure();
            check(!$ok && strpos($why, 'does not exist') !== false && !file_exists("$t/home/.lighttpd/.htpasswd"), "never created: $why");
            check($panel->remove() === [true, 'nothing to remove'], 'remove with no file is a no-op');
        }, null);
        panelWorld(PANEL_ON, function ($panel, $t) {
            symlink("$t/elsewhere-lock", "$t/home/.lighttpd/.htpasswd.lock");
            list($ok, $why) = $panel->ensure();
            check(!$ok && strpos($why, 'not locking through it') !== false && !file_exists("$t/elsewhere-lock"), "lock symlink: $why");
        });
    },
    'panel access: a lock another writer holds (this process or flock(1), as PMSS takes it) stops the change; released, it goes through' => function () {
        panelWorld(PANEL_ON, function ($panel, $t) {
            $file = "$t/home/.lighttpd/.htpasswd";
            $before = file_get_contents($file);
            $panel->file->lockWait = 1;
            $h = fopen("$file.lock", 'c');
            check(flock($h, LOCK_EX), 'holder');
            list($ok, $why) = $panel->ensure();
            check(!$ok && strpos($why, 'another program held') !== false && file_get_contents($file) === $before, "held: $why");
            flock($h, LOCK_UN);
            fclose($h);
            check($panel->ensure() === [true, 'created'], 'released: created');
            $flock = trim((string) shell_exec('command -v flock 2>/dev/null'));
            if ($flock === '') {
                skip('flock(1) not installed; the in-process holder above covered the lock');
            }
            $bytes = file_get_contents($file);
            $p = proc_open(escapeshellarg($flock) . ' -w 10 ' . escapeshellarg("$file.lock") . ' -c ' . escapeshellarg('sleep 3'), [], $pipes);
            check(is_resource($p), 'flock(1) holder did not start');
            $probe = fopen("$file.lock", 'c');
            for ($i = 0; $i < 50 && flock($probe, LOCK_EX | LOCK_NB); $i++) { // wait until flock(1) holds it
                flock($probe, LOCK_UN);
                usleep(50000);
            }
            fclose($probe);
            list($ok, $why) = $panel->ensure(true);
            proc_terminate($p);
            proc_close($p);
            check(!$ok && strpos($why, 'another program held') !== false && file_get_contents($file) === $bytes, "flock(1) holder: $why");
        });
    },
    'panel access: a write that would change a line that is not the agent\'s is put back byte for byte' => function () {
        panelWorld(PANEL_ON, function ($panel, $t) {
            $broken = new class("$t/home", 'exampleuser', "$t/local") extends PanelAccess {
                protected function compose($before, $line)
                {
                    return [str_replace('exampleuser:', 'exampleuser:x', $before) . $line . "\n", false]; // a bug that touches the account's line
                }
            };
            $file = "$t/home/.lighttpd/.htpasswd";
            $before = file_get_contents($file);
            list($ok, $why) = $broken->ensure();
            check(!$ok && strpos($why, 'put back as it was') !== false && file_get_contents($file) === $before, "restored: $why");
            check(strpos((string) file_get_contents($broken->logFile()), '"action":"restore"') !== false && !file_exists("$t/local/secrets/panel-agent"), 'logged, no secret kept');
        });
    },
    'panel access: remove takes out the agent line and the secret, keeps the rest, and works after the panel stops listing the agent' => function () {
        panelWorld(PANEL_ON, function ($panel, $t) {
            $file = "$t/home/.lighttpd/.htpasswd";
            $before = file_get_contents($file);
            check($panel->ensure()[0], 'created');
            file_put_contents("$t/home/.lighttpd.conf", panelConfText('user=exampleuser'));
            check($panel->remove() === [true, 'removed'] && file_get_contents($file) === $before && !file_exists("$t/local/secrets/panel-agent"), 'removed');
            check((fileperms($file) & 0777) === 0640 && strpos((string) file_get_contents($panel->logFile()), '"action":"remove"') !== false, 'mode kept, logged');
            check($panel->remove() === [true, 'nothing to remove'], 'twice is fine');
        });
    },
    'launch: `pikkumies access status|rotate|remove` is the access subcommand; other words after access are a prompt' => function () {
        foreach (['status', 'rotate', 'remove'] as $verb) {
            check(Launch::route(['pikkumies', 'access', $verb]) === ['access', [$verb]], $verb);
        }
        check(Launch::route(['pikkumies', 'access', 'my', 'files']) === ['run', ['chat', '--query=access my files']], 'a prompt');
        check(Launch::route(['pikkumies', 'access']) === ['run', ['chat', '--query=access']], 'bare access is a prompt');
    },
];
