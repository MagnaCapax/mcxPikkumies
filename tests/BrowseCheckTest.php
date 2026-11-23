<?php
/** tools/system/browse/BrowseCheck.php: when the browser may be offered, the memory gate, the doctor line (ADR-0077). */
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/browse.inc.php';

/** Seams for a machine where every check passes; $fn gets the world, every seam is reset after. */
function browseCheckWorld(callable $fn)
{
    $t = testTempDir('pk-browse-check');
    try {
        file_put_contents("$t/chromium", "#!/bin/sh\necho 'Chromium 141.0.7390.54 built on Debian'\n");
        chmod("$t/chromium", 0700);
        foreach (BrowseCheck::HERMES_FACTS as $fact) {
            @mkdir(dirname("$t/hermes/{$fact[0]}"), 0700, true);
            file_put_contents("$t/hermes/{$fact[0]}", (string) @file_get_contents("$t/hermes/{$fact[0]}") . $fact[1] . "\n");
        }
        mkdir("$t/hermes/pm", 0700, true);
        file_put_contents("$t/hermes/pm/lock.json", json_encode(['packages' => ['agent-browser' => ['version' => '0.26.0',
            'artifacts' => ['any' => ['sha256' => str_repeat('a', 64), 'url' => 'https://registry.example.org/a.tgz']]]]]));
        mkdir("$t/sys/kernel", 0700, true);
        mkdir("$t/sys/user", 0700, true);
        file_put_contents("$t/sys/kernel/unprivileged_userns_clone", "1\n");
        file_put_contents("$t/sys/user/max_user_namespaces", "514631\n");
        file_put_contents("$t/config.yaml", "browser:\n  restrict_evaluate: true\n");
        mkdir("$t/local", 0700);
        BrowsePaths::$chromium = "$t/chromium";
        BrowsePaths::$local = "$t/local";
        BrowseCheck::$source = "$t/hermes";
        BrowseCheck::$userConfig = "$t/config.yaml";
        // a shared account list by default, so nothing here depends on the machine the suite runs on (fenceReason)
        file_put_contents("$t/passwd", "alpha:x:1000:1000::$t:/bin/sh\nbeta:x:1001:1001::$t:/bin/sh\n");
        file_put_contents("$t/nsswitch.conf", "passwd:         files systemd\n");
        BrowseCheck::$seedbox = "$t/no-seedbox";
        BrowseCheck::$passwd = "$t/passwd";
        BrowseCheck::$nsswitch = "$t/nsswitch.conf";
        BrowseCheck::$procSys = "$t/sys";
        BrowseCheck::$which = function ($tool) {
            return "/usr/bin/$tool";
        };
        BrowseBinary::$lockFile = "$t/hermes/pm/lock.json";
        return $fn($t);
    } finally {
        BrowsePaths::$chromium = null;
        BrowsePaths::$local = null;
        BrowseCheck::$source = null;
        BrowseCheck::$userConfig = null;
        BrowseCheck::$procSys = '/proc/sys';
        BrowseCheck::$seedbox = '/etc/seedbox';
        BrowseCheck::$passwd = '/etc/passwd';
        BrowseCheck::$nsswitch = '/etc/nsswitch.conf';
        BrowseCheck::$which = null;
        BrowseBinary::$lockFile = null;
        testRmTree($t);
    }
}

