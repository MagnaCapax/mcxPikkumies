<?php
/**
 * The transcribe unit: login, status, and `run` against a fake cloud endpoint
 * (tests/fixtures/mediatools/router.php) and fake ffmpeg/whisper-cli/nproc on a temporary PATH.
 */
require_once __DIR__ . '/fixtures/mediatools/harness.php';

/** A tiny real file: ffmpeg/ffprobe are faked, so its content never matters, only its size. */
function ttAudio(array $fx, $bytes = 100)
{
    $f = "$fx[home]/call.mp3";
    file_put_contents($f, str_repeat('a', $bytes));
    return $f;
}

/** Puts a working fake local engine in place, as install-local would have left it. */
function ttInstallFakeLocal(array $fx, $text = 'local transcript')
{
    mtSetState($fx, ['whisper_text' => $text]);
    mkdir("$fx[local]/whisper/build/bin", 0700, true);
    copy("$fx[bin]/whisper-cli", "$fx[local]/whisper/build/bin/whisper-cli");
    copy("$fx[bin]/fakelib.php", "$fx[local]/whisper/build/bin/fakelib.php"); // whisper-cli's fake requires its sibling
    chmod("$fx[local]/whisper/build/bin/whisper-cli", 0755);
    mkdir("$fx[local]/whisper/models", 0700, true);
    file_put_contents("$fx[local]/whisper/models/ggml-base.bin", 'fake model');
}

