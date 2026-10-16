<?php
/**
 * The write path shared (by copy, not by code) across lib/SecretFile.php, tools/media-servers/lib/MediaSecret.php
 * and tools/backup/lib/BackupStore.php: a secret stored atomically, 0700 dir / 0600 file, as JSON. All three had the
 * identical silent-data-loss defect: json_encode() on invalid UTF-8 returns false, file_put_contents($tmp, false)
 * writes 0 bytes and returns int 0, and `0 !== false` reads as success (review 2026-10-16, fixed in this session).
 */
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/lib/MediaSecret.php';
require_once dirname(__DIR__) . '/pikkumies/tools/backup/lib/BackupStore.php';

if (!function_exists('swtDir')) {
    function swtDir()
    {
        return testTempDir('pk-swt');
    }
}

/** Writes $code (a PHP statement string) as a runnable script with the libs loaded; returns the script path. */
if (!function_exists('swtScript')) {
    function swtScript($code)
    {
        $lib = dirname(__DIR__) . '/pikkumies/lib';
        $tools = dirname(__DIR__) . '/pikkumies/tools';
        $script = tempnam(sys_get_temp_dir(), 'pk-swt-run');
        file_put_contents($script, '<?php
require ' . var_export("$lib/Cli.php", true) . ';
require ' . var_export("$lib/SecretFile.php", true) . ';
require ' . var_export("$tools/media-servers/lib/MediaSecret.php", true) . ';
require ' . var_export("$tools/backup/lib/BackupStore.php", true) . ';
usleep(mt_rand(0, 15000));
' . $code);
        return $script;
    }
}

/** Starts $code as a real OS process, without waiting; returns the proc_open handle + its pipes. */
if (!function_exists('swtStart')) {
    function swtStart($code)
    {
        $script = swtScript($code);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return [$p, $pipes, $script];
    }
}

/** Waits for a swtStart() process; returns [exit code, stdout+stderr]. */
if (!function_exists('swtFinish')) {
    function swtFinish($started)
    {
        list($p, $pipes, $script) = $started;
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        unlink($script);
        clearstatcache();
        return [$code, $out];
    }
}

return [
    'secretfile: write() with invalid UTF-8 refuses loudly instead of storing an empty file (verified defect, fixed)' => function () {
        $dir = swtDir();
        try {
            try {
                SecretFile::write("$dir/tok", ['note' => "bad-\xFF-utf8"], 'secret');
                check(false, 'write() accepted invalid UTF-8 without error');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'JSON') !== false, 'wrong error for invalid UTF-8: ' . $e->getMessage());
            }
            check(!file_exists("$dir/tok"), 'a 0-byte secret file was created on a refused write');
            check(glob("$dir/*.tmp*") === [], 'a temp file was left behind');
        } finally {
            testRmTree($dir);
        }
    },
    'mediasecret: save() has the identical invalid-UTF-8 defect as SecretFile::write(), and the identical fix' => function () {
        $dir = swtDir();
        try {
            $ms = new MediaSecret($dir, 'plex');
            try {
                $ms->save(['note' => "bad-\xFF-utf8"]);
                check(false, 'save() accepted invalid UTF-8 without error');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'JSON') !== false, 'wrong error: ' . $e->getMessage());
            }
            check(!file_exists("$dir/plex"), 'a 0-byte credential file was created on a refused save');
        } finally {
            testRmTree($dir);
        }
    },
    'backupstore: commit()\'s repo-record write has the identical defect via a non-UTF-8 repository string, now refused' => function () {
        $dir = swtDir();
        try {
            mkdir("$dir/secrets", 0700);
            mkdir("$dir/local/backup", 0700, true);
            file_put_contents("$dir/local/backup/restic.json", json_encode(['repo' => 'sftp:me@box:good', 'saved_at' => 1]));
            $store = new BackupStore('restic', "$dir/secrets", "$dir/local");
            $store->stage("bad-\xFF-repo", 'typed-pw');
            try {
                $store->commit();
                check(false, 'commit() accepted a non-UTF-8 repository string without error');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'JSON') !== false, 'wrong error: ' . $e->getMessage());
            }
            // The write() guard throws before touching the repo file at all, so the previously-stored
            // repository record is byte-for-byte what it was before this commit() ever ran.
            check(file_get_contents("$dir/local/backup/restic.json") === json_encode(['repo' => 'sftp:me@box:good', 'saved_at' => 1]),
                'the previously-stored repository record was corrupted by the refused write');
            check(glob("$dir/local/backup/*.tmp*") === [], 'a temp file was left behind for the repo record');
        } finally {
            testRmTree($dir);
        }
    },
    'secretfile: read() tested directly: missing file, mode auto-heal, and a wrong-uid file are refused' => function () {
        $dir = swtDir();
        try {
            check(SecretFile::read("$dir/nope", 'run login') === false, 'a missing file must return false, not throw');
            SecretFile::write("$dir/tok", ['v' => 'secret-value'], 'secret');
            chmod("$dir/tok", 0644); // drift: readable by others
            $mine = fileowner("$dir/tok");
            $content = SecretFile::read("$dir/tok", 'run login', $mine);
            check(is_string($content) && strpos($content, 'secret-value') !== false, 'content lost on auto-heal: ' . var_export($content, true));
            check((fileperms("$dir/tok") & 0777) === 0600, 'mode 0644 was not healed back to 0600');
            try {
                SecretFile::read("$dir/tok", 'run login', $mine + 99999);
                check(false, 'a uid mismatch must refuse, not read the secret');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'not trusting it') !== false, 'wrong refusal message: ' . $e->getMessage());
            }
        } finally {
            testRmTree($dir);
        }
    },
    'secretfile: read() caps returned content at exactly 65536 bytes, as a byte-for-byte prefix' => function () {
        $dir = swtDir();
        try {
            $big = str_repeat('AB', 35000); // 70000 bytes, not valid JSON (read() does not parse it)
            file_put_contents("$dir/tok", $big);
            chmod("$dir/tok", 0600);
            $got = SecretFile::read("$dir/tok", 'run login', fileowner("$dir/tok"));
            check(strlen($got) === 65536, 'not capped at 65536 bytes: ' . strlen($got));
            check($got === substr($big, 0, 65536), 'returned content is not a byte-for-byte prefix of the file');
        } finally {
            testRmTree($dir);
        }
    },
    'secretfile: write() leaves a previously-stored secret untouched and cleans its temp file when rename() fails' => function () {
        $dir = swtDir();
        try {
            // A non-empty directory at the target path: renaming a regular temp file onto it always fails,
            // the way a real rename() failure (e.g. a full filesystem) would, without needing root.
            mkdir("$dir/tok", 0700);
            file_put_contents("$dir/tok/marker", 'the previously-stored secret');
            try {
                SecretFile::write("$dir/tok", ['v' => 'new'], 'secret');
                check(false, 'write() must throw when rename() cannot replace the target');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'cannot store') !== false, 'wrong error: ' . $e->getMessage());
            }
            check(is_dir("$dir/tok") && file_get_contents("$dir/tok/marker") === 'the previously-stored secret',
                'the previously-stored secret was touched by a write that failed at rename()');
            check(glob("$dir/*.tmp*") === [], 'the temp file was not cleaned up after a failed rename');
        } finally {
            testRmTree($dir);
        }
    },
    'secretfile/mediasecret/backupstore: two concurrent writers never leave a half-written or mixed secret file' => function () {
        $dir = swtDir();
        try {
            mkdir("$dir/secrets", 0700);
            mkdir("$dir/local/backup", 0700, true);
            $a1 = swtStart('SecretFile::write(' . var_export("$dir/sf", true) . ', ["v" => str_repeat("A", 500)], "secret"); echo "done";');
            $b1 = swtStart('SecretFile::write(' . var_export("$dir/sf", true) . ', ["v" => str_repeat("B", 500)], "secret"); echo "done";');
            $ra1 = swtFinish($a1);
            $rb1 = swtFinish($b1);
            $decoded = json_decode((string) file_get_contents("$dir/sf"), true);
            check(is_array($decoded) && isset($decoded['v']) && ($decoded['v'] === str_repeat('A', 500) || $decoded['v'] === str_repeat('B', 500)),
                'SecretFile::write: final file is not one complete payload: ' . json_encode($decoded));

            $a2 = swtStart('(new MediaSecret(' . var_export($dir, true) . ', "unit"))->save(["v" => str_repeat("A", 500)]); echo "done";');
            $b2 = swtStart('(new MediaSecret(' . var_export($dir, true) . ', "unit"))->save(["v" => str_repeat("B", 500)]); echo "done";');
            $ra2 = swtFinish($a2);
            $rb2 = swtFinish($b2);
            $decoded = json_decode((string) file_get_contents("$dir/unit"), true);
            check(is_array($decoded) && ($decoded['v'] === str_repeat('A', 500) || $decoded['v'] === str_repeat('B', 500)),
                'MediaSecret::save: final file is not one complete payload');

            $writeCall = static function ($dir, $content) {
                return '$store = new BackupStore("restic", ' . var_export("$dir/secrets", true) . ', ' . var_export("$dir/local", true) . ');
                    $ref = new ReflectionMethod($store, "write");
                    $ref->setAccessible(true);
                    $ref->invoke($store, ' . var_export("$dir/secrets/direct", true) . ', ' . var_export($content, true) . ');
                    echo "done";';
            };
            $a3 = swtStart($writeCall($dir, str_repeat('A', 500)));
            $b3 = swtStart($writeCall($dir, str_repeat('B', 500)));
            $ra3 = swtFinish($a3);
            $rb3 = swtFinish($b3);
            $direct = (string) file_get_contents("$dir/secrets/direct");
            check($direct === str_repeat('A', 500) || $direct === str_repeat('B', 500),
                'BackupStore private write(): final file is not one complete payload: ' . strlen($direct) . ' bytes');
            foreach ([$ra1, $rb1, $ra2, $rb2, $ra3, $rb3] as $r) {
                check($r[0] === 0 && $r[1] === 'done', 'a concurrent writer failed: ' . json_encode($r));
            }
        } finally {
            testRmTree($dir);
        }
    },
    'secretfile, mediasecret and backupstore all create their secrets directory 0700 and file 0600 with the identical literal mode' => function () {
        $dir = swtDir();
        try {
            mkdir("$dir/secrets", 0700);
            mkdir("$dir/local/backup", 0700, true);
            SecretFile::write("$dir/secretfile/tok", ['v' => 'x'], 'secret');
            (new MediaSecret("$dir/mediasecret", 'unit'))->save(['v' => 'x']);
            $store = new BackupStore('restic', "$dir/secrets", "$dir/local");
            $store->stage('sftp:me@box:r', 'pw');
            $store->commit();
            foreach ([["$dir/secretfile", "$dir/secretfile/tok"], ["$dir/mediasecret", "$dir/mediasecret/unit"], ["$dir/secrets", "$dir/secrets/restic"]] as $pair) {
                list($d, $f) = $pair;
                check((fileperms($d) & 0777) === 0700, "$d is not mode 0700: " . sprintf('%04o', fileperms($d) & 0777));
                check((fileperms($f) & 0777) === 0600, "$f is not mode 0600: " . sprintf('%04o', fileperms($f) & 0777));
            }
        } finally {
            testRmTree($dir);
        }
    },
];
