<?php
/**
 * `transcribe install-local` against real curl+tar fetching from a loopback static-file fixture
 * (tests/fixtures/mediatools/download-router.php) — the real network is never reached; the pins
 * are a test's own, never TranscribePins' real ones.
 */
require_once __DIR__ . '/fixtures/mediatools/harness.php';
require_once __DIR__ . '/../pikkumies/tools/media/transcribe/TranscribeLocal.php';

/** Real content, real sha256 — a fixture whisper.cpp source tree with just the sentinel file install-local checks for. */
function ttWhisperSrc($workDir, $dirname)
{
    return mtMakeTarGz($workDir, $dirname, ['CMakeLists.txt' => "cmake_minimum_required(VERSION 3.5)\n"]);
}

/** A fixture cmake release: the fake cmake php script at bin/cmake (needs its sibling fakelib.php). */
function ttCmakeFixture($workDir, $dirname, $binDir)
{
    return mtMakeTarGz($workDir, $dirname, [
        'bin/cmake' => (string) file_get_contents("$binDir/cmake"),
        'bin/fakelib.php' => (string) file_get_contents("$binDir/fakelib.php"),
    ]);
}

/** Pins pointing at $base's fixture files, each with its real sha256 (or a deliberately wrong one via $badKey). */
function ttPins($base, $whisperFile, $cmakeFile, $modelContent, $badKey = null)
{
    $real = ['whisper' => hash_file('sha256', $whisperFile), 'cmake' => hash_file('sha256', $cmakeFile), 'model' => hash('sha256', $modelContent)];
    if ($badKey !== null) {
        $real[$badKey] = str_repeat('0', 64);
    }
    return ['whisper' => ['url' => "$base/" . basename($whisperFile), 'sha256' => $real['whisper'], 'dirname' => basename($whisperFile, '.tar.gz')],
        'cmake' => ['url' => "$base/" . basename($cmakeFile), 'sha256' => $real['cmake'], 'dirname' => basename($cmakeFile, '.tar.gz')],
        'models' => ['base' => ['url' => "$base/model-base.bin", 'sha256' => $real['model'], 'bytes' => strlen($modelContent)],
            'small' => ['url' => "$base/model-base.bin", 'sha256' => $real['model'], 'bytes' => strlen($modelContent)]]];
}

