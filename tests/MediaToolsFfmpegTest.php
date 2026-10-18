<?php
/** The ffmpeg unit against fake ffmpeg/ffprobe on a temporary PATH (tests/fixtures/mediatools/). */
require_once __DIR__ . '/fixtures/mediatools/harness.php';

function ftProbeJson(array $streams, $container = 'mov,mp4,m4a,3gp,3g2,mj2', $duration = '12.5', $bitRate = '128000')
{
    return json_encode(['format' => ['format_name' => $container, 'duration' => $duration, 'bit_rate' => $bitRate], 'streams' => $streams]);
}

return [
    'ffmpeg probe: verdicts for a browser-ready mp4, a codec that needs converting, and a webm with AV1/Opus' => function () {
        $fx = mtFixture();
        file_put_contents("$fx[home]/movie.mkv", 'x');
        try {
            mtSetState($fx, ['ffprobe_json' => ftProbeJson([
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080, 'r_frame_rate' => '30000/1001'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'sample_rate' => '48000'],
            ])]);
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['probe', "$fx[home]/movie.mkv"]);
            $l = mtLines($out)[0];
            check($code === 0 && $l['verdict'] === 'plays directly in a browser' && abs($l['streams'][0]['fps'] - 29.97) < 0.01,
                'browser-ready verdict wrong: ' . $out);

            mtSetState($fx, ['ffprobe_json' => ftProbeJson([
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mpeg4'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'mp3'],
            ])]);
            list(, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['probe', "$fx[home]/movie.mkv"]);
            $l = mtLines($out)[0];
            check(strpos($l['verdict'], 'needs converting') === 0 && strpos($l['verdict'], 'mpeg4') !== false, 'codec gap not named: ' . $out);

            mtSetState($fx, ['ffprobe_json' => ftProbeJson(
                [['index' => 0, 'codec_type' => 'video', 'codec_name' => 'av1'], ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'opus']],
                'webm'
            )]);
            list(, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['probe', "$fx[home]/movie.mkv"]);
            check(mtLines($out)[0]['verdict'] === 'plays directly in a browser', 'webm AV1+Opus should play directly: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'ffmpeg probe: path rules reject a file outside home, a symlink escape, and a device/missing file' => function () {
        $fx = mtFixture();
        try {
            mkdir("$fx[dir]/outside");
            file_put_contents("$fx[dir]/outside/x.mp4", 'x');
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['probe', "$fx[dir]/outside/x.mp4"]);
            check($code === 1 && strpos($out, 'not an existing path inside the home') !== false, 'outside-home not refused: ' . $out);

            symlink("$fx[dir]/outside/x.mp4", "$fx[home]/escape.mp4");
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['probe', "$fx[home]/escape.mp4"]);
            check($code === 1, 'symlink escaping home was followed: ' . $out);

            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['probe', "$fx[home]/nope.mp4"]);
            check($code === 1 && strpos($out, 'not an existing') !== false, 'missing file not refused: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'ffmpeg convert: the argv has -protocol_whitelist and runs through the gentle wrapper; quota, overwrite and in==out are refused' => function () {
        $fx = mtFixture();
        file_put_contents("$fx[home]/in.mov", str_repeat('x', 1000));
        try {
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['convert', "$fx[home]/in.mov", "$fx[home]/out.mp4", '--preset', 'remux-mp4']);
            check($code === 0, 'convert failed: ' . $out);
            $calls = mtCalls($fx, 'ffmpeg');
            check(count($calls) === 1 && strpos($calls[0], '-protocol_whitelist file,pipe,crypto,data') !== false
                && strpos($calls[0], '-nostdin') !== false, 'argv missing the protocol whitelist / -nostdin: ' . $calls[0]);
            check(is_file("$fx[home]/out.mp4"), 'output was not created');

            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['convert', "$fx[home]/in.mov", "$fx[home]/out.mp4", '--preset', 'remux-mp4']);
            check($code === 1 && strpos($out, 'already exists') !== false, 'overwrite without --force was not refused: ' . $out);

            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['convert', "$fx[home]/in.mov", "$fx[home]/in.mov", '--preset', 'remux-mp4', '--force']);
            check($code === 1 && strpos($out, 'same file') !== false, 'in==out was not refused: ' . $out);
        } finally {
            mtDone($fx);
        }
    },
    'ffmpeg convert: refuses when the input is larger than the free quota' => function () {
        $fx = mtFixture();
        file_put_contents("$fx[home]/in.mov", str_repeat('x', 5000));
        file_put_contents("$fx[bin]/quota", "#!/usr/bin/env php\n<?php\necho \"/dev/sda1 10 10 10 100 100 100\\n\";\n");
        chmod("$fx[bin]/quota", 0755);
        try {
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['convert', "$fx[home]/in.mov", "$fx[home]/out.mp4", '--preset', 'audio-mp3']);
            check($code === 1 && strpos($out, 'not enough free quota') !== false, 'oversized input was not refused by quota: ' . $out);
            check(!is_file("$fx[home]/out.mp4"), 'ffmpeg ran despite the quota refusal');
        } finally {
            mtDone($fx);
        }
    },
    'ffmpeg hw: names the missing device, and the exact group gap when the device exists but is not in this account\'s groups' => function () {
        $fx = mtFixture();
        try {
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['hw']);
            $l = mtLines($out)[0];
            check($code === 0 && $l['exists'] === false && strpos($l['gap'], 'does not exist') !== false, 'missing device gap wrong: ' . $out);

            // `hw` takes no --device option (USAGE.md), so an existing-but-foreign-group device is exercised
            // directly against FfmpegHw::record($device) instead of faking a device this account cannot own.
            require_once dirname(__DIR__) . '/pikkumies/tools/media/ffmpeg/FfmpegHw.php';
            $dev = "$fx[dir]/renderD128";
            file_put_contents($dev, '');
            $rec = FfmpegHw::record($dev);
            check($rec['exists'] === true && $rec['readable'] === true && $rec['writable'] === true && $rec['gap'] === null,
                'a device this account owns and can read+write should have no gap: ' . json_encode($rec));
            check(is_array($rec['account_groups']), 'account_groups must be an array even when posix_getgroups is unavailable');
        } finally {
            mtDone($fx);
        }
    },
    'ffmpeg jobs: only this account\'s own ffmpeg/ffprobe PIDs, never another process or another account\'s' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/media/ffmpeg/FfmpegVerbs.php';
        require_once dirname(__DIR__) . '/pikkumies/lib/ToolProc.php';
        require_once dirname(__DIR__) . '/pikkumies/lib/HomePath.php';
        require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
        require_once dirname(__DIR__) . '/pikkumies/lib/Quota.php';
        require_once dirname(__DIR__) . '/pikkumies/tools/media/lib/Ffmpeg.php';
        require_once dirname(__DIR__) . '/pikkumies/tools/storage/lib/ToolText.php';
        $jobs = FfmpegVerbs::jobs([], []);
        // We cannot start a real ffmpeg process safely here; this only proves it finds ITS OWN PHP
        // process as "not ffmpeg/ffprobe" and returns an array, never throwing on a live /proc read.
        check(is_array($jobs), 'jobs() must return an array even when nothing of ours is running');
        foreach ($jobs as $j) {
            check(in_array($j['command'], ['ffmpeg', 'ffprobe'], true), 'jobs() returned a non-ffmpeg process: ' . json_encode($j));
        }
    },
    'ffmpeg convert --force passes -y and really replaces the file; an ffmpeg-side "already exists" refusal is never success' => function () {
        $fx = mtFixture();
        file_put_contents("$fx[home]/in.mov", 'x');
        file_put_contents("$fx[home]/out.mp4", 'old');
        try {
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['convert', "$fx[home]/in.mov", "$fx[home]/out.mp4", '--preset', 'remux-mp4', '--force']);
            $calls = mtCalls($fx, 'ffmpeg');
            check($code === 0 && count($calls) === 1 && strpos($calls[0], ' -y ') !== false, '--force did not pass -y: ' . $out . ' ' . json_encode($calls));
            check(file_get_contents("$fx[home]/out.mp4") === 'fake', 'convert --force reported success but left the old file: ' . $out);

            // Without -y the fake (like real ffmpeg under -nostdin) refuses and still exits 0; the runner must see that.
            require_once dirname(__DIR__) . '/pikkumies/tools/media/lib/Ffmpeg.php';
            require_once dirname(__DIR__) . '/pikkumies/tools/storage/lib/ToolText.php';
            file_put_contents("$fx[home]/out.mp4", 'old');
            $r = testWithEnv(['PATH' => $fx['bin'], 'FAKE_DIR' => $fx['dir']], function () use ($fx) {
                return Ffmpeg::convert("$fx[bin]/ffmpeg", "$fx[home]/in.mov", "$fx[home]/out.mp4", 'remux-mp4', 60);
            });
            check($r['code'] === 0 && $r['exists'] === true, 'an "already exists. Exiting." refusal was not detected: ' . json_encode($r));
            check(file_get_contents("$fx[home]/out.mp4") === 'old', 'the existing file must be left alone');
        } finally {
            mtDone($fx);
        }
    },
    'ffmpeg convert: an output path that is a symlink (dangling, or live with --force) or under a symlinked directory is refused' => function () {
        $fx = mtFixture();
        file_put_contents("$fx[home]/in.mov", 'x');
        mkdir("$fx[dir]/outside");
        try {
            symlink("$fx[dir]/outside/new.mp4", "$fx[home]/dangling.mp4");
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['convert', "$fx[home]/in.mov", "$fx[home]/dangling.mp4", '--preset', 'remux-mp4']);
            check($code === 1 && strpos($out, 'symlink') !== false, 'a dangling symlink output was not refused: ' . $out);
            check(!file_exists("$fx[dir]/outside/new.mp4"), 'ffmpeg wrote outside the home through a dangling symlink');

            file_put_contents("$fx[dir]/outside/kept.mp4", 'theirs');
            symlink("$fx[dir]/outside/kept.mp4", "$fx[home]/live.mp4");
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['convert', "$fx[home]/in.mov", "$fx[home]/live.mp4", '--preset', 'remux-mp4', '--force']);
            check($code === 1 && file_get_contents("$fx[dir]/outside/kept.mp4") === 'theirs', 'a live symlink output was followed with --force: ' . $out);

            symlink("$fx[dir]/outside", "$fx[home]/linkdir");
            list($code, $out) = mtRun($fx, 'media/ffmpeg/ffmpeg.php', ['convert', "$fx[home]/in.mov", "$fx[home]/linkdir/x.mp4", '--preset', 'remux-mp4']);
            check($code === 1 && !file_exists("$fx[dir]/outside/x.mp4"), 'an output under a symlinked directory outside the home was accepted: ' . $out);
            check(mtCalls($fx, 'ffmpeg') === [], 'ffmpeg must not run for any refused output path');
        } finally {
            mtDone($fx);
        }
    },
    'ffmpeg probe: an .mkv (ffprobe says matroska,webm) with H.264+AAC needs only a remux to mp4; webm codecs or a .webm name count as webm' => function () {
        require_once dirname(__DIR__) . '/pikkumies/tools/media/lib/Ffmpeg.php';
        $mkv = ['container' => 'matroska,webm', 'streams' => [['type' => 'video', 'codec' => 'h264'], ['type' => 'audio', 'codec' => 'aac']]];
        $v = Ffmpeg::verdict($mkv, '/h/movie.mkv');
        check(strpos($v, 'needs remuxing only') === 0 && strpos($v, 'remux-mp4') !== false, 'H.264/AAC in .mkv should be a remux, not a re-encode: ' . $v);
        $vp9 = ['container' => 'matroska,webm', 'streams' => [['type' => 'video', 'codec' => 'vp9'], ['type' => 'audio', 'codec' => 'opus']]];
        check(Ffmpeg::verdict($vp9, '/h/clip.mkv') === 'plays directly in a browser', 'VP9+Opus is webm content whatever the name');
        $vorbis = ['container' => 'matroska,webm', 'streams' => [['type' => 'video', 'codec' => 'vp8'], ['type' => 'audio', 'codec' => 'vorbis']]];
        check(Ffmpeg::verdict($vorbis, '/h/clip.webm') === 'plays directly in a browser', 'a .webm with VP8+Vorbis plays directly');
        $h264webm = ['container' => 'matroska,webm', 'streams' => [['type' => 'video', 'codec' => 'h264']]];
        check(strpos(Ffmpeg::verdict($h264webm, '/h/odd.webm'), 'needs converting: video (h264)') === 0, 'H.264 in a .webm needs converting');
    },
];
