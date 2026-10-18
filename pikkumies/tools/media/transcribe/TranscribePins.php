<?php
/**
 * TranscribePins: the exact downloads `install-local` is allowed to fetch, each pinned by sha256
 * (ADR-0045). Every value here was obtained by downloading the file over HTTPS and hashing it this
 * session (2026-10-18); the ADR records the source of each hash. A download whose hash does not
 * match is deleted, never used (TranscribeInstall.php).
 *
 * $baseUrl* fields let a test point at a local `php -S` fixture instead of the real network; the
 * install verb never overrides them (real downloads only happen outside tests).
 */
class TranscribePins
{
    /** whisper.cpp v1.9.5, the GitHub-generated source tarball for the release tag. */
    const WHISPER_URL = 'https://github.com/ggml-org/whisper.cpp/archive/refs/tags/v1.9.5.tar.gz';
    const WHISPER_SHA256 = 'ff1a9053feb509ff9d7729703355541ae9690073a6b1c40eb692c962e0dc1720';
    const WHISPER_DIRNAME = 'whisper.cpp-1.9.5'; // the top-level directory the tarball extracts to

    /** Kitware's own CMake release, Linux x86_64 only; other architectures are refused with a clear message. */
    const CMAKE_URL = 'https://github.com/Kitware/CMake/releases/download/v4.4.4/cmake-4.4.4-linux-x86_64.tar.gz';
    const CMAKE_SHA256 = 'e5bb807f7728cb60cd8b27ebc97a2edb469b68655f21e844a600c3575b76f5bb';
    const CMAKE_DIRNAME = 'cmake-4.4.4-linux-x86_64';

    /** ggml models from the whisper.cpp model repository on Hugging Face, at a fixed repository commit (not `main`). */
    const MODELS = [
        'base'  => ['url' => 'https://huggingface.co/ggerganov/whisper.cpp/resolve/5359861c739e955e79d9a303bcbc70fb988958b1/ggml-base.bin',
            'sha256' => '60ed5bc3dd14eea856493d334349b405782ddcaf0028d4b5df4088345fba2efe', 'bytes' => 147951465],
        'small' => ['url' => 'https://huggingface.co/ggerganov/whisper.cpp/resolve/5359861c739e955e79d9a303bcbc70fb988958b1/ggml-small.bin',
            'sha256' => '1be3a9b2063867b937e64e2ec7483364a79917e157fa98c5d94b5c1fffea987b', 'bytes' => 487601967],
    ];

    /** Rough room needed for the whole install, in bytes: source + build output + the model, with margin. */
    public static function roomNeeded($model)
    {
        $modelBytes = isset(self::MODELS[$model]) ? self::MODELS[$model]['bytes'] : 0;
        return 600 * 1024 * 1024 + $modelBytes; // ~600 MiB for the source checkout and build (no cmake download counted; small anyway)
    }

    /** MemAvailable floor for a build, in bytes: base needs at least 1 GiB, small at least 2 GiB (USAGE.md). */
    public static function memNeeded($model)
    {
        return $model === 'small' ? 2 * 1024 * 1024 * 1024 : 1024 * 1024 * 1024;
    }
}
