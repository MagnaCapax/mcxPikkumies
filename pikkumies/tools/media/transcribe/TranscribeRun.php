<?php
/**
 * TranscribeRun: the `run` verb (ADR-0045). Converts the input to speech-16k in a temporary
 * directory inside the account's own quota (pikkumies/local/tmp/, never /tmp), tries the cloud
 * endpoint when a login is stored and --local was not given, splits a file over 24 MB into ~150 s
 * pieces first, and falls back to the local engine on any cloud failure. The temporary directory is
 * removed, success or failure; one left by a run that was killed (where `finally` never runs) is
 * removed by the next run once it is STALE_SECONDS old. $localDir is the caller's local/ directory
 * (a test seam, Config::localDir() in production — TranscribeLocal.php's own doc explains the pattern).
 */
class TranscribeRun
{
    const CLOUD_CHUNK_LIMIT = 25165824; // 24 MiB
    const SEGMENT_SECONDS = 150;
    const CONVERT_SECONDS = 7200; // 2 h: generous for the speech-16k conversion and the local engine alike
    const TMP_PREFIX = 'transcribe-';
    const STALE_SECONDS = 21600; // 6 h: a live run adds or removes a file in its directory at least every ~2 h (each step's cap)

    public static function run(array $args, array $opt, TranscribeSecret $secret, $localDir = null)
    {
        if (!isset($args[0])) {
            throw new CliError('run needs a file', 'php transcribe.php run <file>');
        }
        $home = HomePath::home();
        $in = Ffmpeg::inputFile($args[0], $home);
        $language = isset($opt['language']) && is_string($opt['language']) ? $opt['language'] : null;
        $ffmpegBin = Ffmpeg::ffmpegPath();
        if ($ffmpegBin === null) {
            throw new CliError('ffmpeg is not installed on this machine (not found in PATH); nothing was transcribed',
                'the provider or this account installs ffmpeg');
        }
        $tmpDir = self::makeTempDir($localDir);
        try {
            $wav = "$tmpDir/audio.wav";
            $conv = Ffmpeg::convert($ffmpegBin, $in, $wav, 'speech-16k', self::CONVERT_SECONDS);
            if ($conv['code'] !== 0 || !is_file($wav)) {
                throw new CliError('could not prepare the audio for transcription' . ($conv['err'] !== '' ? ': ' . $conv['err'] : ''),
                    'check that ffmpeg can read this file (ffmpeg probe)');
            }
            $started = time();
            if (empty($opt['local'])) {
                $login = $secret->load();
                if ($login !== null) {
                    $cloud = self::tryCloud($ffmpegBin, $login, $wav, $language);
                    if ($cloud['ok']) {
                        return self::result('cloud', $cloud['text'], $language, $started);
                    }
                    fwrite(STDERR, 'cloud transcription unavailable: ' . $cloud['reason'] . "\n");
                }
            }
            if (!TranscribeLocal::installed($localDir)) {
                throw new CliError('no transcription engine is available: no cloud login is stored and the local engine is not installed',
                    'run `login` to use a cloud endpoint, or `install-local` to build the local engine');
            }
            $r = TranscribeLocal::run($wav, $language, self::CONVERT_SECONDS, $localDir);
            if (!$r['ok']) {
                throw new CliError('local transcription failed: ' . $r['reason'], 'try `status` to check the engine, or `install-local` again');
            }
            return self::result('local', $r['text'], $language, $started);
        } finally {
            self::removeTempDir($tmpDir, $localDir);
        }
    }

    /** Whole file when it fits in one upload, otherwise split into ~150 s pieces and the texts joined. */
    private static function tryCloud($ffmpegBin, array $login, $wav, $language)
    {
        $size = @filesize($wav);
        if ($size === false || $size <= self::CLOUD_CHUNK_LIMIT) {
            return TranscribeCloud::transcribe($login['endpoint'], $login['model'], $login['key'], $wav, $language);
        }
        $dir = dirname($wav);
        $pattern = "$dir/seg-%03d.wav";
        $r = ToolProc::run(ToolProc::gentle([$ffmpegBin, '-nostdin', '-hide_banner', '-protocol_whitelist', Ffmpeg::PROTOCOLS, '-i', $wav,
            '-f', 'segment', '-segment_time', (string) self::SEGMENT_SECONDS, '-c', 'copy', $pattern], self::CONVERT_SECONDS));
        $segments = glob("$dir/seg-*.wav");
        sort($segments);
        if ($r['code'] !== 0 || !$segments) {
            return ['ok' => false, 'text' => '', 'reason' => 'could not split the file into pieces for upload'];
        }
        $texts = [];
        foreach ($segments as $seg) {
            $part = TranscribeCloud::transcribe($login['endpoint'], $login['model'], $login['key'], $seg, $language);
            @unlink($seg);
            if (!$part['ok']) {
                return $part; // the whole cloud attempt fails; the caller falls back to local on the original file
            }
            $texts[] = $part['text'];
        }
        return ['ok' => true, 'text' => trim(implode(' ', $texts)), 'reason' => ''];
    }

    private static function result($provider, $text, $language, $started)
    {
        return ['provider' => $provider, 'seconds' => time() - $started, 'language' => $language, 'chars' => strlen($text), 'text' => $text];
    }

    /**
     * A fresh 0700 directory under pikkumies/local/tmp/ (never /tmp: outside the account's quota).
     * First removes this tool's own directories there older than STALE_SECONDS (by name prefix only).
     */
    private static function makeTempDir($localDir)
    {
        $base = ($localDir !== null ? $localDir : Config::localDir()) . '/tmp';
        if (!@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new CliError("cannot create $base", 'check that pikkumies/local is writable by this account');
        }
        foreach ((array) glob($base . '/' . self::TMP_PREFIX . '*', GLOB_ONLYDIR) as $old) {
            if (preg_match('/^' . self::TMP_PREFIX . '[0-9a-f]{16}$/', basename($old)) && !is_link($old)
                && @filemtime($old) < time() - self::STALE_SECONDS) {
                self::removeTempDir($old, $localDir);
            }
        }
        for ($i = 0; $i < 10; $i++) {
            $dir = $base . '/' . self::TMP_PREFIX . bin2hex(random_bytes(8));
            if (@mkdir($dir, 0700)) {
                return $dir;
            }
        }
        throw new CliError("cannot create a temporary directory under $base", 'check that it is writable and has free inodes');
    }

    /** Removed whether run() succeeded or threw; refuses to touch anything outside its own temp area. */
    private static function removeTempDir($dir, $localDir)
    {
        $base = ($localDir !== null ? $localDir : Config::localDir()) . '/tmp/';
        if (!is_string($dir) || $dir === '' || strpos($dir, $base) !== 0) {
            return;
        }
        foreach ((array) glob("$dir/*") as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