return [
    'install-local: builds with the system (fake) cmake already on PATH, is idempotent, and the pinned downloads are verified by sha256' => function () {
        $fx = mtFixture();
        $dl = "$fx[dir]/downloads";
        mkdir($dl, 0700, true);
        $whisper = ttWhisperSrc($dl, 'whisper-src-1');
        $cmakeFixture = ttCmakeFixture($dl, 'cmake-fixture-1', $fx['bin']);
        $modelContent = 'fake-ggml-model-bytes';
        file_put_contents("$dl/model-base.bin", $modelContent);
        list($fx, $base) = mtServeDownloads($fx, $dl);
        $pins = ttPins($base, $whisper, $cmakeFixture, $modelContent);
        try {
            list($code, $out) = mtTranscribeRun($fx, ['install-local'], ['pins' => $pins]);
            $l = $code === 0 ? mtLines($out)[0] : null;
            check($code === 0 && $l['installed'] === true && $l['model'] === 'base' && in_array('source', $l['steps_run'], true)
                && in_array('build', $l['steps_run'], true) && in_array('model', $l['steps_run'], true)
                && !in_array('cmake', $l['steps_run'], true), 'first install-local run wrong: ' . $out);
            check(is_executable(TranscribeLocal::binaryPath($fx['local'])), 'the built binary is missing or not executable');
            check(is_file(TranscribeLocal::modelPath('base', $fx['local'])), 'the model file is missing');

            list($code, $out) = mtTranscribeRun($fx, ['install-local'], ['pins' => $pins]);
            $l = mtLines($out)[0];
            check($code === 0 && $l['steps_run'] === [], 'a second call should be a no-op (idempotent): ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'install-local: no cmake on PATH downloads the pinned cmake release, verifies it, and uses it to build' => function () {
        $fakes = array_values(array_diff(MT_FAKES, ['cmake'])); // no cmake fake on PATH this time
        $fx = mtFixture([], $fakes);
        $dl = "$fx[dir]/downloads";
        mkdir($dl, 0700, true);
        $whisper = ttWhisperSrc($dl, 'whisper-src-2');
        $cmakeFixture = ttCmakeFixture($dl, 'cmake-fixture-2', __DIR__ . '/fixtures/mediatools/bin'); // the real fake lives in the repo fixture, not this PATH-less $fx['bin']
        $modelContent = 'fake-ggml-model-bytes-2';
        file_put_contents("$dl/model-base.bin", $modelContent);
        list($fx, $base) = mtServeDownloads($fx, $dl);
        $pins = ttPins($base, $whisper, $cmakeFixture, $modelContent);
        try {
            list($code, $out) = mtTranscribeRun($fx, ['install-local'], ['pins' => $pins]);
            $l = $code === 0 ? mtLines($out)[0] : null;
            check($code === 0 && in_array('cmake', $l['steps_run'], true) && $l['installed'] === true, 'cmake download path wrong: ' . $out);
            check(is_file("$fx[local]/whisper/cmake/cmake-fixture-2/bin/cmake"), 'the downloaded cmake was not placed where install-local looks for it');
        } finally {
            mtDone($fx);
        }
    },
    'install-local: a sha256 mismatch deletes the download and stops, before anything is extracted or built' => function () {
        $fx = mtFixture();
        $dl = "$fx[dir]/downloads";
        mkdir($dl, 0700, true);
        $whisper = ttWhisperSrc($dl, 'whisper-src-3');
        $cmakeFixture = ttCmakeFixture($dl, 'cmake-fixture-3', $fx['bin']);
        $modelContent = 'fake-ggml-model-bytes-3';
        file_put_contents("$dl/model-base.bin", $modelContent);
        list($fx, $base) = mtServeDownloads($fx, $dl);
        $pins = ttPins($base, $whisper, $cmakeFixture, $modelContent, 'whisper'); // wrong pinned sha256 for the source tarball
        try {
            list($code, $out) = mtTranscribeRun($fx, ['install-local'], ['pins' => $pins]);
            check($code === 1 && strpos($out, 'does not match its pinned sha256') !== false, 'mismatch was not refused: ' . $out);
            check(!is_dir("$fx[local]/whisper/src"), 'source was extracted despite the sha256 mismatch');
            check((array) glob("$fx[local]/whisper/whisper-source/*.part") === [], 'a failed download was left behind');
        } finally {
            mtDone($fx);
        }
    },
    'install-local: refuses up front when gcc/g++/make are missing, before any download' => function () {
        $fakes = array_values(array_diff(MT_FAKES, ['gcc']));
        $fx = mtFixture([], $fakes);
        try {
            list($code, $out) = mtTranscribeRun($fx, ['install-local'], ['pins' => ttPins('http://127.0.0.1:1', '/dev/null', '/dev/null', 'x')]);
            check($code === 1 && strpos($out, 'gcc is not installed') !== false, 'missing gcc was not refused: ' . $out);
            check(!is_dir("$fx[local]/whisper"), 'preflight should stop before creating anything under local/whisper');
        } finally {
            mtDone($fx);
        }
    },
    'install-local: refuses when free quota is smaller than the room the build needs' => function () {
        $fx = mtFixture();
        file_put_contents("$fx[bin]/quota", "#!/usr/bin/env php\n<?php\necho \"/dev/sda1 10 10 10 100 100 100\\n\";\n");
        chmod("$fx[bin]/quota", 0755);
        try {
            list($code, $out) = mtTranscribeRun($fx, ['install-local'], ['pins' => ttPins('http://127.0.0.1:1', '/dev/null', '/dev/null', 'x')]);
            check($code === 1 && strpos($out, 'not enough free quota') !== false, 'tiny free quota was not refused: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'install-local: a leftover source directory is replaced by a PHP delete confined to the build root (no rm on PATH needed)' => function () {
        $fx = mtFixture(); // PATH holds no rm: the old `exec('rm -rf ...')` silently failed here and the move into place broke
        $dl = "$fx[dir]/downloads";
        mkdir($dl, 0700, true);
        $whisper = ttWhisperSrc($dl, 'whisper-src-5');
        $cmakeFixture = ttCmakeFixture($dl, 'cmake-fixture-5', $fx['bin']);
        file_put_contents("$dl/model-base.bin", 'fake-ggml-model-bytes-5');
        list($fx, $base) = mtServeDownloads($fx, $dl);
        mkdir("$fx[local]/whisper/src/nested", 0700, true); // an interrupted earlier run: src/ exists, CMakeLists.txt does not
        file_put_contents("$fx[local]/whisper/src/nested/stale.txt", 'old');
        try {
            list($code, $out) = mtTranscribeRun($fx, ['install-local'], ['pins' => ttPins($base, $whisper, $cmakeFixture, 'fake-ggml-model-bytes-5')]);
            check($code === 0 && in_array('source', mtLines($out)[0]['steps_run'], true), 'install over a leftover src/ failed: ' . $out);
            check(is_file("$fx[local]/whisper/src/CMakeLists.txt") && !file_exists("$fx[local]/whisper/src/nested"), 'the leftover src/ was not replaced');

            require_once dirname(__DIR__) . '/pikkumies/tools/media/transcribe/TranscribeInstall.php';
            $remove = new ReflectionMethod('TranscribeInstall', 'removeTree');
            $remove->setAccessible(true);
            mkdir("$fx[dir]/keep");
            foreach (["$fx[dir]/keep", "$fx[local]/whisper/../../keep", "$fx[local]/whisper"] as $bad) {
                $refused = false;
                try {
                    $remove->invoke(null, $bad, "$fx[local]/whisper");
                } catch (CliError $e) {
                    $refused = true;
                }
                check($refused && is_dir("$fx[dir]/keep") && is_dir("$fx[local]/whisper"), "removeTree did not refuse $bad");
            }
        } finally {
            mtDone($fx);
        }
    },
    'install-local: the real pins are https and the models are fetched at a fixed repository commit, never a moving branch' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/media/transcribe/TranscribePins.php';
        $urls = [TranscribePins::WHISPER_URL, TranscribePins::CMAKE_URL];
        foreach (TranscribePins::MODELS as $m) {
            $urls[] = $m['url'];
            check(preg_match('#/resolve/[0-9a-f]{40}/#', $m['url']) === 1, 'model not pinned to a commit: ' . $m['url']);
        }
        foreach ($urls as $u) {
            check(strpos($u, 'https://') === 0, "pin is not https: $u");
        }
    },
    'install-local: cmake extracts straight into its place, replacing a partial cmake left by an interrupted run' => function () {
        $fx = mtFixture([], array_values(array_diff(MT_FAKES, ['cmake'])));
        $dl = "$fx[dir]/downloads";
        mkdir($dl, 0700, true);
        $whisper = ttWhisperSrc($dl, 'whisper-src-6');
        $cmakeFixture = ttCmakeFixture($dl, 'cmake-fixture-6', __DIR__ . '/fixtures/mediatools/bin');
        file_put_contents("$dl/model-base.bin", 'fake-ggml-model-bytes-6');
        list($fx, $base) = mtServeDownloads($fx, $dl);
        mkdir("$fx[local]/whisper/cmake/cmake-fixture-6/share", 0700, true); // partial: no bin/cmake yet
        file_put_contents("$fx[local]/whisper/cmake/cmake-fixture-6/share/partial.txt", 'old');
        try {
            list($code, $out) = mtTranscribeRun($fx, ['install-local'], ['pins' => ttPins($base, $whisper, $cmakeFixture, 'fake-ggml-model-bytes-6')]);
            check($code === 0 && in_array('cmake', mtLines($out)[0]['steps_run'], true), 'cmake over a partial leftover failed: ' . $out);
            check(is_file("$fx[local]/whisper/cmake/cmake-fixture-6/bin/cmake") && !file_exists("$fx[local]/whisper/cmake/cmake-fixture-6/share"),
                'the partial cmake was not replaced by the verified download');
        } finally {
            mtDone($fx);
        }
    },
];
