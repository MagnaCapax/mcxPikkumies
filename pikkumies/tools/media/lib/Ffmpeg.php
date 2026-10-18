<?php
/**
 * Ffmpeg: runs ffmpeg/ffprobe for both media units (media/ffmpeg and media/transcribe, ADR-0045),
 * through ToolProc so every call is an argv, nice 19 and idle I/O (ToolProc::gentle), never a shell
 * string. Paths are always realpath'd absolute first (HomePath), so a name starting with `-` is
 * never read as an option, a symlink cannot escape the home, and the input must be a regular file
 * (no device files).
 */
class Ffmpeg
{
    /** preset name => argv fragment, placed between `-i <in>` and the output path. */
    const PRESETS = [
        'remux-mp4'  => ['-c', 'copy'],
        'audio-mp3'  => ['-vn', '-c:a', 'libmp3lame'],
        'audio-opus' => ['-vn', '-c:a', 'libopus'],
        'speech-16k' => ['-vn', '-ac', '1', '-ar', '16000'], // mono 16 kHz, for transcription
    ];

    /** The only protocols ffmpeg may open for an input: local files and in-memory forms, never the network. */
    const PROTOCOLS = 'file,pipe,crypto,data';

    /** Absolute path of ffmpeg, or null when not installed. */
    public static function ffmpegPath()
    {
        return ToolProc::which('ffmpeg');
    }

    /** Absolute path of ffprobe, or null when not installed. */
    public static function ffprobePath()
    {
        return ToolProc::which('ffprobe');
    }

    /**
     * An existing regular file, realpath'd, inside $home unless $explicit (HomePath::existing does
     * the containment check; here we also refuse anything that is not a plain file, so a device
     * node or a fifo is never fed to ffmpeg).
     */
    public static function inputFile($path, $home)
    {
        $real = is_string($path) && $path !== '' ? HomePath::existing($path, $home) : false;
        if ($real === false || !is_file($real)) {
            throw new CliError("not an existing file: $path", 'give a full path to a media file under ' . $home);
        }
        return $real;
    }

    /**
     * `ffprobe -v error -print_format json -show_format -show_streams <file>`, parsed to a small
     * array: container, duration (s), overall bitrate (bps), and one record per stream (codec,
     * resolution+fps for video, channels+sample_rate for audio, language). Throws on a file ffprobe
     * cannot read at all; a format it does not recognise still returns (empty streams).
     */
    public static function probe($path)
    {
        $bin = self::ffprobePath();
        if ($bin === null) {
            throw new CliError('ffprobe is not installed on this machine (not found in PATH); nothing was done',
                'the provider or this account installs ffmpeg');
        }
        $r = ToolProc::run([$bin, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path]);
        $data = $r['code'] === 0 ? json_decode($r['out'], true) : null;
        if (!is_array($data)) {
            throw new CliError('ffprobe could not read this file' . ($r['err'] !== '' ? ': ' . ToolText::tail($r['err']) : ''),
                'check that it is really a media file');
        }
        $format = isset($data['format']) && is_array($data['format']) ? $data['format'] : [];
        $streams = [];
        foreach (isset($data['streams']) && is_array($data['streams']) ? $data['streams'] : [] as $s) {
            $streams[] = [
                'index'    => isset($s['index']) ? (int) $s['index'] : null,
                'type'     => isset($s['codec_type']) ? $s['codec_type'] : null,
                'codec'    => isset($s['codec_name']) ? $s['codec_name'] : null,
                'width'    => isset($s['width']) ? (int) $s['width'] : null,
                'height'   => isset($s['height']) ? (int) $s['height'] : null,
                'fps'      => isset($s['r_frame_rate']) ? self::rate($s['r_frame_rate']) : null,
                'channels' => isset($s['channels']) ? (int) $s['channels'] : null,
                'sample_rate' => isset($s['sample_rate']) ? (int) $s['sample_rate'] : null,
                'language' => isset($s['tags']['language']) ? $s['tags']['language'] : null,
            ];
        }
        return [
            'container' => isset($format['format_name']) ? $format['format_name'] : null,
            'duration_seconds' => isset($format['duration']) ? round((float) $format['duration'], 3) : null,
            'bit_rate_bps' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            'streams' => $streams,
        ];
    }

    /** "30000/1001" -> 29.97; a malformed or 0-denominator rate is null, never a division error. */
    public static function rate($s)
    {
        if (!is_string($s) || strpos($s, '/') === false) {
            return null;
        }
        list($n, $d) = array_map('floatval', explode('/', $s, 2));
        return $d > 0.0 ? round($n / $d, 3) : null;
    }