return [
    'transcribe login: refuses without a terminal, requires https unless it is a local listener, and stores endpoint+model+key together (never echoed)' => function () {
        $fx = mtFixture();
        try {
            list($code, $out) = mtTranscribeRun($fx, ['login']);
            check($code === 1 && strpos($out, 'stdin is not one') !== false, 'no terminal was not refused: ' . $out);

            list($code, $out) = mtTranscribeRun($fx, ['login'], ['typed' => ['http://example.org/v1', 'm1', 'k1']]);
            check($code === 1 && strpos($out, 'https is required') !== false, 'plain http to a remote host was not refused: ' . $out);

            list($code, $out) = mtTranscribeRun($fx, ['login'], ['typed' => ['https://example.org/v1', 'm1', 'sekret-key']]);
            check($code === 0, 'login with https should succeed: ' . $out);
            $file = "$fx[dir]/secrets/transcription";
            clearstatcache();
            check((fileperms($file) & 0777) === 0600 && (fileperms(dirname($file)) & 0777) === 0700, 'secret file not 0600/0700');
            check(strpos((string) file_get_contents($file), 'sekret-key') !== false, 'the file should hold the key (storage, not output)');

            list($code, $out) = mtTranscribeRun($fx, ['status']);
            check(strpos($out, 'sekret-key') === false, 'the key must never be printed by status: ' . $out);
            $l = mtLines($out)[0];
            check($l['cloud_login'] === true && $l['cloud_endpoint_host'] === 'example.org' && $l['cloud_model'] === 'm1', 'status wrong: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'transcribe login: a local 127.0.0.1 endpoint is accepted over plain http; empty answers are refused' => function () {
        $fx = mtFixture();
        try {
            list($code, $out) = mtTranscribeRun($fx, ['login'], ['typed' => ['http://127.0.0.1:8081/v1', 'm1', 'k1']]);
            check($code === 0, 'a local http endpoint should be accepted: ' . $out);

            list($code, $out) = mtTranscribeRun($fx, ['login'], ['typed' => ['', 'm1', 'k1']], ['secretDir' => "$fx[dir]/secrets2"]);
            check($code === 1 && strpos($out, 'no endpoint typed') !== false, 'an empty endpoint was not refused: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: cloud success sends the model, response_format and Authorization header, and prints the text on stdout' => function () {
        $fx = mtFixture();
        list($fx, $base) = mtServe($fx, ['cloud_text' => 'hello from the cloud']);
        mtStoreLogin($fx, $base, 'stt-model-1', 'sekret-key');
        $audio = ttAudio($fx);
        try {
            list($code, $out) = mtTranscribeRun($fx, ['run', $audio]);
            check($code === 0 && trim($out) === 'hello from the cloud', 'plain run must print the text, nothing else: ' . var_export($out, true));
            $calls = mtCloudCalls($fx);
            check(count($calls) === 1 && $calls[0]['model'] === 'stt-model-1' && $calls[0]['response_format'] === 'json'
                && $calls[0]['authorization'] === 'Bearer sekret-key', 'cloud call fields wrong: ' . json_encode($calls));

            list($code, $out) = mtTranscribeRun($fx, ['run', $audio, '--json']);
            $rec = json_decode(trim($out), true);
            check($code === 0 && $rec['provider'] === 'cloud' && $rec['text'] === 'hello from the cloud'
                && $rec['chars'] === strlen('hello from the cloud'), '--json record wrong: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: the temp directory lives under local/tmp (never /tmp) and is removed after success and after failure' => function () {
        $fx = mtFixture();
        list($fx, $base) = mtServe($fx, ['cloud_text' => 'x']);
        mtStoreLogin($fx, $base, 'm1', 'k1');
        $audio = ttAudio($fx);
        try {
            list($code, ) = mtTranscribeRun($fx, ['run', $audio]);
            check($code === 0, 'run should succeed');
            check(!is_dir("$fx[local]/tmp") || count((array) glob("$fx[local]/tmp/*")) === 0, 'the temp directory was not cleaned up after success');

            mtSetState($fx, ['ffmpeg_code' => 1, 'ffmpeg_err' => "conversion exploded\n"]);
            list($code, $out) = mtTranscribeRun($fx, ['run', $audio]);
            check($code === 1 && strpos($out, 'could not prepare the audio') !== false, 'ffmpeg failure not reported: ' . $out);
            check(!is_dir("$fx[local]/tmp") || count((array) glob("$fx[local]/tmp/*")) === 0, 'the temp directory was not cleaned up after failure');
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: a 500 from the cloud falls back to local, saying why on stderr' => function () {
        $fx = mtFixture();
        list($fx, $base) = mtServe($fx, ['cloud_code' => 500]);
        mtStoreLogin($fx, $base, 'm1', 'k1');
        ttInstallFakeLocal($fx, 'local transcript');
        $audio = ttAudio($fx);
        try {
            list($code, $out, $err) = mtTranscribeRun($fx, ['run', $audio]);
            check($code === 0 && trim($out) === 'local transcript', 'should fall back to local on a 500: ' . $out);
            check(strpos($err, 'cloud transcription unavailable') !== false, 'no reason was given on stderr: ' . $err);
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: a 401 says the key was refused, then falls back to local; --local skips the cloud entirely' => function () {
        $fx = mtFixture();
        list($fx, $base) = mtServe($fx, ['cloud_code' => 401]);
        mtStoreLogin($fx, $base, 'm1', 'wrong-key');
        ttInstallFakeLocal($fx, 'local transcript');
        $audio = ttAudio($fx);
        try {
            list($code, $out, $err) = mtTranscribeRun($fx, ['run', $audio]);
            check($code === 0 && trim($out) === 'local transcript', 'should fall back to local after the 401: ' . $out);
            check(strpos($err, 'run login again') !== false, 'the 401 reason was not reported on stderr: ' . $err);

            list($code, $out) = mtTranscribeRun($fx, ['run', $audio, '--local']);
            check($code === 0 && trim($out) === 'local transcript', '--local must use the local engine straight away: ' . $out);
            check(count(mtCloudCalls($fx)) === 1, '--local must never call the cloud endpoint (only the earlier 401 call should be logged)');
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: a file over the cloud chunk limit is split into pieces and the texts joined' => function () {
        $fx = mtFixture();
        list($fx, $base) = mtServe($fx, ['cloud_text' => 'part']);
        mtStoreLogin($fx, $base, 'm1', 'k1');
        mtSetState($fx, ['segment_count' => 3]);
        $audio = ttAudio($fx, 200);
        try {
            // The fake ffmpeg always creates a small placeholder output regardless of the real input
            // size, so TranscribeRun's own 24 MiB check never trips here; this proves the SEGMENT PATH
            // itself (ffmpeg -f segment, one cloud call per piece, texts joined) only when forced open
            // by calling it the way TranscribeRun does: directly, with a pre-made oversized wav.
            require_once dirname(__DIR__) . '/pikkumies/tools/media/transcribe/TranscribeCloud.php';
            require_once dirname(__DIR__) . '/pikkumies/lib/ToolProc.php';
            $wav = "$fx[dir]/big.wav";
            file_put_contents($wav, str_repeat('a', 30000000)); // over the 24 MiB cloud chunk limit
            $ffmpeg = "$fx[bin]/ffmpeg";
            $r = testWithEnv(['PATH' => $fx['bin'], 'FAKE_DIR' => $fx['dir']], function () use ($ffmpeg, $wav, $fx) {
                return ToolProc::run(ToolProc::gentle([$ffmpeg, '-nostdin', '-hide_banner', '-i', $wav, '-f', 'segment', '-segment_time', '150',
                    '-c', 'copy', "$fx[dir]/seg-%03d.wav"]));
            });
            check($r['code'] === 0, 'fake segment call failed: ' . $r['err']);
            $segs = glob("$fx[dir]/seg-*.wav");
            check(count($segs) === 3, 'fake ffmpeg did not create the scripted 3 segments: ' . json_encode($segs));
            sort($segs);
            $texts = [];
            foreach ($segs as $seg) {
                $part = TranscribeCloud::transcribe($base, 'm1', 'k1', $seg);
                check($part['ok'], 'segment upload failed: ' . json_encode($part));
                $texts[] = $part['text'];
            }
            check(implode(' ', $texts) === 'part part part', 'joined segment texts wrong: ' . implode(' ', $texts));
            check(count(mtCloudCalls($fx)) === 3, 'expected one cloud call per segment, got ' . count(mtCloudCalls($fx)));
        } finally {
            mtDone($fx);
        }
    },
    'transcribe status: reports ffmpeg found, no login and no local engine without throwing' => function () {
        $fx = mtFixture();
        try {
            list($code, $out) = mtTranscribeRun($fx, ['status']);
            $l = mtLines($out)[0];
            check($code === 0 && $l['cloud_login'] === false && $l['local_installed'] === false && $l['ffmpeg'] === true, 'status wrong: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: neither cloud login nor local engine gives a clear error naming both fixes, and exits 1' => function () {
        $fx = mtFixture();
        $audio = ttAudio($fx);
        try {
            list($code, $out) = mtTranscribeRun($fx, ['run', $audio]);
            check($code === 1 && strpos($out, 'login') !== false && strpos($out, 'install-local') !== false, 'missing-engine error incomplete: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'transcribe login: plain http only for the exact loopback hosts; look-alike names (127.0.0.1.example, localhost.example) need https' => function () {
        $fx = mtFixture();
        try {
            foreach (['http://127.0.0.1.evil.example/v1', 'http://localhost.evil.example/v1', 'http://evil-localhost.example/v1', 'http://127.1/v1'] as $i => $url) {
                list($code, $out) = mtTranscribeRun($fx, ['login'], ['typed' => [$url, 'm1', 'k1'], 'secretDir' => "$fx[dir]/bad$i"]);
                check($code === 1 && strpos($out, 'https is required') !== false, "$url was accepted over plain http: $out");
                check(!file_exists("$fx[dir]/bad$i/transcription"), "$url was stored");
            }
            foreach (['http://localhost:8081/v1', 'http://[::1]:8081/v1', 'http://127.1.2.3:8081/v1'] as $i => $url) {
                list($code, $out) = mtTranscribeRun($fx, ['login'], ['typed' => [$url, 'm1', 'k1'], 'secretDir' => "$fx[dir]/good$i"]);
                check($code === 0, "$url is a loopback host and should be accepted over http: $out");
            }
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: removes this tool\'s own temp directories left by killed runs once 6 hours old, never fresh ones or other names' => function () {
        $fx = mtFixture();
        $audio = ttAudio($fx);
        $tmp = "$fx[local]/tmp";
        foreach (['transcribe-0123456789abcdef', 'transcribe-fedcba9876543210', 'other-0123456789abcdef'] as $d) {
            mkdir("$tmp/$d", 0700, true);
            file_put_contents("$tmp/$d/audio.wav", 'left behind');
        }
        $old = time() - 7 * 3600;
        touch("$tmp/transcribe-0123456789abcdef", $old);
        touch("$tmp/other-0123456789abcdef", $old);
        try {
            mtTranscribeRun($fx, ['run', $audio]); // fails (no engine), but the sweep runs first
            clearstatcache();
            check(!file_exists("$tmp/transcribe-0123456789abcdef"), 'a 7-hour-old temp directory of this tool was not removed');
            check(is_file("$tmp/transcribe-fedcba9876543210/audio.wav"), 'a fresh temp directory (a live run) must be kept');
            check(is_file("$tmp/other-0123456789abcdef/audio.wav"), 'a directory without this tool\'s prefix must never be touched');
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: a converted file over the chunk limit is split by TranscribeRun itself with the protocol whitelist, one upload per piece' => function () {
        $fx = mtFixture();
        list($fx, $base) = mtServe($fx, ['cloud_text' => 'part']);
        mtStoreLogin($fx, $base, 'm1', 'k1');
        mtSetState($fx, ['segment_count' => 3, 'ffmpeg_bytes' => 25165825]); // sparse speech-16k output, one byte over 24 MiB
        $audio = ttAudio($fx);
        try {
            list($code, $out) = mtTranscribeRun($fx, ['run', $audio]);
            check($code === 0 && trim($out) === 'part part part', 'split run output wrong: ' . var_export($out, true));
            $split = array_values(array_filter(mtCalls($fx, 'ffmpeg'), function ($c) {
                return strpos($c, ' segment ') !== false;
            }));
            check(count($split) === 1 && strpos($split[0], '-protocol_whitelist file,pipe,crypto,data -i ') !== false,
                'the split call lacks the protocol whitelist: ' . json_encode($split));
            check(count(mtCloudCalls($fx)) === 3, 'expected one cloud call per piece, got ' . count(mtCloudCalls($fx)));
        } finally {
            mtDone($fx);
        }
    },
    'transcribe run: with both local models installed, small (the more accurate) is used' => function () {
        $fx = mtFixture();
        ttInstallFakeLocal($fx, 'local transcript');
        file_put_contents("$fx[local]/whisper/models/ggml-small.bin", 'fake small model');
        $audio = ttAudio($fx);
        try {
            list($code, $out) = mtTranscribeRun($fx, ['run', $audio, '--local']);
            $calls = mtCalls($fx, 'whisper-cli');
            check($code === 0 && count($calls) === 1 && strpos($calls[0], 'ggml-small.bin') !== false, 'small was not preferred: ' . json_encode($calls));
            list(, $out) = mtTranscribeRun($fx, ['status']);
            check(mtLines($out)[0]['local_model'] === 'small', 'status should report small: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'ADR-0045 and the media docs say OpenAI only as "OpenAI-compatible", name no skill slash-name, and no doc points at the old storage/lib ToolProc/HomePath' => function () {
        $root = dirname(__DIR__);
        $files = array_merge(["$root/docs/adr/0045-media-tools-ffmpeg-and-transcription.md"], glob("$root/pikkumies/tools/media/*.md"),
            glob("$root/pikkumies/tools/media/*/*.md"));
        foreach ($files as $f) {
            $text = str_replace('OpenAI-compatible', '', (string) file_get_contents($f));
            check(stripos($text, 'openai') === false, basename($f) . ' names the API format other than as "OpenAI-compatible" (a vendor or product)');
            $skill = preg_match('#(^|[\s"(])/v[a-z]+\b#', $text, $m) ? $m[0] : null;
            check($skill === null, basename($f) . " contains a skill slash-name: $skill");
        }
        foreach (['pikkumies/tools/system/performance/README.md', 'pikkumies/lib/Quota.php', 'tests/StorageEdgeTest.php', 'tests/ToolProcEdgeTest.php',
            'docs/adr/0045-media-tools-ffmpeg-and-transcription.md'] as $rel) {
            check(!preg_match('#tools/storage/lib(/ToolProc|/HomePath|`)#', (string) file_get_contents("$root/$rel")), "$rel still points at the old helper path");
        }
    },
];
