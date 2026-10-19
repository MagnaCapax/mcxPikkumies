<?php
/** lib/MediaStackCredentials.php against the PMSS installer's own file format, the lock and the refusals. */
$mscFile = "# PMSS media stack credentials\n# This file is owner-readable only; app auth is separate from the public proxy.\n"
    . "SONARR_URL = https://host.example.com/public-exampleuser/sonarr/\nSONARR_USERNAME = exampleuser\nSONARR_PASSWORD = Old-Value-0001\n"
    . "RADARR_USERNAME = exampleuser\nRADARR_PASSWORD = [existing Radarr password preserved; not known to installer]\n"
    . "JELLYFIN_USERNAME = exampleuser\nJELLYFIN_PASSWORD = Jf-Value-0002\r\n";

return [
    'media-stack credentials: parsed as the installer writes it; placeholder and missing lines are no value; jellyfin reads it the same way' => function () use ($mscFile) {
        $home = testTempDir('pk-msc');
        try {
            file_put_contents("$home/.media-stack-credentials.txt", $mscFile);
            $v = MediaStackCredentials::read($home);
            check($v['SONARR_PASSWORD'] === 'Old-Value-0001' && $v['JELLYFIN_PASSWORD'] === 'Jf-Value-0002', 'parse: ' . json_encode($v));
            check(MediaStackCredentials::state($v, 'RADARR_PASSWORD') === 'placeholder' && MediaStackCredentials::value($v, 'RADARR_PASSWORD') === null, 'placeholder');
            check(MediaStackCredentials::state($v, 'LIDARR_PASSWORD') === 'missing' && MediaStackCredentials::state(null, 'X') === 'no-file', 'missing');
            check(MediaStackCredentials::appKey('sonarr') === 'SONARR' && MediaStackCredentials::appKey('autobrr-session') === 'AUTOBRR_SESSION', 'app key');
            require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/jellyfin/JellyfinAuth.php';
            check(JellyfinAuth::pmssCredentials($home) === ['user' => 'exampleuser', 'password' => 'Jf-Value-0002'], 'jellyfin reads the shared parser');
            check(MediaStackCredentials::read("$home/none") === null, 'no file');
        } finally {
            testRmTree($home);
        }
    },
    'media-stack credentials: set() replaces every line of a key, appends a new one, keeps the rest byte for byte, 0600; its return undoes it' => function () use ($mscFile) {
        $home = testTempDir('pk-msc');
        try {
            $f = "$home/.media-stack-credentials.txt";
            file_put_contents($f, $mscFile . "SONARR_PASSWORD = duplicate\n");
            chmod($f, 0644);
            $old = MediaStackCredentials::set($home, ['SONARR_PASSWORD' => 'New-Value-0003', 'LIDARR_PASSWORD' => 'New-Value-0004']);
            check($old === ['SONARR_PASSWORD' => 'Old-Value-0001', 'LIDARR_PASSWORD' => null], 'old values: ' . json_encode($old));
            $text = file_get_contents($f);
            $want = str_replace(['SONARR_PASSWORD = Old-Value-0001', 'SONARR_PASSWORD = duplicate'], 'SONARR_PASSWORD = New-Value-0003', $mscFile . "SONARR_PASSWORD = duplicate\n")
                . "LIDARR_PASSWORD = New-Value-0004\n";
            check($text === $want, "rewritten file:\n$text");
            clearstatcache();
            check((fileperms($f) & 0777) === 0600, sprintf('mode %o', fileperms($f) & 0777));
            MediaStackCredentials::set($home, $old);
            $back = file_get_contents($f);
            check(strpos($back, 'LIDARR_PASSWORD') === false && strpos($back, 'SONARR_PASSWORD = Old-Value-0001') !== false
                && strpos($back, 'JELLYFIN_PASSWORD = Jf-Value-0002') !== false, "undo:\n$back");
            check(!glob("$home/.media-stack-credentials.txt.pk*"), 'a temporary file was left behind');
            $err = '';
            try {
                MediaStackCredentials::set($home, ['SONARR_PASSWORD' => "two\nlines"]);
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            check(strpos($err, 'not a plain one-line value') !== false && strpos(file_get_contents($f), "two") === false, 'a newline was written: ' . $err);
        } finally {
            testRmTree($home);
        }
    },
    'media-stack credentials: a new file gets the installer header; a symlinked file is refused and never followed' => function () {
        $home = testTempDir('pk-msc');
        try {
            MediaStackCredentials::set($home, ['LIDARR_PASSWORD' => 'New-Value-0005']);
            check(file_get_contents("$home/.media-stack-credentials.txt") === "# PMSS media stack credentials\n"
                . "# This file is owner-readable only; app auth is separate from the public proxy.\nLIDARR_PASSWORD = New-Value-0005\n", 'new file');
            $other = testTempDir('pk-msc-other');
            file_put_contents("$other/target", "KEEP = me\n");
            unlink("$home/.media-stack-credentials.txt");
            symlink("$other/target", "$home/.media-stack-credentials.txt");
            $err = '';
            try {
                MediaStackCredentials::set($home, ['LIDARR_PASSWORD' => 'New-Value-0006']);
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            check(strpos($err, 'is a link') !== false && file_get_contents("$other/target") === "KEEP = me\n", 'symlink: ' . $err);
            testRmTree($other);
        } finally {
            testRmTree($home);
        }
    },
    'media-stack credentials: a writer waits for the lock and re-reads inside it, so a change made meanwhile is kept' => function () {
        $home = testTempDir('pk-msc');
        try {
            $f = "$home/.media-stack-credentials.txt";
            file_put_contents($f, "A_PASSWORD = one\n");
            // Another writer: holds the lock, changes the file, waits, then lets go.
            $child = "$home/child.php";
            file_put_contents($child, '<?php $l = fopen(' . var_export("$f.lock", true) . ', "c"); flock($l, LOCK_EX); touch('
                . var_export("$home/locked", true) . '); usleep(300000); file_put_contents(' . var_export("$f.x", true)
                . ', "A_PASSWORD = one\nC_PASSWORD = three\n"); rename(' . var_export("$f.x", true) . ', ' . var_export($f, true)
                . '); usleep(300000); flock($l, LOCK_UN);');
            $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child), [], $pipes);
            for ($i = 0; $i < 100 && !file_exists("$home/locked"); $i++) {
                usleep(20000);
            }
            $t = microtime(true);
            MediaStackCredentials::set($home, ['B_PASSWORD' => 'two']);
            $waited = microtime(true) - $t;
            proc_close($p);
            $text = file_get_contents($f);
            check($waited > 0.3, "set() did not wait for the lock ($waited s)");
            check($text === "A_PASSWORD = one\nC_PASSWORD = three\nB_PASSWORD = two\n", "the other writer's change was lost:\n$text");
        } finally {
            testRmTree($home);
        }
    },
    'media-stack credentials: a symlinked or foreign lock file is refused before anything is opened, created or written' => function () use ($mscFile) {
        $home = testTempDir('pk-msc');
        try {
            $f = "$home/.media-stack-credentials.txt";
            file_put_contents($f, $mscFile);
            chmod($f, 0600);
            $before = file_get_contents($f);
            file_put_contents("$home/elsewhere", 'keep');
            chmod("$home/elsewhere", 0644);
            foreach (["$home/elsewhere", "$home/not-yet-there"] as $target) {
                @unlink("$f.lock");
                symlink($target, "$f.lock");
                $err = '';
                try {
                    MediaStackCredentials::set($home, ['SONARR_PASSWORD' => 'New-Value-0003']);
                } catch (CliError $e) {
                    $err = $e->getMessage();
                }
                clearstatcache();
                check(strpos($err, 'refusing ~/.media-stack-credentials.txt.lock') === 0, "link to $target: " . $err);
                check(file_get_contents($f) === $before && !file_exists("$home/not-yet-there") && file_get_contents("$home/elsewhere") === 'keep'
                    && (fileperms("$home/elsewhere") & 0777) === 0644, "link to $target: something was written or followed");
            }
            unlink("$f.lock");
            mkdir("$f.lock");
            try {
                MediaStackCredentials::set($home, ['SONARR_PASSWORD' => 'New-Value-0003']);
                check(false, 'a folder in place of the lock file was used');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'refusing') === 0 && file_get_contents($f) === $before, 'folder lock: ' . $e->getMessage());
            }
            rmdir("$f.lock");
            MediaStackCredentials::set($home, ['SONARR_PASSWORD' => 'New-Value-0003']);
            clearstatcache();
            check(MediaStackCredentials::read($home)['SONARR_PASSWORD'] === 'New-Value-0003' && is_file("$f.lock") && !is_link("$f.lock")
                && (fileperms("$f.lock") & 0777) === 0600, 'a plain lock file works and is 0600');
        } finally {
            testRmTree($home);
        }
    },
];
