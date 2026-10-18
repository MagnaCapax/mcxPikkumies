<?php
/**
 * TranscribeLocal: the local whisper.cpp engine (ADR-0045): where it lives under pikkumies/local/
 * (never tracked, AGENTS.md), whether it is installed, and running it on one audio file. $localDir
 * is always the caller's local/ directory (a test seam, Config::localDir() in production — the
 * same pattern tools/storage/storage/storage.php uses for StorageScan's cache directory).
 */
class TranscribeLocal
{
    public static function root($localDir = null)
    {
        return ($localDir !== null ? $localDir : Config::localDir()) . '/whisper';
    }

    public static function binaryPath($localDir = null)
    {
        return self::root($localDir) . '/build/bin/whisper-cli';
    }

    public static function modelPath($name, $localDir = null)
    {
        return self::root($localDir) . "/models/ggml-$name.bin";
    }

    /** Whichever model `install-local` finished, `small` (the more accurate) when both are, or null when neither file exists. */
    public static function installedModel($localDir = null)
    {
        foreach (['small', 'base'] as $name) {
            if (is_file(self::modelPath($name, $localDir))) {
                return $name;
            }
        }
        return null;
    }

    public static function installed($localDir = null)
    {
        return is_file(self::binaryPath($localDir)) && is_executable(self::binaryPath($localDir)) && self::installedModel($localDir) !== null;
    }

    /** min(4, max(1, nproc/2)): whisper.cpp's own thread flag, never more than the account's own half-share. */
    public static function threads()
    {
        $bin = ToolProc::which('nproc');
        $n = $bin !== null ? (int) trim(ToolProc::run([$bin])['out']) : 0;
        return $n > 0 ? min(4, max(1, intdiv($n, 2))) : 1;
    }

    /**
     * Runs whisper-cli on $file (already 16 kHz mono), `-otxt` so the words come back as a sibling
     * <file>.txt. Returns ['ok', 'text', 'reason']. $seconds: the ToolProc::gentle time cap.
     */
    public static function run($file, $language, $seconds, $localDir = null)
    {
        if (!self::installed($localDir)) {
            return ['ok' => false, 'text' => '', 'reason' => 'the local engine is not installed'];
        }
        $model = self::installedModel($localDir);
        $out = $file . '.local-out';
        $argv = [self::binaryPath($localDir), '-m', self::modelPath($model, $localDir), '-f', $file, '-otxt', '-of', $out,
            '-t', (string) self::threads()];
        if ($language !== null && $language !== '') {
            $argv[] = '-l';
            $argv[] = $language;
        }
        $r = ToolProc::run(ToolProc::gentle($argv, $seconds));
        $text = is_file("$out.txt") ? (string) @file_get_contents("$out.txt") : false;
        @unlink("$out.txt");
        if ($r['code'] !== 0 || $text === false) {
            return ['ok' => false, 'text' => '', 'reason' => 'whisper-cli failed' . ($r['err'] !== '' ? ': ' . ToolText::tail($r['err']) : '')];
        }
        return ['ok' => true, 'text' => trim($text), 'reason' => ''];
    }
}
