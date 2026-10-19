<?php
/**
 * lib/Config.php beyond the happy path already covered by tests/ConfigTest.php: validName/parse
 * boundaries, write()'s two distinct failure branches, writeDefaultsIfMissing() (previously untested),
 * and load()'s degrade-on-garbage path (ADR-0032).
 */
require_once dirname(__DIR__) . '/pikkumies/lib/ConfigureTiers.php';

if (!function_exists('cfgScratchCopy')) {
    /**
     * A temp dir shaped like pikkumies/{lib,local}/, holding a copy of the real Config.php, so that a
     * subprocess requiring the copy has Config::root()/localDir() resolve under $tmp instead of the
     * real repo (writeDefaultsIfMissing() and load() take no $file override). $localBody:
     * contents to pre-seed local/config.php with, or null for none. Returns the copy's path.
     */
    function cfgScratchCopy($tmp, $localBody = null)
    {
        @mkdir("$tmp/pikkumies/lib", 0700, true);
        $copy = "$tmp/pikkumies/lib/Config.php";
        copy(dirname(__DIR__) . '/pikkumies/lib/Config.php', $copy);
        copy(dirname(__DIR__) . '/pikkumies/lib/ConfigureTiers.php', "$tmp/pikkumies/lib/ConfigureTiers.php"); // Config::set judges tiers with it (ADR-0059)
        if ($localBody !== null) {
            @mkdir("$tmp/pikkumies/local", 0700, true);
            file_put_contents("$tmp/pikkumies/local/config.php", $localBody);
        }
        return $copy;
    }
}

if (!function_exists('cfgScratchRun')) {
    /** Runs $body (PHP source using Config::) against a scratch copy in a subprocess; [code, stdout]. */
    function cfgScratchRun($copy, $body)
    {
        $script = dirname($copy) . '/run-' . mt_rand() . '.php';
        file_put_contents($script, "<?php\nrequire " . var_export($copy, true) . ";\n$body\n");
        return runPhp($script, []);
    }
}

if (!function_exists('startPhp')) {
    /** Start a PHP script with args, without waiting; returns the proc_open handle + its pipes.
     * Same helper as tests/ConcurrencyTest.php; guarded so only one definition wins when both load. */
    function startPhp($script, array $args)
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return [$p, $pipes];
    }
}

if (!function_exists('finishPhp')) {
    /** Wait for a startPhp() process; returns [exit code, stdout+stderr]. */
    function finishPhp($started)
    {
        list($p, $pipes) = $started;
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out];
    }
}

if (!function_exists('cfgSkipIfRoot')) {
    /** chmod-based permission tests cannot fail as intended under root, which ignores file modes. */
    function cfgSkipIfRoot()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            skip('running as euid 0: permission-based failure cannot be forced');
        }
    }
}

