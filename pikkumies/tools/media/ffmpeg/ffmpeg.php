<?php
/**
 * ffmpeg: converts and inspects the user's own media files with ffmpeg/ffprobe (tools/media/README.md,
 * README.md here, ADR-0045). Every path is resolved and confined to the account's home
 * (pikkumies/lib/HomePath.php); every subprocess runs through pikkumies/lib/ToolProc.php.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'ToolProc', 'HomePath', 'Quota'] as $class) {
    require_once "$lib/$class.php";
}
require_once dirname(__DIR__, 2) . '/storage/lib/ToolText.php'; // ToolText stayed in storage/lib (storage README)
require_once dirname(__DIR__) . '/lib/Ffmpeg.php';
require_once __DIR__ . '/FfmpegVerbs.php';
require_once __DIR__ . '/FfmpegHw.php';

$usage = <<<'TXT'
usage: php ffmpeg.php <verb> [arguments] [--human]
  probe <file> [--json]                                 container, duration, streams, browser-playback verdict
  hw                                                     is the GPU render node usable by this account
  convert <in> <out> --preset P [--force] [--max-hours N]
                                                          presets: remux-mp4, audio-mp3, audio-opus, speech-16k
  jobs                                                   this account's running ffmpeg/ffprobe processes
TXT;

Cli::run($argv, [
    'probe'   => ['FfmpegVerbs', 'probe'],
    'hw'      => function ($args, $opt) {
        return FfmpegHw::record();
    },
    'convert' => ['FfmpegVerbs', 'convert'],
    'jobs'    => ['FfmpegVerbs', 'jobs'],
], $usage, ['force', 'json'], ['preset', 'max-hours']); // --json: a no-op; JSON Lines is already the default (--human is the other form)