    /**
     * "plays directly in a browser" verdict: H.264 (or AV1/VP9/VP8 in webm) video + AAC/Opus/MP3
     * audio, in mp4 or webm. Names what would need conversion otherwise. An audio-only file is judged
     * on its one audio stream. ffprobe names every Matroska file `matroska,webm`, so it counts as webm
     * only when $path ends in .webm or its codecs are webm's own (VP8/VP9/AV1, Opus/Vorbis); an .mkv
     * holding H.264 and AAC/Opus/MP3 only needs a remux to mp4, not a re-encode.
     */
    public static function verdict(array $probe, $path = '')
    {
        $container = strtolower((string) $probe['container']);
        $inMp4 = strpos($container, 'mp4') !== false || strpos($container, 'mov') !== false;
        $matroska = strpos($container, 'webm') !== false || strpos($container, 'matroska') !== false;
        $video = null;
        $audio = null;
        foreach ($probe['streams'] as $s) {
            if ($s['type'] === 'video' && $video === null) {
                $video = $s;
            } elseif ($s['type'] === 'audio' && $audio === null) {
                $audio = $s;
            }
        }
        $vCodec = $video !== null ? $video['codec'] : null;
        $aCodec = $audio !== null ? $audio['codec'] : null;
        $webmCodecs = in_array($vCodec, [null, 'vp8', 'vp9', 'av1'], true) && in_array($aCodec, [null, 'opus', 'vorbis'], true);
        $inWebm = $matroska && (strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) === 'webm' || $webmCodecs);
        $okVideo = $video === null || ($inMp4 && $vCodec === 'h264') || ($inWebm && in_array($vCodec, ['av1', 'vp9', 'vp8'], true));
        $okAudio = $audio === null || in_array($aCodec, ['aac', 'opus', 'mp3'], true) || ($inWebm && $aCodec === 'vorbis');
        if (($inMp4 || $inWebm) && $okVideo && $okAudio) {
            return 'plays directly in a browser';
        }
        if ($matroska && !$inWebm && in_array($vCodec, [null, 'h264'], true) && in_array($aCodec, [null, 'aac', 'opus', 'mp3'], true)) {
            return 'needs remuxing only: the streams play in a browser but the Matroska container does not; convert --preset remux-mp4'
                . ' copies them into mp4 without re-encoding';
        }
        $need = [];
        if (!$okVideo && $video !== null) {
            $need[] = 'video (' . $video['codec'] . ') to H.264 in mp4, or AV1/VP9 in webm';
        }
        if (!$okAudio && $audio !== null) {
            $need[] = 'audio (' . $audio['codec'] . ') to AAC, Opus or MP3';
        }
        if (!$inMp4 && !$inWebm) {
            $need[] = 'container (' . $probe['container'] . ') to mp4 or webm';
        }
        return $need ? 'needs converting: ' . implode('; ', $need) : 'format not recognised; try a preset and see';
    }

    /**
     * argv for `convert`/a transcription preset: `-nostdin -hide_banner [-y] -protocol_whitelist
     * file,pipe,crypto,data -i <in> <preset argv> <out>`. $in and $out are already realpath'd
     * absolute paths (HomePath), so neither can be read as an option even if its name starts with
     * `-`. `-y` only with $overwrite: without it ffmpeg (with -nostdin) refuses an existing output,
     * printing "already exists. Exiting." and still exiting 0. Throws on an unknown preset.
     */
    public static function convertArgv($bin, $in, $out, $preset, $overwrite = false)
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new CliError("unknown preset: $preset", 'presets: ' . implode(', ', array_keys(self::PRESETS)));
        }
        return array_merge([$bin, '-nostdin', '-hide_banner'], $overwrite ? ['-y'] : [], ['-protocol_whitelist', self::PROTOCOLS, '-i', $in],
            self::PRESETS[$preset], [$out]);
    }

    /**
     * Converts $in to $out with $preset (overwriting an existing $out only with $overwrite), through
     * ToolProc::gentle with a $seconds time cap (the host may stop a long-running ffmpeg itself, so
     * this is a cooperative cap, not the only one). Returns ['code', 'stopped', 'err', 'exists']
     * (ToolProc::run's 'out' is ffmpeg's own progress text on stderr, not useful here, so only 'err'
     * is kept, cut to a reasonable error size; 'exists': ffmpeg refused an existing $out).
     */
    public static function convert($bin, $in, $out, $preset, $seconds, $overwrite = false)
    {
        $r = ToolProc::run(ToolProc::gentle(self::convertArgv($bin, $in, $out, $preset, $overwrite), $seconds));
        return ['code' => $r['code'], 'stopped' => $r['stopped'], 'err' => $r['err'] !== '' ? ToolText::tail($r['err']) : '',
            'exists' => strpos($r['err'], 'already exists. Exiting.') !== false]; // checked on the full stderr, before tail() cuts it
    }
}