return [
    'config: validName boundaries — empty, shortest, start-anchor, codepoints, control chars' => function () {
        // #1 empty is the one input that must fall through to "Enter keeps Pikkumies", never stored valid-but-blank.
        check(Config::validName('') === false, 'empty name must be invalid');
        // #2 boundary opposite the existing 33-char-rejected case in ConfigTest.php.
        check(Config::validName('0') === true, 'a single digit must be the shortest valid name');
        // #3, #4 must start with a letter or digit per the doc comment (Config.php:47).
        check(Config::validName(' Bob') === false, 'leading space must be invalid');
        check(Config::validName('-Bob') === false && Config::validName('.Bob') === false, 'leading dash/dot must be invalid');
        // #5 ADR-0032 notes multi-byte names use up to 4 bytes/char; the regex must count codepoints (the /u
        // modifier), not bytes, at the NAME_MAX boundary — reasoned: dropping /u would make PHP's {0,N}
        // quantifier count raw bytes, so 32 three-byte codepoints (96 bytes) would exceed the limit and the
        // *valid* case below would flip to false. Verified by mutation this session (see report).
        check(Config::validName(str_repeat('界', Config::NAME_MAX)) === true, 'NAME_MAX codepoints must be valid');
        check(Config::validName(str_repeat('界', Config::NAME_MAX + 1)) === false, 'NAME_MAX+1 codepoints must be invalid');
        // #6 control characters must never reach SOUL.md/YAML rendering through a stored name.
        check(Config::validName("Bob\tHelper") === false && Config::validName("Bob\nHelper") === false, 'tab/newline must be invalid');
    },
    'config: parse error paths and the days boundary' => function () {
        // #8 the message must name the actual key, since parse()'s caller (installFlags, the configure
        // tool) surfaces it to a human who needs to know which flag/key was wrong.
        try {
            Config::parse('provider.endpoint', 'x');
            check(false, 'parse on an unknown key must throw');
        } catch (InvalidArgumentException $x) {
            check(strpos($x->getMessage(), 'provider.endpoint') !== false && strpos($x->getMessage(), 'not a setting') !== false, 'message: ' . $x->getMessage());
        }
        // #9 trim and case-fold happen together for bool parsing (Config.php:62,66).
        check(Config::parse('privacy.telemetry', '  OFF  ') === false, 'trim+fold combined');
        // #10 the days regex has no sign class; a loosened regex could silently accept a sign.
        foreach (['+5', '-1'] as $bad) {
            $threw = false;
            try {
                Config::parse('sessions.retentionDays', $bad);
            } catch (InvalidArgumentException $x) {
                $threw = true;
            }
            check($threw, "parse accepted a signed day count: $bad");
        }
        // #11 the real boundary: ConfigTest.php only checks oversized '99999'; this pins DAYS_MAX itself.
        check(Config::parse('sessions.retentionDays', (string) Config::DAYS_MAX) === Config::DAYS_MAX, 'DAYS_MAX itself must parse');
        $over = false;
        try {
            Config::parse('sessions.retentionDays', (string) (Config::DAYS_MAX + 1));
        } catch (InvalidArgumentException $x) {
            $over = true;
        }
        check($over, 'DAYS_MAX+1 must be rejected');
        // #12 a loosened regex + (int) cast would silently truncate '7.5' to 7 instead of erroring.
        $decimal = false;
        try {
            Config::parse('sessions.retentionDays', '7.5');
        } catch (InvalidArgumentException $x) {
            $decimal = true;
        }
        check($decimal, 'a decimal day count must be rejected, not truncated');
        // #13 the only path letting a person clear agent.name via parse (vs. an invalid non-empty name).
        check(Config::parse('agent.name', '') === '', 'empty must clear the name');
        $invalid = false;
        try {
            Config::parse('agent.name', '***');
        } catch (InvalidArgumentException $x) {
            $invalid = true;
        }
        check($invalid, 'a non-empty invalid name must still throw');
    },
    'config: write() rebuilds a hand-corrupted non-array section instead of merging into it or crashing' => function () {
        // #14 guards the `!is_array($local[$section])` reset branch (Config.php:123-125) against a fatal
        // on a hand-edited config.php. Reasoned: without that `!is_array()` check, `$local['privacy']['telemetry']
        // = true` on a string would throw "Cannot use a scalar value as an array" — a crash, not a repair.
        $dir = testTempDir('pk-write-section');
        try {
            $file = "$dir/config.php";
            file_put_contents($file, "<?php\nreturn ['privacy' => 'broken'];\n");
            Config::set('privacy.telemetry', true, $file);
            $local = require $file;
            check($local === ['privacy' => ['telemetry' => true]], 'corrupted section was not rebuilt: ' . json_encode($local));
        } finally {
            testRmTree($dir);
        }
    },
    "config: write() throws 'could not lock' when the lock file cannot be created" => function () {
        cfgSkipIfRoot();
        // #15 first of two distinct failure branches in write() (Config.php:115-118); zero coverage before this.
        $dir = testTempDir('pk-write-lock');
        try {
            chmod($dir, 0500); // no write permission: @fopen($file.'.lock','c') must fail
            $threw = false;
            try {
                Config::set('agent.name', 'X', "$dir/config.php");
            } catch (RuntimeException $x) {
                $threw = strpos($x->getMessage(), 'could not lock') !== false;
            }
            check($threw, 'write() must throw "could not lock" when the lock file cannot be created');
        } finally {
            chmod($dir, 0700);
            testRmTree($dir);
        }
    },
    "config: write() throws 'disk full' and leaves no tmp file when rename() targets a directory" => function () {
        // #16 second distinct failure branch (lock ok, rename fails): existing suite only covers the
        // happy-path tmp cleanup. A directory sitting at $file makes @rename($tmp, $file) fail every time.
        $dir = testTempDir('pk-write-rename');
        try {
            $file = "$dir/config.php";
            mkdir($file, 0700);
            $threw = false;
            try {
                Config::set('agent.name', 'X', $file);
            } catch (RuntimeException $x) {
                $threw = strpos($x->getMessage(), 'disk full') !== false;
            }
            check($threw, 'write() must throw "disk full" when rename() cannot replace a directory');
            check(!glob("$file.tmp-*"), 'a failed write must not leave a temporary file behind');
        } finally {
            testRmTree($dir);
        }
    },
    'config: two processes calling Config::set on the same file concurrently never corrupt it' => function () {
        // #17 flock is the only thing serializing two settings-writers; jitter (usleep before the write,
        // the same technique tests/ConcurrencyTest.php uses) makes the two writers actually overlap instead
        // of one finishing before the other starts.
        $dir = testTempDir('pk-write-race');
        try {
            $file = "$dir/config.php";
            $script = "$dir/race.php";
            $lib = dirname(__DIR__) . '/pikkumies/lib/Config.php';
            file_put_contents($script, '<?php
require ' . var_export($lib, true) . ';
usleep(mt_rand(0, 15000));
Config::set($argv[1], $argv[2] === "1", $argv[3]);
echo "ok";
');
            $a = startPhp($script, ['privacy.telemetry', '1', $file]);
            $b = startPhp($script, ['privacy.perfLogging', '0', $file]);
            list($ca, $oa) = finishPhp($a);
            list($cb, $ob) = finishPhp($b);
            check($ca === 0 && $cb === 0 && $oa === 'ok' && $ob === 'ok', "a racing Config::set crashed: $oa / $ob");
            $local = require $file;
            check($local['privacy']['telemetry'] === true && $local['privacy']['perfLogging'] === false, 'both writers\' keys must survive: ' . json_encode($local));
        } finally {
            testRmTree($dir);
        }
    },
    'config: write() reuses one lock file across repeated sets, never accumulates them' => function () {
        // #18 a leak would litter local/ with lock files over an install's lifetime.
        $dir = testTempDir('pk-write-lockreuse');
        try {
            $file = "$dir/config.php";
            for ($i = 0; $i < 5; $i++) {
                Config::set('agent.name', "Name$i", $file);
            }
            check(count(glob("$file.lock*")) === 1, 'exactly one lock file must exist after 5 sets');
        } finally {
            testRmTree($dir);
        }
    },
    "config: SETTABLE's contract matches the configure tool's USAGE.md and ADR-0032's stated limits" => function () {
        // #7, #20, #21 docs/code drift guards. SETTABLE is read live, not hardcoded, so a future addition
        // or type change here fails these at the first place that would go stale.
        check(Config::SETTABLE === ['agent.name' => 'name', 'privacy.perfLogging' => 'bool', 'privacy.telemetry' => 'bool', 'sessions.retentionDays' => 'days', 'update.channel' => 'channel',
            'provider.model' => 'model', 'hooks.model' => 'model', 'tools.disabled' => 'names', 'skills.disabled' => 'names', 'root.disabled' => 'bool', 'searchFirst.disabled' => 'bool',
            'halt.disabled' => 'bool', 'lowBalance.disabled' => 'bool', 'feedback.ask' => 'bool', 'fileopsCheck.disabled' => 'bool', 'hooks.reword' => 'bool', 'hooks.llm.disabled' => 'bool', 'hooks.async.disabled' => 'bool',
            'autoUpdate.disabled' => 'bool', 'cron.disabled' => 'bool', 'hermes.lazyInstalls' => 'bool', 'ssh.allow' => 'hosts', 'ssh.deny' => 'hosts',
            'ssh.blockUnknown' => 'bool'], 'SETTABLE drifted: ' . json_encode(Config::SETTABLE));
        // USAGE.md's tier table names every key with a rule (ADR-0059), so the person reading it sees every setting.
        $usage = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/USAGE.md');
        check(preg_match('/^\| set \|.*\|$/m', $usage) === 1, 'USAGE.md must have a `set` row');
        foreach (array_merge(array_keys(Config::SETTABLE), array_keys(ConfigureTiers::RULES), ['arr.<app>.config', 'host.*']) as $key) {
            check(strpos($usage, "`$key`") !== false, "USAGE.md is missing $key");
        }
        $adr = (string) file_get_contents(dirname(__DIR__) . '/docs/adr/0032-settings-configure-skill-first-run-and-install-flags.md');
        check(strpos($adr, '1-' . Config::NAME_MAX) !== false, 'ADR-0032 must state the name limit as 1-' . Config::NAME_MAX);
        check(strpos($adr, '0-' . Config::DAYS_MAX) !== false, 'ADR-0032 must state the days limit as 0-' . Config::DAYS_MAX);
    },
    'config: writeDefaultsIfMissing creates the documented minimal stub, never touches an existing file, forces 0600, and makes a missing local/ at 0700' => function () {
        // #22-25, run against a scratch copy of Config.php because writeDefaultsIfMissing() has no $file
        // override and always targets self::localDir() — calling it in-process would write the real
        // pikkumies/local/config.php (forbidden).
        $dir = testTempDir('pk-wdim-stub');
        try {
            $copy = cfgScratchCopy($dir);
            list($code, $out) = cfgScratchRun($copy, '
                $r = Config::writeDefaultsIfMissing();
                echo json_encode(["result" => $r, "local" => require Config::localDir() . "/config.php", "dirMode" => fileperms(Config::localDir()) & 0777, "fileMode" => fileperms(Config::localDir() . "/config.php") & 0777]);
            ');
            check($code === 0, "script failed: $out");
            $data = json_decode($out, true);
            check($data['result'] === true, 'first call must report it wrote the file');
            check($data['local'] === ['provider' => ['endpoint' => '', 'model' => '']], 'must write the documented minimal stub, not the full defaults: ' . json_encode($data['local']));
            check($data['dirMode'] === 0700, 'local/ must be created at 0700: ' . decoct($data['dirMode']));
            check($data['fileMode'] === 0600, 'config.php must be 0600: ' . decoct($data['fileMode']));
        } finally {
            testRmTree($dir);
        }

        $dir2 = testTempDir('pk-wdim-notouch');
        try {
            $copy = cfgScratchCopy($dir2, 'garbage, not even valid PHP');
            list($code, $out) = cfgScratchRun($copy, '
                $before = file_get_contents(Config::localDir() . "/config.php");
                $r = Config::writeDefaultsIfMissing();
                $after = file_get_contents(Config::localDir() . "/config.php");
                echo json_encode(["result" => $r, "unchanged" => $before === $after]);
            ');
            check($code === 0, "script failed: $out");
            $data = json_decode($out, true);
            check($data['result'] === false && $data['unchanged'] === true, 'an existing file, even garbage, must never be touched: ' . $out);
        } finally {
            testRmTree($dir2);
        }

        $dir3 = testTempDir('pk-wdim-umask');
        try {
            $copy = cfgScratchCopy($dir3);
            list($code, $out) = cfgScratchRun($copy, '
                umask(0022);
                Config::writeDefaultsIfMissing();
                echo fileperms(Config::localDir() . "/config.php") & 0777;
            ');
            check($code === 0 && (int) $out === 0600, "a permissive umask must not weaken the explicit chmod: $out");
        } finally {
            testRmTree($dir3);
        }
    },
    "config: writeDefaultsIfMissing can no longer report success while writing nothing (the gap this package closes)" => function () {
        cfgSkipIfRoot();
        // #26 CONFIRMED REAL before the fix: unlike write(), writeDefaultsIfMissing() never checked
        // file_put_contents()'s return value, so a dir with no write permission produced
        // {"result":true,"isFile":false} plus two unsuppressed PHP warnings on stderr (verified this
        // session against the pre-fix code: result=true, isFile=false). The fix adds the same
        // lock+tmp-file+checked-rename pattern Config::write() already used.
        $dir = testTempDir('pk-wdim-gap');
        try {
            $copy = cfgScratchCopy($dir);
            $localDir = dirname(dirname($copy)) . '/local';
            // Pre-create local/ ourselves (unwritable) so writeDefaultsIfMissing()'s own @mkdir is a
            // harmless no-op and the directory is unwritable by the time it tries to write the file.
            mkdir($localDir, 0500, true);
            list($code, $out) = cfgScratchRun($copy, '
                $r = Config::writeDefaultsIfMissing();
                echo json_encode(["result" => $r, "isFile" => is_file(Config::localDir() . "/config.php")]);
            ');
            chmod($localDir, 0700);
            check($code === 0, "script failed: $out");
            $data = json_decode($out, true);
            check($data['result'] === false, 'must report false, never true, when nothing was written: ' . $out);
            check($data['isFile'] === false, 'no file should exist: ' . $out);
        } finally {
            testRmTree($dir);
        }
    },
    "config: two near-simultaneous writeDefaultsIfMissing calls on a brand-new dir don't throw or corrupt the file" => function () {
        // #27 bin/pikkumies's header promises "any number of instances... written through a lock or an
        // atomic rename" (now true for this function too, after the fix above).
        $dir = testTempDir('pk-wdim-race');
        try {
            $copy = cfgScratchCopy($dir);
            $script = dirname($copy) . '/race.php';
            file_put_contents($script, '<?php
require ' . var_export($copy, true) . ';
usleep(mt_rand(0, 15000));
echo Config::writeDefaultsIfMissing() ? "wrote" : "noop";
');
            $a = startPhp($script, []);
            $b = startPhp($script, []);
            list($ca, $oa) = finishPhp($a);
            list($cb, $ob) = finishPhp($b);
            check($ca === 0 && $cb === 0, "a racing writeDefaultsIfMissing crashed: $oa / $ob");
            check(in_array([$oa, $ob], [['wrote', 'noop'], ['noop', 'wrote']], true), "exactly one side must have written: $oa / $ob");
            $local = require dirname(dirname($copy)) . '/local/config.php';
            check($local === ['provider' => ['endpoint' => '', 'model' => '']], 'the file must still parse as the documented stub: ' . json_encode($local));
        } finally {
            testRmTree($dir);
        }
    },
    'config: load() degrades to plain defaults when local/config.php returns a non-array, and a full round trip preserves overrides and defaults alike' => function () {
        // #28 load() is on every entrypoint's hot path; a malformed local file must never crash it.
        $dir = testTempDir('pk-load-degrade');
        try {
            $copy = cfgScratchCopy($dir, "<?php\nreturn 'oops';\n");
            list($code, $out) = cfgScratchRun($copy, 'echo (Config::load() === Config::defaults()) ? "same" : "different";');
            check($code === 0 && $out === 'same', "a non-array local file must degrade to exactly Config::defaults(): $out");
        } finally {
            testRmTree($dir);
        }

        // #29 catches a merge-direction bug letting a default silently clobber a real override.
        $dir2 = testTempDir('pk-load-roundtrip');
        try {
            $copy = cfgScratchCopy($dir2);
            list($code, $out) = cfgScratchRun($copy, '
                Config::set("agent.name", "Helper", Config::localDir() . "/config.php");
                Config::set("privacy.telemetry", false, Config::localDir() . "/config.php");
                Config::set("sessions.retentionDays", 30, Config::localDir() . "/config.php");
                $c = Config::load();
                echo json_encode(["name" => $c["agent"]["name"], "telemetry" => $c["privacy"]["telemetry"], "perfLogging" => $c["privacy"]["perfLogging"], "days" => $c["sessions"]["retentionDays"], "terms" => $c["terms"]["accepted"]]);
            ');
            check($code === 0, "script failed: $out");
            $data = json_decode($out, true);
            check($data['name'] === 'Helper' && $data['telemetry'] === false && $data['days'] === 30, "3 overrides must survive: $out");
            check($data['perfLogging'] === true && $data['terms'] === false, "untouched defaults must survive alongside overrides: $out");
        } finally {
            testRmTree($dir2);
        }
    },
];