return [
    'browse check: every check passing offers the browser; each failing one turns it off with its own reason' => function () {
        browseCheckWorld(function ($t) {
            check(BrowseCheck::problem() === '', 'all pass: ' . BrowseCheck::problem());
            $env = BrowseCheck::hermesEnv();
            check($env['AGENT_BROWSER_EXECUTABLE_PATH'] === "$t/chromium" && strpos($env['PATH'], "$t/local/browse/path:") === 0, json_encode($env));
            check(strpos((string) file_get_contents("$t/local/browse/path/agent-browser"), "browse.php' cli -- \"\$@\"") !== false, 'the shim is pure exec glue');
            BrowseCheck::$which = function ($tool) {
                return $tool === 'slirp4netns' ? null : "/usr/bin/$tool";
            };
            check(BrowseCheck::problem() === 'slirp4netns is not installed' && BrowseCheck::hermesEnv() === [], 'a missing tool: off, nothing on PATH');
            BrowseCheck::$which = function ($tool) {
                return "/usr/bin/$tool";
            };
            file_put_contents("$t/sys/kernel/unprivileged_userns_clone", "0\n");
            check(strpos(BrowseCheck::problem(), 'user namespaces') !== false, 'userns off');
            file_put_contents("$t/sys/kernel/unprivileged_userns_clone", "1\n");
            file_put_contents("$t/hermes/tools/browser_tool_cloud.py", "renamed\n");
            check(strpos(BrowseCheck::problem(), 'no longer has browser.allow_private_urls') !== false, BrowseCheck::problem());
            chmod("$t/chromium", 0600);
            check(strpos(BrowseCheck::problem(), 'is not installed') !== false, 'chromium not executable');
        });
    },
    'browse check: restrict_evaluate must be true; allow_private_urls or use_real_profile on turns the browser off' => function () {
        check(BrowseCheck::configProblem("browser:\n  restrict_evaluate: true\n") === '', 'seeded');
        check(BrowseCheck::configProblem("browser:\n  restrict_evaluate: 'yes'  # mine\n  allow_private_urls: false\n") === '', 'quoted and commented, private off');
        check(BrowseCheck::configProblem("browser:\n  command_timeout: 30\n") !== '', 'not set');
        check(BrowseCheck::configProblem("browser:\n  restrict_evaluate: false\n") !== '', 'false');
        check(strpos(BrowseCheck::configProblem("browser:\n  restrict_evaluate: true\n  use_real_profile: true\n"), 'use_real_profile') === 0, 'real profile');
        check(strpos(BrowseCheck::configProblem("security:\n  allow_private_urls: on\nbrowser:\n  restrict_evaluate: true\n"), 'allow_private_urls') === 0, 'security.allow_private_urls as well');
    },
    'browse check: the memory gate uses the account\'s own limit, else MemAvailable, and says how much it needs' => function () {
        $mb = 1048576;
        $own = ['memory_max' => 2048 * $mb, 'memory_current' => 1000 * $mb, 'memory_shared' => false];
        check(BrowseCheck::headroomProblem($own, 10 * $mb) === '', 'own limit with room (MemAvailable ignored)');
        $tight = ['memory_max' => 1024 * $mb, 'memory_current' => 700 * $mb, 'memory_shared' => false];
        $why = BrowseCheck::headroomProblem($tight, 64000 * $mb);
        check(strpos($why, '324 MB') !== false && strpos($why, "account's limit") !== false && strpos($why, '600 MB') !== false, $why);
        $shared = ['memory_max' => 64000 * $mb, 'memory_current' => null, 'memory_shared' => true];
        check(strpos(BrowseCheck::headroomProblem($shared, 100 * $mb), 'on this server') !== false, 'shared level: MemAvailable decides');
        check(BrowseCheck::headroomProblem(['memory_max' => null, 'memory_current' => null, 'memory_shared' => false], null) === '', 'nothing readable: no refusal');
        $t = testTempDir('pk-browse-mem');
        file_put_contents("$t/meminfo", "MemTotal:       65536000 kB\nMemAvailable:    2048000 kB\n");
        check(BrowseCheck::memAvailable("$t/meminfo") === 2048000.0 * 1024, 'MemAvailable in bytes');
        testRmTree($t);
    },
    'browse check: the doctor line says ready with both versions, or off with the failing check' => function () {
        browseCheckWorld(function ($t) {
            check(BrowseCheck::doctorLine(['guard' => []], '141.0.7390.54') === 'browse:   ready (chromium 141.0.7390.54, agent-browser 0.26.0, isolated, guard up)', BrowseCheck::doctorLine(['x'], '141.0.7390.54'));
            $stopped = BrowseCheck::doctorLine(null, '141.0.7390.54');
            check(strpos($stopped, 'browse:   ready (chromium 141.0.7390.54, agent-browser 0.26.0 (fetched on first use); stopped') === 0, $stopped);
            check(BrowseCheck::chromiumVersion() === '141.0.7390.54', 'read from chromium --version');
            file_put_contents("$t/config.yaml", "browser:\n  restrict_evaluate: false\n");
            check(strpos(BrowseCheck::doctorLine(null, 'x'), 'browse:   off (browser.restrict_evaluate is not true') === 0, BrowseCheck::doctorLine(null, 'x'));
        });
    },
    'browse check: the per-account fence is dropped only on a machine proven single-tenant, and every doubt keeps it (fenceReason)' => function () {
        browseCheckWorld(function ($t) {
            $one = "alpha:x:1000:1000::$t:/bin/sh\nroot:x:0:0:root:$t:/bin/bash\ndaemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin\nsvc:x:998:998::$t:/bin/false\n";
            $cases = [
                // [passwd, nsswitch, seedbox dir present, expected: '' for no fence, else a word of the reason, what it shows]
                [$one, "passwd: files systemd\n", false, '', 'one human account (system accounts and root do not count)'],
                ["root:x:0:0:root:$t:/bin/bash\n", "passwd:  files\n", false, '', 'root alone'],
                [$one . "beta:x:1001:1001::$t:/bin/sh\n", "passwd: files systemd\n", false, 'more than one account', 'two accounts with a home'],
                [$one, "passwd: files systemd\n", true, 'PMSS', 'a PMSS server with one account'],
                [$one, "passwd: files ldap\n", false, 'directory service', 'accounts from LDAP the file cannot show'],
                [$one, "passwd: files sss systemd\n", false, 'directory service', 'accounts from sssd'],
                [$one, "passwd: compat\n", false, 'directory service', 'compat mode reads NIS entries'],
                [$one, "# nothing about passwd here\nhosts: files dns\n", false, 'directory service', 'no passwd line'],
                [$one, "passwd: files [NOTFOUND=return] systemd\n", false, 'directory service', 'a lookup action is not plain files'],
            ];
            foreach ($cases as $c) {
                file_put_contents("$t/passwd", $c[0]);
                file_put_contents("$t/nsswitch.conf", $c[1]);
                BrowseCheck::$seedbox = $c[2] ? $t : "$t/no-seedbox";
                $r = BrowseCheck::fenceReason();
                check($c[3] === '' ? $r === '' : strpos($r, $c[3]) !== false, "{$c[4]}: " . json_encode($r));
            }
            BrowseCheck::$seedbox = "$t/no-seedbox";
            file_put_contents("$t/passwd", $one);
            unlink("$t/nsswitch.conf");
            check(strpos(BrowseCheck::fenceReason(), 'cannot be read') !== false, 'no name-service switch file: the fence stays');
            file_put_contents("$t/nsswitch.conf", "passwd: files\n");
            BrowseCheck::$passwd = "$t/missing-passwd";
            check(strpos(BrowseCheck::fenceReason(), 'cannot be read') !== false, 'no account list: the fence stays (Detect::sharedTenancy alone would say not shared)');
        });
    },
    'browse check: the doctor line and status name the single-tenant mode, and the shared line is unchanged' => function () {
        browseCheckWorld(function ($t) {
            check(BrowseCheck::doctorLine(['guard' => []], '141.0.7390.54') === 'browse:   ready (chromium 141.0.7390.54, agent-browser 0.26.0, isolated, guard up)', 'shared: as before');
            check(BrowseCli::statusRecord()['fence'] === 'per-account paths', 'status on a shared host');
            file_put_contents("$t/passwd", "alpha:x:1000:1000::$t:/bin/sh\n");
            check(BrowseCheck::doctorLine(['guard' => []], '141.0.7390.54') === 'browse:   ready (chromium 141.0.7390.54, agent-browser 0.26.0, single-tenant host: no per-account fence, isolated, guard up)',
                BrowseCheck::doctorLine(['guard' => []], '141.0.7390.54'));
            check(strpos(BrowseCheck::doctorLine(null, '141.0.7390.54'), 'agent-browser 0.26.0, single-tenant host: no per-account fence') !== false, 'the stopped line says it too');
            check(strpos(BrowseCli::statusRecord()['fence'], 'none') === 0, 'status on a single-tenant host');
        });
    },
];
