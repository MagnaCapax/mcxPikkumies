<?php
/**
 * RffmpegVenv::install against fake network/venv/pip seams (tests/fixtures/rffmpeg/harness.php):
 * checksum enforcement, the user-space layout (never a system path), the D1/D2/D3 fixes (Review fixes
 * 2), --force's new confirmation gate, and that remove() undoes exactly what install created. One
 * test (last) runs with NO seam overrides for venv/pip/init -- a real python3, a real hash-pinned pip
 * install and a real run of the pinned script's own `init --no-root -y` against the generated config,
 * which is what originally caught D1 (fake-only coverage hid it, Review D5).
 */
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegState.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegVenv.php';
require_once __DIR__ . '/fixtures/rffmpeg/harness.php';

return [
    'rffmpeg install: a checksum mismatch refuses before any venv or script is written' => function () {
        $fx = rfFixture();
        try {
            $p = RffmpegState::paths($fx['local']);
            $seams = rfInstallSeams($p['db_dir']);
            $seams['scriptSha256'] = str_repeat('0', 64); // deliberately wrong
            try {
                RffmpegVenv::install($seams, $fx['local'], []);
                check(false, 'a checksum mismatch should have refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'checksum') !== false, 'wrong refusal: ' . $e->getMessage());
            }
            check(!is_file($p['script']) && !is_dir($p['venv'] . '/bin'), 'a mismatch must not reach venv creation');
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg install: everything lands under local/state, never a system path; the config is nested under rffmpeg:, and the ffmpeg/ffprobe paths have no "rffmpeg" in them' => function () {
        $fx = rfFixture();
        try {
            $p = RffmpegState::paths($fx['local']);
            check(strpos($p['base'], '/local/state/rffmpeg') !== false, 'base path drifted: ' . $p['base']);
            $out = RffmpegVenv::install(rfInstallSeams($p['db_dir']), $fx['local'], []);
            $rec = $out[0];
            check($rec['ok'] === true && $rec['installed'] === true, 'install did not report ok: ' . json_encode($rec));

            // D2: the pinned script's own __main__ checks "rffmpeg" in sys.argv[0] (the invoked path).
            check(strpos($p['transcode_ffmpeg'], 'rffmpeg') === false && strpos($p['transcode_ffprobe'], 'rffmpeg') === false,
                'the ffmpeg/ffprobe entry points must never contain "rffmpeg" in their own path: ' . $p['transcode_ffmpeg']);
            check(is_file($p['transcode_ffmpeg']) && is_executable($p['transcode_ffmpeg']) && is_file($p['transcode_ffprobe']) && is_executable($p['transcode_ffprobe']),
                'the ffmpeg/ffprobe wrappers were not created executable');
            $ffmpegBody = file_get_contents($p['transcode_ffmpeg']);
            check(strpos($ffmpegBody, 'RFFMPEG_CONFIG') !== false, 'D3: the ffmpeg wrapper does not export RFFMPEG_CONFIG: ' . $ffmpegBody);
            check(is_link($p['transcode_target_ffmpeg']) && is_link($p['transcode_target_ffprobe']), 'the clean-path targets are not symlinks');
            check(readlink($p['transcode_target_ffmpeg']) === '../rffmpeg/rffmpeg', 'ffmpeg target symlink points at the wrong place');

            check(is_file($p['db_dir'] . '/rffmpeg.db'), 'rffmpeg init did not create its db');
            $cfg = file_get_contents($p['config']);
            check(strpos($cfg, "rffmpeg:\n") === 0, 'D1: the config must be nested under the top-level rffmpeg: key: ' . $cfg);
            check(strpos($cfg, "  directories:\n") !== false, 'D1: directories: must be nested (2-space indent) under rffmpeg:, not top level: ' . $cfg);
            check(strpos($cfg, 'ssh:') !== false && strpos($cfg, $p['ssh_wrapper']) !== false, 'commands.ssh does not point at the generated security wrapper');
            check(is_file($p['ssh_wrapper']) && is_executable($p['ssh_wrapper']), 'the ssh security wrapper was not generated');

            check($rec['deps_frozen'] === ['click==' . RffmpegVenv::CLICK_VERSION, 'PyYAML==' . RffmpegVenv::PYYAML_VERSION],
                'pip deps not recorded: ' . json_encode($rec['deps_frozen']));
            check(is_file($p['requirements']) && strpos(file_get_contents($p['requirements']), '--hash=sha256:') !== false,
                'CWE-494: the requirements file is not hash-pinned');
            $state = RffmpegState::read($fx['local']);
            check($state['installed'] === true && $state['hosts'] === [], 'state.json not written correctly: ' . json_encode($state));
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg install: refuses a second install without --force; --force needs --with-data too (CWE-862) and refuses while hosts are recorded' => function () {
        $fx = rfFixture();
        try {
            $p = RffmpegState::paths($fx['local']);
            RffmpegVenv::install(rfInstallSeams($p['db_dir'], "#!/usr/bin/env python3\nfirst\n"), $fx['local'], []);
            try {
                RffmpegVenv::install(rfInstallSeams($p['db_dir']), $fx['local'], []);
                check(false, 'a second install without --force should have refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'already installed') !== false, 'wrong refusal: ' . $e->getMessage());
            }
            try {
                RffmpegVenv::install(rfInstallSeams($p['db_dir']), $fx['local'], ['force' => true]);
                check(false, '--force without --with-data should have refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), '--with-data') !== false, 'wrong refusal: ' . $e->getMessage());
            }
            $withHost = RffmpegState::read($fx['local']);
            $withHost['hosts'] = ['gpubox' => ['host' => 'h']];
            RffmpegState::write($fx['local'], $withHost);
            try {
                RffmpegVenv::install(rfInstallSeams($p['db_dir']), $fx['local'], ['force' => true, 'with-data' => true]);
                check(false, '--force while a host is recorded should have refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'hosts are recorded') !== false, 'wrong refusal: ' . $e->getMessage());
            }
            $noHost = RffmpegState::read($fx['local']);
            $noHost['hosts'] = [];
            RffmpegState::write($fx['local'], $noHost);
            RffmpegVenv::install(rfInstallSeams($p['db_dir'], "#!/usr/bin/env python3\nsecond\n"), $fx['local'], ['force' => true, 'with-data' => true]);
            check(strpos(file_get_contents($p['script']), 'second') !== false, '--force did not replace the pinned script');
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg remove (local side): removes exactly what install created, nothing else; a top-level symlink is unlinked, never descended into (CWE-59)' => function () {
        $fx = rfFixture();
        try {
            $p = RffmpegState::paths($fx['local']);
            RffmpegVenv::install(rfInstallSeams($p['db_dir']), $fx['local'], []);
            $sentinel = $p['base'] . '/../sentinel.txt';
            file_put_contents($sentinel, 'untouched');

            // CWE-59: plant log_dir as a symlink to a directory with data the agent must never touch.
            testRmTree($p['log_dir']);
            $victim = $fx['dir'] . '/victim';
            mkdir($victim, 0700, true);
            file_put_contents("$victim/do-not-touch.txt", 'victim data');
            symlink($victim, $p['log_dir']);

            $removed = RffmpegVenv::removeLocal($fx['local']);
            check(!is_file($p['script']) && !is_file($p['transcode_ffmpeg']) && !is_link($p['transcode_target_ffmpeg']) && !is_file($p['config']),
                'not everything local was removed');
            check(!is_dir($p['venv']) && !is_dir($p['db_dir']) && !is_dir($p['wrappers']) && !is_dir($p['transcode_base']), 'directories install created were left behind');
            check(is_file($sentinel), 'remove touched something outside its own state base');
            check(!is_link($p['log_dir']) && is_file("$victim/do-not-touch.txt"), 'CWE-59: the planted symlink was followed into the victim directory');
            check(in_array($p['script'], $removed, true) && in_array($p['venv'], $removed, true) && in_array($p['log_dir'], $removed, true),
                'removed-list missing expected paths: ' . json_encode($removed));
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg remove (local side): --with-data without --allow-shell NEVER removes the log (--force path); removeLocal($dir, true) keeps it' => function () {
        $fx = rfFixture();
        try {
            $p = RffmpegState::paths($fx['local']);
            RffmpegVenv::install(rfInstallSeams($p['db_dir']), $fx['local'], []);
            file_put_contents($p['log_dir'] . '/rffmpeg.log', 'history to keep');
            $removed = RffmpegVenv::removeLocal($fx['local'], true);
            check(is_dir($p['log_dir']) && is_file($p['log_dir'] . '/rffmpeg.log'), 'the log must survive a keepLog removal');
            check(!in_array($p['log_dir'], $removed, true), 'log_dir must not be reported as removed when kept');
            check(!is_file($p['script']) && !is_dir($p['venv']), 'everything else should still be removed');
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg install: a REAL python3 venv, a real hash-pinned pip install and a real run of the pinned script\'s init --no-root -y against the generated (D1-nested) config' => function () {
        $python = ToolProc::which('python3');
        if ($python === null) {
            skip('python3 is not on PATH; cannot run a real venv+init check here');
        }
        $help = ToolProc::run([$python, '-m', 'venv', '--help']);
        if ($help['code'] !== 0) {
            skip('python3 -m venv is not available (no python3-venv package?); cannot run a real venv+init check here');
        }
        // The pinned upstream script is GPL and is not shipped in this repository: point PIKKUMIES_RFFMPEG_SCRIPT at a copy of
        // the exact pinned file (the RffmpegVenv::SCRIPT_URL tag) to run this real install check; without it the test skips.
        $fixtureScript = (string) getenv('PIKKUMIES_RFFMPEG_SCRIPT');
        $scriptBody = $fixtureScript !== '' && is_file($fixtureScript) ? file_get_contents($fixtureScript) : false;
        if ($scriptBody === false || hash('sha256', $scriptBody) !== RffmpegVenv::SCRIPT_SHA256) {
            skip('set PIKKUMIES_RFFMPEG_SCRIPT to a copy of the pinned rffmpeg script (sha256 RffmpegVenv::SCRIPT_SHA256) to run the real install check');
        }
        $fx = rfFixture();
        try {
            $p = RffmpegState::paths($fx['local']);
            // Only the download is faked (a local, checksum-verified copy of the exact pinned bytes,
            // so this test never depends on GitHub being reachable); venv creation, pip (hash-pinned,
            // real PyPI) and running `init --no-root -y` are all real -- this is what actually caught
            // D1 (the missing top-level rffmpeg: key) when every other test used a fake runScript.
            $seams = ['fetchScript' => function ($url) use ($scriptBody) {
                return ['code' => 200, 'body' => $scriptBody, 'error' => ''];
            }];
            $out = RffmpegVenv::install($seams, $fx['local'], []);
            $rec = $out[0];
            check($rec['ok'] === true, 'the real install did not report ok: ' . json_encode($rec));
            check(is_file($p['db_dir'] . '/rffmpeg.db'), 'the real pinned script\'s own init --no-root -y did not create its db -- check ' . $p['config']);
            $cfg = file_get_contents($p['config']);
            check(strpos($cfg, "rffmpeg:\n") === 0, 'the generated config the real script accepted is not what later installs will also generate: ' . $cfg);
        } finally {
            rfDone($fx);
        }
    },
];
